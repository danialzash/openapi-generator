<?php

namespace Verge\OpenAPIGenerator\Analyzers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Verge\OpenAPIGenerator\Contracts\AnalyzerInterface;
use Verge\OpenAPIGenerator\Support\ValidationRuleMapper;

class RequestAnalyzer implements AnalyzerInterface
{
    protected array $config;
    protected ValidationRuleMapper $ruleMapper;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->ruleMapper = new ValidationRuleMapper();
    }

    /**
     * Analyze request class and return schema.
     */
    public function analyze(mixed $data): array
    {
        if (is_string($data)) {
            return $this->analyzeRequestClass($data);
        }

        if (is_array($data) && isset($data['controller_info'])) {
            return $this->analyzeFromRouteData($data);
        }

        return [];
    }

    /**
     * Analyze from route data.
     */
    public function analyzeFromRouteData(array $routeData): array
    {
        $result = [
            'request_class' => null,
            'has_request_body' => false,
            'schema' => null,
            'query_parameters' => [],
        ];

        $controllerInfo = $routeData['controller_info'] ?? [];
        $methodParams = $controllerInfo['method_parameters'] ?? [];

        // Find FormRequest parameter
        foreach ($methodParams as $param) {
            if (!$param['type'] || $param['is_builtin']) {
                continue;
            }

            $className = $param['type'];
            
            if (!class_exists($className)) {
                continue;
            }

            if (is_subclass_of($className, FormRequest::class)) {
                $result['request_class'] = $className;
                $analysis = $this->analyzeRequestClass($className);
                $result['has_request_body'] = true;
                $result['schema'] = $analysis['schema'] ?? null;
                $result['query_parameters'] = $analysis['query_parameters'] ?? [];
                break;
            }

            // Check if it's a regular Request (for query params)
            if ($className === Request::class || is_subclass_of($className, Request::class)) {
                // Can't auto-detect validation from base Request
                continue;
            }
        }

        return $result;
    }

    /**
     * Analyze a FormRequest class.
     */
    public function analyzeRequestClass(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        if (!is_subclass_of($className, FormRequest::class)) {
            return [];
        }

        $reflection = new ReflectionClass($className);
        
        $result = [
            'class' => $className,
            'rules' => [],
            'schema' => null,
            'query_parameters' => [],
            'docblock' => $reflection->getDocComment() ?: null,
        ];

        // Extract rules
        $rules = $this->extractRules($className);
        $result['rules'] = $rules;

        if (!empty($rules)) {
            $result['schema'] = $this->ruleMapper->mapRulesToSchema($rules);
        }

        // Try to extract query parameters from docblock or method
        $result['query_parameters'] = $this->extractQueryParameters($reflection);

        return $result;
    }

    /**
     * Extract validation rules from a FormRequest.
     */
    protected function extractRules(string $className): array
    {
        try {
            // Try to instantiate and call rules()
            // This is safe because we're just reading the rules
            $reflection = new ReflectionClass($className);
            
            if (!$reflection->hasMethod('rules')) {
                return [];
            }

            $rulesMethod = $reflection->getMethod('rules');
            
            // Check if the rules method has dependencies
            $params = $rulesMethod->getParameters();
            
            if (empty($params)) {
                // Simple case - no dependencies, we can try to instantiate
                return $this->extractRulesViaReflection($className, $reflection);
            }

            // Complex case - has dependencies, try to parse the source
            return $this->extractRulesFromSource($reflection);
        } catch (\Throwable $e) {
            // Fallback to source parsing
            try {
                return $this->extractRulesFromSource(new ReflectionClass($className));
            } catch (\Throwable $e) {
                return [];
            }
        }
    }

    /**
     * Extract rules via reflection (instantiation).
     */
    protected function extractRulesViaReflection(string $className, ReflectionClass $reflection): array
    {
        try {
            // Create a minimal request instance
            $request = $reflection->newInstanceWithoutConstructor();
            
            // Initialize request
            if (method_exists($request, 'setContainer')) {
                $request->setContainer(app());
            }
            
            if (method_exists($request, 'setRouteResolver')) {
                $request->setRouteResolver(function () {
                    return null;
                });
            }

            // Call rules method
            $rulesMethod = $reflection->getMethod('rules');
            $rulesMethod->setAccessible(true);
            
            return $rulesMethod->invoke($request) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Extract rules by parsing source code.
     * This is a fallback when instantiation fails.
     */
    protected function extractRulesFromSource(ReflectionClass $reflection): array
    {
        if (!$reflection->hasMethod('rules')) {
            return [];
        }

        $method = $reflection->getMethod('rules');
        $filename = $reflection->getFileName();
        
        if (!$filename || !file_exists($filename)) {
            return [];
        }

        $source = file_get_contents($filename);
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        
        $lines = explode("\n", $source);
        $methodSource = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        
        // Simple regex-based extraction of rules array
        return $this->parseRulesFromSource($methodSource);
    }

    /**
     * Parse rules from method source code.
     */
    protected function parseRulesFromSource(string $source): array
    {
        $rules = [];

        // Match array key-value pairs like 'field' => 'rules' or 'field' => ['rules']
        preg_match_all(
            '/[\'"]([^\'"\[\]]+)[\'"]\s*=>\s*(?:[\'"]((?:[^\'"\\\\]|\\\\.)*)[\'"|\[([^\]]+)\])/m',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $field = $match[1];
            $fieldRules = $match[2] ?? $match[3] ?? '';
            
            // Clean up the rules
            if (!empty($fieldRules)) {
                $rules[$field] = trim($fieldRules);
            }
        }

        return $rules;
    }

    /**
     * Extract query parameters from docblock or method.
     */
    protected function extractQueryParameters(ReflectionClass $reflection): array
    {
        $params = [];
        $docblock = $reflection->getDocComment();

        if (!$docblock) {
            return $params;
        }

        // Parse @queryParam annotations
        preg_match_all(
            '/@queryParam\s+(\w+)\s+(\w+)?(?:\s+(.+))?/m',
            $docblock,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $name = $match[1];
            $type = $match[2] ?? 'string';
            $description = isset($match[3]) ? trim($match[3]) : null;

            $params[] = [
                'name' => $name,
                'in' => 'query',
                'required' => false,
                'schema' => $this->mapTypeToSchema($type),
                'description' => $description,
            ];
        }

        return $params;
    }

    /**
     * Map a simple type name to OpenAPI schema.
     */
    protected function mapTypeToSchema(string $type): array
    {
        return match (strtolower($type)) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double', 'number' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            default => ['type' => 'string'],
        };
    }

    /**
     * Get the request body content type for a route.
     */
    public function getContentType(array $routeData): string
    {
        // Check if route has file upload
        $requestClass = $routeData['request_class'] ?? null;
        
        if ($requestClass && class_exists($requestClass)) {
            $rules = $this->extractRules($requestClass);
            
            foreach ($rules as $field => $fieldRules) {
                $normalizedRules = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);
                
                foreach ($normalizedRules as $rule) {
                    $ruleName = strtolower(explode(':', $rule)[0]);
                    if (in_array($ruleName, ['file', 'image', 'mimes', 'mimetypes'])) {
                        return 'multipart/form-data';
                    }
                }
            }
        }

        return 'application/json';
    }
}
