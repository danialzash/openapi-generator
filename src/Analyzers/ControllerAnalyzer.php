<?php

namespace Verge\OpenAPIGenerator\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use ReflectionClass;
use ReflectionMethod;
use Verge\OpenAPIGenerator\Contracts\AnalyzerInterface;
use Verge\OpenAPIGenerator\Support\ValidationRuleMapper;

class ControllerAnalyzer implements AnalyzerInterface
{
    protected array $config;
    protected ValidationRuleMapper $ruleMapper;
    protected $parser;
    protected NodeFinder $nodeFinder;
    protected PrettyPrinter $printer;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->ruleMapper = new ValidationRuleMapper();
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
        $this->printer = new PrettyPrinter();
    }

    /**
     * Analyze controller method for inline validation and return statements.
     */
    public function analyze(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $controller = $data['controller'] ?? null;
        $method = $data['action'] ?? null;

        if (!$controller || !$method || !class_exists($controller)) {
            return [];
        }

        return $this->analyzeControllerMethod($controller, $method);
    }

    /**
     * Analyze a specific controller method.
     */
    public function analyzeControllerMethod(string $controller, string $method): array
    {
        $result = [
            'inline_validation' => null,
            'return_statements' => [],
            'response_types' => [],
            'docblock' => null,
        ];

        try {
            $reflection = new ReflectionClass($controller);
            
            if (!$reflection->hasMethod($method)) {
                return $result;
            }

            $methodReflection = $reflection->getMethod($method);
            $result['docblock'] = $methodReflection->getDocComment() ?: null;

            // Get method source code
            $source = $this->getMethodSource($methodReflection);
            
            if (!$source) {
                return $result;
            }

            // Parse the source code
            $ast = $this->parseSource($source);
            
            if (!$ast) {
                return $result;
            }

            // Extract inline validation
            $result['inline_validation'] = $this->extractInlineValidation($ast);

            // Extract return statements
            $result['return_statements'] = $this->extractReturnStatements($ast);

            // Analyze response types from return statements
            $result['response_types'] = $this->analyzeResponseTypes($result['return_statements']);

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get the source code of a method.
     */
    protected function getMethodSource(ReflectionMethod $method): ?string
    {
        $filename = $method->getFileName();
        
        if (!$filename || !file_exists($filename)) {
            return null;
        }

        $source = file_get_contents($filename);
        $lines = explode("\n", $source);
        
        $startLine = $method->getStartLine() - 1;
        $endLine = $method->getEndLine();
        
        // Include the full class for proper parsing context
        $fullSource = implode("\n", $lines);
        
        // Extract just the method
        $methodLines = array_slice($lines, $startLine, $endLine - $startLine);
        
        return implode("\n", $methodLines);
    }

    /**
     * Parse PHP source code into AST.
     */
    protected function parseSource(string $source): ?array
    {
        try {
            // Wrap in a class if needed for valid PHP
            $wrapped = "<?php\nclass __TempClass {\n" . $source . "\n}";
            return $this->parser->parse($wrapped);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract inline validation calls from AST.
     */
    protected function extractInlineValidation(array $ast): ?array
    {
        $validationCalls = [];

        // Find $request->validate() calls
        $methodCalls = $this->nodeFinder->findInstanceOf($ast, Node\Expr\MethodCall::class);

        foreach ($methodCalls as $call) {
            if (!$call->name instanceof Node\Identifier) {
                continue;
            }

            $methodName = $call->name->name;

            // Check for validate() or validated() calls
            if (in_array($methodName, ['validate', 'validated'])) {
                $rules = $this->extractRulesFromValidateCall($call);
                if ($rules) {
                    $validationCalls[] = [
                        'method' => $methodName,
                        'rules' => $rules,
                        'schema' => $this->ruleMapper->mapRulesToSchema($rules),
                    ];
                }
            }
        }

        // Find Validator::make() calls
        $staticCalls = $this->nodeFinder->findInstanceOf($ast, Node\Expr\StaticCall::class);

        foreach ($staticCalls as $call) {
            if (!$call->class instanceof Node\Name) {
                continue;
            }

            $className = $call->class->toString();
            
            if (!$call->name instanceof Node\Identifier) {
                continue;
            }

            $methodName = $call->name->name;

            if (($className === 'Validator' || str_ends_with($className, '\Validator')) && $methodName === 'make') {
                $rules = $this->extractRulesFromValidatorMake($call);
                if ($rules) {
                    $validationCalls[] = [
                        'method' => 'Validator::make',
                        'rules' => $rules,
                        'schema' => $this->ruleMapper->mapRulesToSchema($rules),
                    ];
                }
            }
        }

        return !empty($validationCalls) ? $validationCalls : null;
    }

    /**
     * Extract rules from $request->validate() call.
     */
    protected function extractRulesFromValidateCall(Node\Expr\MethodCall $call): ?array
    {
        if (empty($call->args)) {
            return null;
        }

        $rulesArg = $call->args[0]->value ?? null;
        
        return $this->extractRulesFromArrayNode($rulesArg);
    }

    /**
     * Extract rules from Validator::make() call.
     */
    protected function extractRulesFromValidatorMake(Node\Expr\StaticCall $call): ?array
    {
        // Second argument is the rules array
        if (count($call->args) < 2) {
            return null;
        }

        $rulesArg = $call->args[1]->value ?? null;
        
        return $this->extractRulesFromArrayNode($rulesArg);
    }

    /**
     * Extract rules from an array node.
     */
    protected function extractRulesFromArrayNode(?Node $node): ?array
    {
        if (!$node instanceof Node\Expr\Array_) {
            return null;
        }

        $rules = [];

        foreach ($node->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem) {
                continue;
            }

            // Get the key
            $key = $this->extractStringValue($item->key);
            if (!$key) {
                continue;
            }

            // Get the value (rules)
            $value = $this->extractRuleValue($item->value);
            if ($value !== null) {
                $rules[$key] = $value;
            }
        }

        return !empty($rules) ? $rules : null;
    }

    /**
     * Extract string value from node.
     */
    protected function extractStringValue(?Node $node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        return null;
    }

    /**
     * Extract rule value from node.
     */
    protected function extractRuleValue(?Node $node): string|array|null
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Expr\Array_) {
            $values = [];
            foreach ($node->items as $item) {
                if ($item instanceof Node\Expr\ArrayItem && $item->value instanceof Node\Scalar\String_) {
                    $values[] = $item->value->value;
                }
            }
            return !empty($values) ? $values : null;
        }

        return null;
    }

    /**
     * Extract return statements from AST.
     */
    protected function extractReturnStatements(array $ast): array
    {
        $returns = [];
        $returnNodes = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Return_::class);

        foreach ($returnNodes as $return) {
            $returnInfo = $this->analyzeReturnExpression($return->expr);
            if ($returnInfo) {
                $returns[] = $returnInfo;
            }
        }

        return $returns;
    }

    /**
     * Analyze a return expression.
     */
    protected function analyzeReturnExpression(?Node $expr): ?array
    {
        if (!$expr) {
            return null;
        }

        // Response::show(), Response::created(), etc.
        if ($expr instanceof Node\Expr\StaticCall) {
            return $this->analyzeStaticCallReturn($expr);
        }

        // response()->json(), etc.
        if ($expr instanceof Node\Expr\MethodCall) {
            return $this->analyzeMethodCallReturn($expr);
        }

        // new JsonResource(), new SomeResource()
        if ($expr instanceof Node\Expr\New_) {
            return $this->analyzeNewReturn($expr);
        }

        // Variable or other expression
        return [
            'type' => 'unknown',
            'expression' => $this->printer->prettyPrintExpr($expr),
        ];
    }

    /**
     * Analyze static call return (Response::show, etc.)
     */
    protected function analyzeStaticCallReturn(Node\Expr\StaticCall $call): array
    {
        $className = $call->class instanceof Node\Name ? $call->class->toString() : 'unknown';
        $methodName = $call->name instanceof Node\Identifier ? $call->name->name : 'unknown';

        $result = [
            'type' => 'static_call',
            'class' => $className,
            'method' => $methodName,
            'arguments' => [],
        ];

        // Check for Response facade macros
        if ($className === 'Response' || str_ends_with($className, '\Response')) {
            $macros = $this->config['response_macros'] ?? [];
            if (isset($macros[$methodName])) {
                $result['status_code'] = $macros[$methodName]['status'] ?? 200;
                $result['description'] = $macros[$methodName]['description'] ?? null;
            }
        }

        // Try to extract resource class from arguments
        foreach ($call->args as $arg) {
            $argInfo = $this->analyzeArgument($arg->value);
            if ($argInfo) {
                $result['arguments'][] = $argInfo;
            }
        }

        return $result;
    }

    /**
     * Analyze method call return (response()->json(), etc.)
     */
    protected function analyzeMethodCallReturn(Node\Expr\MethodCall $call): array
    {
        $methodName = $call->name instanceof Node\Identifier ? $call->name->name : 'unknown';

        $result = [
            'type' => 'method_call',
            'method' => $methodName,
        ];

        // Check for chained calls
        if ($call->var instanceof Node\Expr\MethodCall || $call->var instanceof Node\Expr\StaticCall) {
            $result['chain'] = $this->analyzeReturnExpression($call->var);
        }

        // json() method indicates JSON response
        if ($methodName === 'json') {
            $result['content_type'] = 'application/json';
        }

        return $result;
    }

    /**
     * Analyze new expression return (new SomeResource())
     */
    protected function analyzeNewReturn(Node\Expr\New_ $new): array
    {
        $className = $new->class instanceof Node\Name ? $new->class->toString() : 'unknown';

        return [
            'type' => 'new_instance',
            'class' => $className,
            'is_resource' => str_contains(strtolower($className), 'resource'),
        ];
    }

    /**
     * Analyze an argument expression.
     */
    protected function analyzeArgument(Node $expr): ?array
    {
        if ($expr instanceof Node\Expr\New_) {
            $className = $expr->class instanceof Node\Name ? $expr->class->toString() : 'unknown';
            return [
                'type' => 'new_instance',
                'class' => $className,
            ];
        }

        if ($expr instanceof Node\Expr\StaticCall) {
            $className = $expr->class instanceof Node\Name ? $expr->class->toString() : 'unknown';
            $methodName = $expr->name instanceof Node\Identifier ? $expr->name->name : 'unknown';
            return [
                'type' => 'static_call',
                'class' => $className,
                'method' => $methodName,
            ];
        }

        if ($expr instanceof Node\Expr\MethodCall) {
            return [
                'type' => 'method_call',
                'method' => $expr->name instanceof Node\Identifier ? $expr->name->name : 'unknown',
            ];
        }

        return null;
    }

    /**
     * Analyze response types from return statements.
     */
    protected function analyzeResponseTypes(array $returnStatements): array
    {
        $types = [];

        foreach ($returnStatements as $return) {
            if (!is_array($return)) {
                continue;
            }

            // Check for resource classes
            $resourceClass = $this->findResourceClass($return);
            if ($resourceClass) {
                $types[] = [
                    'type' => 'resource',
                    'class' => $resourceClass,
                ];
            }

            // Check for status code
            if (isset($return['status_code'])) {
                $types[] = [
                    'type' => 'status',
                    'code' => $return['status_code'],
                    'description' => $return['description'] ?? null,
                ];
            }
        }

        return $types;
    }

    /**
     * Find resource class from return info.
     */
    protected function findResourceClass(array $returnInfo): ?string
    {
        // Direct new instance
        if (($returnInfo['type'] ?? null) === 'new_instance' && ($returnInfo['is_resource'] ?? false)) {
            return $returnInfo['class'] ?? null;
        }

        // In arguments
        foreach ($returnInfo['arguments'] ?? [] as $arg) {
            if (($arg['type'] ?? null) === 'new_instance') {
                $class = $arg['class'] ?? '';
                if (str_contains(strtolower($class), 'resource')) {
                    return $class;
                }
            }

            // Resource::collection() static call
            if (($arg['type'] ?? null) === 'static_call' && ($arg['method'] ?? null) === 'collection') {
                return $arg['class'] ?? null;
            }
        }

        return null;
    }
}
