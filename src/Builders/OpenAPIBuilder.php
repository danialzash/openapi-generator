<?php

namespace Verge\OpenAPIGenerator\Builders;

use Symfony\Component\Yaml\Yaml;
use Verge\OpenAPIGenerator\Analyzers\ControllerAnalyzer;
use Verge\OpenAPIGenerator\Analyzers\MiddlewareAnalyzer;
use Verge\OpenAPIGenerator\Analyzers\RequestAnalyzer;
use Verge\OpenAPIGenerator\Analyzers\ResponseAnalyzer;
use Verge\OpenAPIGenerator\Analyzers\RouteAnalyzer;
use Verge\OpenAPIGenerator\Contracts\BuilderInterface;

class OpenAPIBuilder implements BuilderInterface
{
    protected array $config;
    protected RouteAnalyzer $routeAnalyzer;
    protected RequestAnalyzer $requestAnalyzer;
    protected ControllerAnalyzer $controllerAnalyzer;
    protected ResponseAnalyzer $responseAnalyzer;
    protected MiddlewareAnalyzer $middlewareAnalyzer;
    protected PathBuilder $pathBuilder;
    protected SchemaBuilder $schemaBuilder;

    protected array $spec = [];
    protected array $analyzedRoutes = [];

    public function __construct(
        array $config,
        RouteAnalyzer $routeAnalyzer,
        RequestAnalyzer $requestAnalyzer,
        ControllerAnalyzer $controllerAnalyzer,
        ResponseAnalyzer $responseAnalyzer,
        MiddlewareAnalyzer $middlewareAnalyzer,
        PathBuilder $pathBuilder,
        SchemaBuilder $schemaBuilder
    ) {
        $this->config = $config;
        $this->routeAnalyzer = $routeAnalyzer;
        $this->requestAnalyzer = $requestAnalyzer;
        $this->controllerAnalyzer = $controllerAnalyzer;
        $this->responseAnalyzer = $responseAnalyzer;
        $this->middlewareAnalyzer = $middlewareAnalyzer;
        $this->pathBuilder = $pathBuilder;
        $this->schemaBuilder = $schemaBuilder;
    }

    /**
     * Build the complete OpenAPI specification.
     */
    public function build(): array
    {
        $this->reset();

        // Build base spec
        $this->buildInfo();
        $this->buildServers();

        // Analyze routes and build paths
        $this->analyzeAndBuildPaths();

        // Build components
        $this->buildComponents();

        // Build security
        $this->buildSecurity();

        // Build tags
        $this->buildTags();

        return $this->spec;
    }

    /**
     * Reset the builder.
     */
    public function reset(): self
    {
        $this->spec = [
            'openapi' => '3.1.0',
        ];
        $this->analyzedRoutes = [];
        $this->pathBuilder->reset();
        $this->schemaBuilder->reset();

        return $this;
    }

    /**
     * Build info section.
     */
    protected function buildInfo(): void
    {
        $infoConfig = $this->config['info'] ?? [];

        $info = [
            'title' => $infoConfig['title'] ?? 'API Documentation',
            'version' => $infoConfig['version'] ?? '1.0.0',
        ];

        if (!empty($infoConfig['description'])) {
            $info['description'] = $infoConfig['description'];
        }

        // Contact
        $contact = array_filter([
            'name' => $infoConfig['contact']['name'] ?? null,
            'email' => $infoConfig['contact']['email'] ?? null,
            'url' => $infoConfig['contact']['url'] ?? null,
        ]);
        if (!empty($contact)) {
            $info['contact'] = $contact;
        }

        // License
        $license = array_filter([
            'name' => $infoConfig['license']['name'] ?? null,
            'url' => $infoConfig['license']['url'] ?? null,
        ]);
        if (!empty($license)) {
            $info['license'] = $license;
        }

        $this->spec['info'] = $info;
    }

    /**
     * Build servers section.
     */
    protected function buildServers(): void
    {
        $servers = $this->config['servers'] ?? [];

        if (empty($servers)) {
            $servers = [
                [
                    'url' => config('app.url', 'http://localhost'),
                    'description' => 'Current Environment',
                ],
            ];
        }

        $this->spec['servers'] = $servers;
    }

