<?php

namespace Verge\OpenAPIGenerator\Analyzers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use Verge\OpenAPIGenerator\Contracts\AnalyzerInterface;
use Verge\OpenAPIGenerator\Support\TypeResolver;

class ResponseAnalyzer implements AnalyzerInterface
{
    protected array $config;
    protected TypeResolver $typeResolver;
    protected $parser;
    protected NodeFinder $nodeFinder;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->typeResolver = new TypeResolver();
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Analyze response and return schema.
     */
    public function analyze(mixed $data): array
    {
        if (is_string($data) && class_exists($data)) {
            return $this->analyzeResourceClass($data);
        }

        if (is_array($data)) {
            return $this->analyzeFromControllerData($data);
        }

        return [];
    }

    /**
     * Analyze from controller analyzer data.
     */
    public function analyzeFromControllerData(array $controllerData): array
    {
        $result = [
            'responses' => [],
            'schemas' => [],
        ];

        $responseTypes = $controllerData['response_types'] ?? [];
        
        foreach ($responseTypes as $responseType) {
            if (($responseType['type'] ?? null) === 'resource' && isset($responseType['class'])) {
                $resourceClass = $responseType['class'];
                
                // Try to resolve full class name
                $resolvedClass = $this->resolveClassName($resourceClass, $controllerData);
                
                if ($resolvedClass && class_exists($resolvedClass)) {
                    $resourceAnalysis = $this->analyzeResourceClass($resolvedClass);
                    $result['schemas'][$resourceAnalysis['name']] = $resourceAnalysis;
                }
            }

            if (($responseType['type'] ?? null) === 'status') {
                $result['responses'][$responseType['code']] = [
                    'description' => $responseType['description'] ?? 'Response',
                ];
            }
        }

        return $result;
    }

    /**
     * Analyze a JsonResource class.
     */
    public function analyzeResourceClass(string $className): array
    {
        if (!class_exists($className)) {
            return [
                'name' => class_basename($className),
                'class' => $className,
                'schema' => ['type' => 'object'],
            ];
        }

        $reflection = new ReflectionClass($className);
        $isCollection = is_subclass_of($className, ResourceCollection::class);

        $result = [
            'name' => class_basename($className),
            'class' => $className,
            'is_collection' => $isCollection,
            'schema' => null,
        ];

        // Get the toArray method
        if ($reflection->hasMethod('toArray')) {
            $toArrayMethod = $reflection->getMethod('toArray');
            $schema = $this->analyzeToArrayMethod($toArrayMethod, $reflection);
            
            if ($isCollection) {
                $result['schema'] = [
                    'type' => 'array',
                    'items' => $schema,
                ];
            } else {
                $result['schema'] = $schema;
            }
        } else {
            $result['schema'] = ['type' => 'object'];
        }

        return $result;
    }

    /**
     * Analyze the toArray method of a resource.
     */
    protected function analyzeToArrayMethod(ReflectionMethod $method, ReflectionClass $class): array
    {
        $filename = $class->getFileName();
        
        if (!$filename || !file_exists($filename)) {
            return ['type' => 'object'];
        }

        $source = file_get_contents($filename);
        
        // Get just the toArray method source
        $lines = explode("\n", $source);
        $startLine = $method->getStartLine() - 1;
        $endLine = $method->getEndLine();
        $methodSource = implode("\n", array_slice($lines, $startLine, $endLine - $startLine));

        try {
            $ast = $this->parser->parse("<?php\nclass __Temp {\n" . $methodSource . "\n}");
            
            if (!$ast) {
                return ['type' => 'object'];
            }

            return $this->extractSchemaFromAst($ast, $class);
        } catch (\Throwable $e) {
            return ['type' => 'object'];
        }
    }

    /**
     * Extract schema from toArray AST.
     */
    protected function extractSchemaFromAst(array $ast, ReflectionClass $resourceClass): array
    {
        // Find return statements with arrays
        $returns = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Return_::class);

        foreach ($returns as $return) {
            if ($return->expr instanceof Node\Expr\Array_) {
                return $this->extractSchemaFromArray($return->expr, $resourceClass);
            }
        }

