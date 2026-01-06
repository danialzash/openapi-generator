<?php

namespace Verge\OpenAPIGenerator\Analyzers;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Verge\OpenAPIGenerator\Contracts\AnalyzerInterface;

class RouteAnalyzer implements AnalyzerInterface
{
    protected array $config;
    protected Router $router;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->router = app('router');
    }

    /**
     * Analyze all routes and return structured data.
     */
    public function analyze(mixed $data = null): array
    {
        $routes = $this->getFilteredRoutes();
        $analyzed = [];

        foreach ($routes as $route) {
            $routeData = $this->analyzeRoute($route);
            if ($routeData) {
                $analyzed[] = $routeData;
            }
        }

        return $analyzed;
    }

    /**
     * Get all routes filtered by configuration.
     */
    public function getFilteredRoutes(): Collection
    {
        $routes = collect($this->router->getRoutes()->getRoutes());

        return $routes->filter(function (Route $route) {
            return $this->shouldIncludeRoute($route);
        });
    }

    /**
     * Check if a route should be included based on filters.
     */
    protected function shouldIncludeRoute(Route $route): bool
    {
        $uri = $route->uri();
        $middleware = $route->middleware();
        $name = $route->getName();

        $filters = $this->config['route_filters'] ?? [];

        // Check exclude prefixes
        $excludePrefixes = $filters['exclude_prefixes'] ?? [];
        foreach ($excludePrefixes as $prefix) {
            if (Str::startsWith($uri, $prefix)) {
                return false;
            }
        }

        // Check include prefixes (if set, only include matching routes)
        $includePrefixes = $filters['include_prefixes'] ?? [];
        if (!empty($includePrefixes)) {
            $matches = false;
            foreach ($includePrefixes as $prefix) {
                if (Str::startsWith($uri, $prefix)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                return false;
            }
        }

        // Check exclude middleware
        $excludeMiddleware = $filters['exclude_middleware'] ?? [];
        foreach ($excludeMiddleware as $excluded) {
            if (in_array($excluded, $middleware)) {
                return false;
            }
        }

        // Check include middleware (if set, only include routes with matching middleware)
        $includeMiddleware = $filters['include_middleware'] ?? [];
        if (!empty($includeMiddleware)) {
            $hasRequired = false;
            foreach ($includeMiddleware as $required) {
                if (in_array($required, $middleware)) {
                    $hasRequired = true;
                    break;
                }
            }
            if (!$hasRequired) {
                return false;
            }
        }

        // Check exclude names (regex patterns)
        $excludeNames = $filters['exclude_names'] ?? [];
        if ($name) {
            foreach ($excludeNames as $pattern) {
                if (preg_match($pattern, $name)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Analyze a single route.
     */
    public function analyzeRoute(Route $route): ?array
    {
        $methods = $this->getRouteMethods($route);
        $uri = $route->uri();
        $action = $route->getAction();

        // Skip routes without a controller
        if (!isset($action['controller'])) {
            return null;
        }

        [$controller, $method] = $this->parseControllerAction($action['controller']);

        if (!$controller || !$method) {
            return null;
        }

        $analyzed = [];

        foreach ($methods as $httpMethod) {
            $analyzed[] = [
                'method' => strtoupper($httpMethod),
                'uri' => '/' . ltrim($uri, '/'),
                'name' => $route->getName(),
                'controller' => $controller,
                'action' => $method,
                'middleware' => $route->middleware(),
                'parameters' => $this->extractParameters($route),
                'where_constraints' => $route->wheres,
                'domain' => $route->getDomain(),
                'prefix' => $action['prefix'] ?? null,
                'controller_info' => $this->getControllerInfo($controller, $method),
            ];
        }

        return count($analyzed) === 1 ? $analyzed[0] : $analyzed;
    }

    /**
     * Get HTTP methods for a route (excluding HEAD).
     */
    protected function getRouteMethods(Route $route): array
    {
        return array_filter($route->methods(), function ($method) {
            return !in_array(strtoupper($method), ['HEAD']);
        });
    }

    /**
     * Parse controller@action string.
     */
    protected function parseControllerAction(string $action): array
    {
        if (Str::contains($action, '@')) {
            return explode('@', $action);
        }

        // Handle invokable controllers
        if (class_exists($action)) {
            return [$action, '__invoke'];
        }

        return [null, null];
    }

    /**
     * Extract route parameters.
     */
    public function extractParameters(Route $route): array
    {
        $uri = $route->uri();
        $parameters = [];

        // Match {param} and {param?} patterns
        preg_match_all('/\{(\w+)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = $match[1];
            $required = !isset($match[2]);

            $parameter = [
                'name' => $name,
                'in' => 'path',
                'required' => $required,
                'schema' => $this->inferParameterSchema($name, $route),
            ];

            // Add description from route model binding if available
            $binding = $this->getRouteModelBinding($route, $name);
            if ($binding) {
                $parameter['description'] = "The {$name} identifier";
                $parameter['model'] = $binding;
            }

            // Add pattern constraint if set
            if (isset($route->wheres[$name])) {
                $parameter['pattern'] = $route->wheres[$name];
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * Infer the schema type for a parameter.
     */
    protected function inferParameterSchema(string $name, Route $route): array
    {
        // Check for UUID pattern
        if (isset($route->wheres[$name])) {
            $pattern = $route->wheres[$name];
            if (Str::contains($pattern, '[a-f0-9]') || Str::contains($pattern, 'uuid')) {
                return ['type' => 'string', 'format' => 'uuid'];
            }
            if ($pattern === '\d+' || $pattern === '[0-9]+') {
                return ['type' => 'integer'];
            }
        }

        // Infer from common naming conventions
        if (Str::endsWith($name, '_id') || $name === 'id') {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        return ['type' => 'string'];
    }

    /**
     * Get route model binding information.
     */
    protected function getRouteModelBinding(Route $route, string $param): ?string
    {
        $bindings = $route->bindingFields();
        
        if (isset($bindings[$param])) {
            return $bindings[$param];
        }

        // Try to infer from controller method signature
        $action = $route->getAction();
        if (!isset($action['controller'])) {
            return null;
        }

        [$controller, $method] = $this->parseControllerAction($action['controller']);
        
        if (!$controller || !class_exists($controller)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
            $parameters = $reflection->getParameters();

            foreach ($parameters as $parameter) {
                if ($parameter->getName() === $param) {
                    $type = $parameter->getType();
                    if ($type && !$type->isBuiltin()) {
                        return $type->getName();
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore reflection errors
        }

        return null;
    }

    /**
     * Get information about the controller and method.
     */
    protected function getControllerInfo(string $controller, string $method): array
    {
        $info = [
            'class' => $controller,
            'method' => $method,
            'exists' => false,
        ];

        if (!class_exists($controller)) {
            return $info;
        }

        $info['exists'] = true;

        try {
            $classReflection = new ReflectionClass($controller);
            $info['class_docblock'] = $classReflection->getDocComment() ?: null;

            if ($classReflection->hasMethod($method)) {
                $methodReflection = $classReflection->getMethod($method);
                $info['method_docblock'] = $methodReflection->getDocComment() ?: null;
                $info['method_parameters'] = $this->getMethodParameters($methodReflection);
                $info['return_type'] = $this->getReturnType($methodReflection);
            }
        } catch (\Throwable $e) {
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Get method parameters with type information.
     */
    protected function getMethodParameters(ReflectionMethod $method): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            
            $parameters[] = [
                'name' => $param->getName(),
                'type' => $type ? $type->getName() : null,
                'is_builtin' => $type ? $type->isBuiltin() : null,
                'allows_null' => $type ? $type->allowsNull() : true,
                'has_default' => $param->isDefaultValueAvailable(),
                'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }

        return $parameters;
    }

    /**
     * Get the return type of a method.
     */
    protected function getReturnType(ReflectionMethod $method): ?array
    {
        $returnType = $method->getReturnType();

        if (!$returnType) {
            return null;
        }

        return [
            'type' => $returnType->getName(),
            'is_builtin' => $returnType->isBuiltin(),
            'allows_null' => $returnType->allowsNull(),
        ];
    }

    /**
     * Generate tags from controller name.
     */
    public function generateTagFromController(string $controller): string
    {
        $mappings = $this->config['tags']['mappings'] ?? [];
        $className = class_basename($controller);

        if (isset($mappings[$className])) {
            return $mappings[$className];
        }

        // Remove "Controller" suffix and convert to readable format
        $tag = Str::beforeLast($className, 'Controller');
        $tag = Str::headline($tag);

        return $tag;
    }

    /**
     * Get unique tags from all analyzed routes.
     */
    public function getUniqueTags(array $analyzedRoutes): array
    {
        $tags = [];

        foreach ($analyzedRoutes as $route) {
            if (is_array($route) && isset($route['controller'])) {
                $tag = $this->generateTagFromController($route['controller']);
                if (!isset($tags[$tag])) {
                    $tags[$tag] = [
                        'name' => $tag,
                        'description' => "Operations related to {$tag}",
                    ];
                }
            }
        }

        return array_values($tags);
    }
}