    /**
     * Analyze routes and build paths.
     */
    protected function analyzeAndBuildPaths(): void
    {
        // Get all analyzed routes
        $routes = $this->routeAnalyzer->analyze();

        foreach ($routes as $routeData) {
            // Handle multiple methods for same route
            if (isset($routeData[0])) {
                foreach ($routeData as $route) {
                    $this->processRoute($route);
                }
            } else {
                $this->processRoute($routeData);
            }
        }

        $this->spec['paths'] = $this->pathBuilder->build();
    }

    /**
     * Process a single route.
     */
    protected function processRoute(array $routeData): void
    {
        $analysisData = [];

        // Analyze request
        if ($this->config['auto_detection']['form_requests'] ?? true) {
            $analysisData['request'] = $this->requestAnalyzer->analyze($routeData);
        }

        // Analyze controller
        if ($this->config['auto_detection']['inline_validation'] ?? true) {
            $analysisData['controller'] = $this->controllerAnalyzer->analyze($routeData);
        }

        // Analyze response
        if ($this->config['auto_detection']['json_resources'] ?? true) {
            $analysisData['response'] = $this->responseAnalyzer->analyze($analysisData['controller'] ?? []);
        }

        // Analyze middleware
        $analysisData['middleware'] = $this->middlewareAnalyzer->analyze($routeData);

        // Add to path builder
        $this->pathBuilder->addPath($routeData, $analysisData);

        // Store analyzed route
        $this->analyzedRoutes[] = [
            'route' => $routeData,
            'analysis' => $analysisData,
        ];
    }

    /**
     * Build components section.
     */
    protected function buildComponents(): void
    {
        $components = [];

        // Build schemas
        $this->schemaBuilder->addPlaceholdersForMissing();
        $schemas = $this->schemaBuilder->build();
        if (!empty($schemas)) {
            $components['schemas'] = $schemas;
        }

        // Build security schemes
        $securitySchemes = $this->middlewareAnalyzer->buildSecuritySchemes();
        if (!empty($securitySchemes)) {
            $components['securitySchemes'] = $securitySchemes;
        }

        if (!empty($components)) {
            $this->spec['components'] = $components;
        }
    }

    /**
     * Build security section.
     */
    protected function buildSecurity(): void
    {
        $securitySchemes = $this->config['security_schemes'] ?? [];
        
        // Find default security scheme
        foreach ($securitySchemes as $name => $config) {
            if (!empty($config['middleware'])) {
                $this->spec['security'] = [[$name => []]];
                break;
            }
        }

        // If no security configured, add bearer auth as default
        if (!isset($this->spec['security']) && !empty($this->spec['components']['securitySchemes'] ?? [])) {
            $firstScheme = array_key_first($this->spec['components']['securitySchemes']);
            $this->spec['security'] = [[$firstScheme => []]];
        }
    }

    /**
     * Build tags section.
     */
    protected function buildTags(): void
    {
        $tags = $this->routeAnalyzer->getUniqueTags(array_column($this->analyzedRoutes, 'route'));

        if (!empty($tags)) {
            $this->spec['tags'] = $tags;
        }
    }

    /**
     * Get the specification as YAML.
     */
    public function toYaml(): string
    {
        $spec = $this->build();
        
        return Yaml::dump($spec, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Get the specification as JSON.
     */
    public function toJson(int $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        $spec = $this->build();
        
        return json_encode($spec, $options);
    }

    /**
     * Save the specification to a file.
     */
    public function save(?string $path = null, ?string $format = null): bool
    {
        $outputConfig = $this->config['output'] ?? [];
        $path = $path ?? $outputConfig['path'] ?? public_path('docs/openapi.yaml');
        $format = $format ?? $outputConfig['format'] ?? 'yaml';

        // Ensure directory exists
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $content = $format === 'json' ? $this->toJson() : $this->toYaml();

        return file_put_contents($path, $content) !== false;
    }

    /**
     * Get analyzed routes.
     */
    public function getAnalyzedRoutes(): array
    {
        return $this->analyzedRoutes;
    }

    /**
     * Get statistics about the generated spec.
     */
    public function getStatistics(): array
    {
        $paths = $this->spec['paths'] ?? [];
        $operations = 0;

        foreach ($paths as $pathOperations) {
            $operations += count($pathOperations);
        }

        return [
            'paths' => count($paths),
            'operations' => $operations,
            'schemas' => count($this->spec['components']['schemas'] ?? []),
            'security_schemes' => count($this->spec['components']['securitySchemes'] ?? []),
            'tags' => count($this->spec['tags'] ?? []),
        ];
    }
}
