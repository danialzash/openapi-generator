<?php

namespace Verge\OpenAPIGenerator\Analyzers;

use Illuminate\Support\Str;
use Verge\OpenAPIGenerator\Contracts\AnalyzerInterface;
use Verge\OpenAPIGenerator\Models\SecurityScheme;

class MiddlewareAnalyzer implements AnalyzerInterface
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Analyze middleware and return security requirements.
     */
    public function analyze(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $middleware = $data['middleware'] ?? [];
        
        return $this->analyzeMiddlewareList($middleware);
    }

    /**
     * Analyze a list of middleware.
     */
    public function analyzeMiddlewareList(array $middleware): array
    {
        $result = [
            'security' => [],
            'rate_limiting' => null,
            'requires_auth' => false,
            'is_public' => true,
        ];

        foreach ($middleware as $mw) {
            // Parse middleware name and parameters
            $parsed = $this->parseMiddleware($mw);
            
            // Check for authentication middleware
            $authResult = $this->checkAuthMiddleware($parsed);
            if ($authResult) {
                $result['security'][] = $authResult;
                $result['requires_auth'] = true;
                $result['is_public'] = false;
            }

            // Check for rate limiting
            $rateLimitResult = $this->checkRateLimitMiddleware($parsed);
            if ($rateLimitResult) {
                $result['rate_limiting'] = $rateLimitResult;
            }
        }

        return $result;
    }

    /**
     * Parse middleware string into name and parameters.
     */
    protected function parseMiddleware(string $middleware): array
    {
        $parts = explode(':', $middleware, 2);
        
        return [
            'name' => $parts[0],
            'full' => $middleware,
            'parameters' => isset($parts[1]) ? explode(',', $parts[1]) : [],
        ];
    }

    /**
     * Check if middleware is an authentication middleware.
     */
    protected function checkAuthMiddleware(array $parsed): ?array
    {
        $name = $parsed['name'];
        $full = $parsed['full'];
        $params = $parsed['parameters'];

        // Get configured security schemes
        $configSchemes = $this->config['security_schemes'] ?? [];

        // Check against configured middleware
        foreach ($configSchemes as $schemeName => $schemeConfig) {
            $schemeMiddleware = $schemeConfig['middleware'] ?? [];
            
            // Check if current middleware matches any in the scheme
            if (in_array($full, $schemeMiddleware) || in_array($name, $schemeMiddleware)) {
                return [
                    'scheme' => $schemeName,
                    'scopes' => $this->extractScopes($parsed),
                ];
            }
        }

        // Check for common auth patterns
        if ($this->isAuthMiddleware($name)) {
            return [
                'scheme' => $this->inferSecurityScheme($parsed),
                'scopes' => $this->extractScopes($parsed),
            ];
        }

        return null;
    }

    /**
     * Check if middleware name indicates authentication.
     */
    protected function isAuthMiddleware(string $name): bool
    {
        $authMiddleware = [
            'auth',
            'auth.basic',
            'auth.session',
            'verified',
            'can',
            'ability',
            'abilities',
            'scope',
            'scopes',
            'jwt.auth',
            'jwt.verify',
            'passport',
        ];

        return in_array($name, $authMiddleware) || Str::startsWith($name, 'auth');
    }

    /**
     * Infer security scheme from middleware.
     */
    protected function inferSecurityScheme(array $parsed): string
    {
        $name = $parsed['name'];
        $params = $parsed['parameters'];

        // auth:sanctum
        if ($name === 'auth' && in_array('sanctum', $params)) {
            return 'bearerAuth';
        }

        // auth:api (usually Passport)
        if ($name === 'auth' && in_array('api', $params)) {
            return 'bearerAuth';
        }

        // JWT
        if (Str::contains($name, 'jwt')) {
            return 'bearerAuth';
        }

        // Basic auth
        if (Str::contains($name, 'basic')) {
            return 'basicAuth';
        }

        // Default to bearer
        return 'bearerAuth';
    }

    /**
     * Extract scopes from middleware parameters.
     */
    protected function extractScopes(array $parsed): array
    {
        $name = $parsed['name'];
        $params = $parsed['parameters'];

        // For scope/scopes middleware, all params are scopes
        if (in_array($name, ['scope', 'scopes'])) {
            return $params;
        }

        // For ability/abilities middleware, extract abilities
        if (in_array($name, ['ability', 'abilities', 'can'])) {
            return $params;
        }

        return [];
    }

    /**
     * Check if middleware is rate limiting middleware.
     */
    protected function checkRateLimitMiddleware(array $parsed): ?array
    {
        $name = $parsed['name'];
        $params = $parsed['parameters'];

        if (!in_array($name, ['throttle', 'rate', 'ratelimit'])) {
            return null;
        }

        // Default rate limit config
        $result = [
            'requests' => null,
            'per_seconds' => null,
            'limiter' => null,
        ];

        // throttle:60,1 format (60 requests per 1 minute)
        if (count($params) >= 2 && is_numeric($params[0]) && is_numeric($params[1])) {
            $result['requests'] = (int) $params[0];
            $result['per_seconds'] = (int) $params[1] * 60; // minutes to seconds
        }
        // throttle:60 format (60 requests per minute)
        elseif (count($params) === 1 && is_numeric($params[0])) {
            $result['requests'] = (int) $params[0];
            $result['per_seconds'] = 60;
        }
        // throttle:api format (named limiter)
        elseif (count($params) >= 1 && !is_numeric($params[0])) {
            $result['limiter'] = $params[0];
        }

        return $result;
    }

    /**
     * Get security requirements for OpenAPI.
     */
    public function getSecurityRequirements(array $middleware): array
    {
        $analysis = $this->analyzeMiddlewareList($middleware);
        $requirements = [];

        foreach ($analysis['security'] as $security) {
            $requirements[] = [
                $security['scheme'] => $security['scopes'],
            ];
        }

        return $requirements;
    }

    /**
     * Get rate limit headers for OpenAPI.
     */
    public function getRateLimitHeaders(array $middleware): array
    {
        $analysis = $this->analyzeMiddlewareList($middleware);
        
        if (!$analysis['rate_limiting']) {
            return [];
        }

        $rateLimit = $analysis['rate_limiting'];
        $headers = [];

        if ($rateLimit['requests']) {
            $headers['X-RateLimit-Limit'] = [
                'description' => 'The maximum number of requests allowed',
                'schema' => ['type' => 'integer'],
                'example' => $rateLimit['requests'],
            ];

            $headers['X-RateLimit-Remaining'] = [
                'description' => 'The number of remaining requests',
                'schema' => ['type' => 'integer'],
            ];
        }

        return $headers;
    }

    /**
     * Build security schemes from database or config.
     */
    public function buildSecuritySchemes(): array
    {
        $schemes = [];

        // First, try to get from database
        try {
            $dbSchemes = SecurityScheme::all();
            
            foreach ($dbSchemes as $scheme) {
                $schemes[$scheme->name] = $scheme->toOpenAPISecurityScheme();
            }
        } catch (\Throwable $e) {
            // Database not available, use config
        }

        // Merge with config (config takes precedence for schema definition)
        $configSchemes = $this->config['security_schemes'] ?? [];
        
        foreach ($configSchemes as $name => $config) {
            if (!isset($schemes[$name])) {
                $schemes[$name] = $this->buildSchemeFromConfig($name, $config);
            }
        }

        // Add default bearer auth if no schemes defined
        if (empty($schemes)) {
            $schemes['bearerAuth'] = [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
            ];
        }

        return $schemes;
    }

    /**
     * Build a security scheme from config array.
     */
    protected function buildSchemeFromConfig(string $name, array $config): array
    {
        $scheme = [
            'type' => $config['type'],
        ];

        switch ($config['type']) {
            case 'http':
                $scheme['scheme'] = $config['scheme'] ?? 'bearer';
                if (isset($config['bearerFormat'])) {
                    $scheme['bearerFormat'] = $config['bearerFormat'];
                }
                break;

            case 'apiKey':
                $scheme['name'] = $config['name'] ?? 'X-API-Key';
                $scheme['in'] = $config['in'] ?? 'header';
                break;

            case 'oauth2':
                $scheme['flows'] = $config['flows'] ?? [];
                break;

            case 'openIdConnect':
                $scheme['openIdConnectUrl'] = $config['openIdConnectUrl'] ?? '';
                break;
        }

        if (isset($config['description'])) {
            $scheme['description'] = $config['description'];
        }

        return $scheme;
    }
}