        return ['type' => 'object'];
    }

    /**
     * Extract schema from array expression.
     */
    protected function extractSchemaFromArray(Node\Expr\Array_ $array, ReflectionClass $resourceClass): array
    {
        $properties = [];
        $required = [];

        foreach ($array->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem) {
                continue;
            }

            // Skip items without string keys
            if (!$item->key instanceof Node\Scalar\String_) {
                // Handle mergeWhen, etc.
                if ($item->value instanceof Node\Expr\MethodCall) {
                    $mergedProps = $this->handleMergeMethod($item->value, $resourceClass);
                    $properties = array_merge($properties, $mergedProps);
                }
                continue;
            }

            $key = $item->key->value;
            $schema = $this->inferSchemaFromValue($item->value, $resourceClass);
            
            if ($schema) {
                $properties[$key] = $schema;
            }
        }

        $result = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $result['required'] = $required;
        }

        return $result;
    }

    /**
     * Handle merge methods like mergeWhen, when, etc.
     */
    protected function handleMergeMethod(Node\Expr\MethodCall $call, ReflectionClass $resourceClass): array
    {
        $properties = [];

        if (!$call->name instanceof Node\Identifier) {
            return $properties;
        }

        $methodName = $call->name->name;

        // mergeWhen has the array as second argument
        if ($methodName === 'mergeWhen' && count($call->args) >= 2) {
            $arrayArg = $call->args[1]->value ?? null;
            if ($arrayArg instanceof Node\Expr\Array_) {
                $schema = $this->extractSchemaFromArray($arrayArg, $resourceClass);
                $properties = $schema['properties'] ?? [];
            }
        }

        // merge has the array as first argument
        if ($methodName === 'merge' && count($call->args) >= 1) {
            $arrayArg = $call->args[0]->value ?? null;
            if ($arrayArg instanceof Node\Expr\Array_) {
                $schema = $this->extractSchemaFromArray($arrayArg, $resourceClass);
                $properties = $schema['properties'] ?? [];
            }
        }

        return $properties;
    }

    /**
     * Infer schema from a value expression.
     */
    protected function inferSchemaFromValue(Node $value, ReflectionClass $resourceClass): ?array
    {
        // String literal
        if ($value instanceof Node\Scalar\String_) {
            return ['type' => 'string', 'example' => $value->value];
        }

        // Integer literal
        if ($value instanceof Node\Scalar\Int_) {
            return ['type' => 'integer', 'example' => $value->value];
        }

        // Float literal
        if ($value instanceof Node\Scalar\Float_) {
            return ['type' => 'number', 'example' => $value->value];
        }

        // Boolean
        if ($value instanceof Node\Expr\ConstFetch) {
            $name = strtolower($value->name->toString());
            if (in_array($name, ['true', 'false'])) {
                return ['type' => 'boolean'];
            }
            if ($name === 'null') {
                return ['type' => 'string', 'nullable' => true];
            }
        }

        // Property access ($this->property)
        if ($value instanceof Node\Expr\PropertyFetch) {
            return $this->inferSchemaFromPropertyFetch($value, $resourceClass);
        }

        // Method call ($this->method() or $this->getSetting()->value)
        if ($value instanceof Node\Expr\MethodCall) {
            return $this->inferSchemaFromMethodCall($value, $resourceClass);
        }

        // Ternary expression
        if ($value instanceof Node\Expr\Ternary) {
            // Use the "if true" value for type inference
            return $this->inferSchemaFromValue($value->if ?? $value->else, $resourceClass);
        }

        // Null coalesce ($this->property ?? default)
        if ($value instanceof Node\Expr\BinaryOp\Coalesce) {
            $schema = $this->inferSchemaFromValue($value->left, $resourceClass);
            if ($schema) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        // Array
        if ($value instanceof Node\Expr\Array_) {
            return [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ];
        }

        // New instance (new SomeResource($this->relation))
        if ($value instanceof Node\Expr\New_) {
            return $this->inferSchemaFromNewInstance($value);
        }

        // Static call (SomeResource::collection())
        if ($value instanceof Node\Expr\StaticCall) {
            return $this->inferSchemaFromStaticCall($value);
        }

        // Default to string
        return ['type' => 'string'];
    }

    /**
     * Infer schema from property fetch.
     */
    protected function inferSchemaFromPropertyFetch(Node\Expr\PropertyFetch $fetch, ReflectionClass $resourceClass): array
    {
        $propertyName = $fetch->name instanceof Node\Identifier ? $fetch->name->name : null;
        
        if (!$propertyName) {
            return ['type' => 'string'];
        }

        // Common property naming conventions
        return match (true) {
            str_ends_with($propertyName, '_id') || $propertyName === 'id' => ['type' => 'string', 'format' => 'uuid'],
            str_ends_with($propertyName, '_at') => ['type' => 'string', 'format' => 'date-time'],
            str_ends_with($propertyName, '_date') => ['type' => 'string', 'format' => 'date'],
            str_starts_with($propertyName, 'is_') || str_starts_with($propertyName, 'has_') => ['type' => 'boolean'],
            in_array($propertyName, ['email']) => ['type' => 'string', 'format' => 'email'],
            in_array($propertyName, ['url', 'website']) => ['type' => 'string', 'format' => 'uri'],
            in_array($propertyName, ['ip', 'ip_address']) => ['type' => 'string', 'format' => 'ipv4'],
            in_array($propertyName, ['port']) => ['type' => 'integer'],
            in_array($propertyName, ['count', 'total', 'quantity', 'amount']) => ['type' => 'integer'],
            in_array($propertyName, ['price', 'cost', 'rate']) => ['type' => 'number'],
            default => ['type' => 'string'],
        };
    }

    /**
     * Infer schema from method call.
     */
    protected function inferSchemaFromMethodCall(Node\Expr\MethodCall $call, ReflectionClass $resourceClass): array
    {
        $methodName = $call->name instanceof Node\Identifier ? $call->name->name : null;

        if (!$methodName) {
            return ['type' => 'string'];
        }

        // Handle common patterns
        return match (true) {
            str_starts_with($methodName, 'get') => ['type' => 'string'],
            str_starts_with($methodName, 'is') || str_starts_with($methodName, 'has') => ['type' => 'boolean'],
            $methodName === 'toArray' => ['type' => 'array', 'items' => ['type' => 'string']],
            $methodName === 'toIso8601String' || str_contains($methodName, 'Date') => ['type' => 'string', 'format' => 'date-time'],
            $methodName === 'count' => ['type' => 'integer'],
            default => ['type' => 'string'],
        };
    }

    /**
     * Infer schema from new instance.
     */
    protected function inferSchemaFromNewInstance(Node\Expr\New_ $new): array
    {
        $className = $new->class instanceof Node\Name ? $new->class->toString() : null;

        if (!$className) {
            return ['type' => 'object'];
        }

        // If it's a resource, reference it
        if (str_contains(strtolower($className), 'resource')) {
            return ['$ref' => '#/components/schemas/' . class_basename($className)];
        }

        return ['type' => 'object'];
    }

    /**
     * Infer schema from static call.
     */
    protected function inferSchemaFromStaticCall(Node\Expr\StaticCall $call): array
    {
        $className = $call->class instanceof Node\Name ? $call->class->toString() : null;
        $methodName = $call->name instanceof Node\Identifier ? $call->name->name : null;

        if ($methodName === 'collection') {
            return [
                'type' => 'array',
                'items' => ['$ref' => '#/components/schemas/' . class_basename($className ?? 'Resource')],
            ];
        }

        if ($methodName === 'make') {
            return ['$ref' => '#/components/schemas/' . class_basename($className ?? 'Resource')];
        }

        return ['type' => 'object'];
    }

    /**
     * Resolve class name from imports.
     */
    protected function resolveClassName(string $shortName, array $context): ?string
    {
        // If already fully qualified
        if (class_exists($shortName)) {
            return $shortName;
        }

        // Try common namespaces
        $namespaces = [
            'App\\Http\\Resources\\',
            'App\\Http\\Resources\\Domain\\',
        ];

        foreach ($namespaces as $namespace) {
            $fullName = $namespace . $shortName;
            if (class_exists($fullName)) {
                return $fullName;
            }
        }

        return null;
    }
}
