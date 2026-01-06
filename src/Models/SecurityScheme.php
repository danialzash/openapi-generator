<?php

namespace Verge\OpenAPIGenerator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class SecurityScheme extends Model
{
    /**
     * Get the table name with prefix.
     */
    public function getTable(): string
    {
        $prefix = config('openapi-generator.table_prefix', 'openapi_');
        return $prefix . 'security_schemes';
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'type',
        'api_key_name',
        'api_key_in',
        'scheme',
        'bearer_format',
        'flows',
        'open_id_connect_url',
        'description',
        'middleware',
        'is_default',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'flows' => 'array',
        'middleware' => 'array',
        'is_default' => 'boolean',
    ];

    /**
     * Scope a query to only include default security schemes.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to filter by type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Find security schemes by middleware.
     */
    public static function findByMiddleware(string $middleware): ?self
    {
        return static::whereJsonContains('middleware', $middleware)->first();
    }

    /**
     * Find security schemes that match any of the given middleware.
     */
    public static function findByMiddlewareArray(array $middlewareList): array
    {
        $schemes = [];

        foreach ($middlewareList as $middleware) {
            // Handle middleware with parameters (e.g., "auth:sanctum")
            $middlewareName = explode(':', $middleware)[0];
            
            $found = static::where(function ($query) use ($middleware, $middlewareName) {
                $query->whereJsonContains('middleware', $middleware)
                    ->orWhereJsonContains('middleware', $middlewareName);
            })->get();

            foreach ($found as $scheme) {
                $schemes[$scheme->name] = $scheme;
            }
        }

        return array_values($schemes);
    }

    /**
     * Get the OpenAPI security scheme definition.
     */
    public function toOpenAPISecurityScheme(): array
    {
        $scheme = [
            'type' => $this->type,
        ];

        if ($this->description) {
            $scheme['description'] = $this->description;
        }

        switch ($this->type) {
            case 'apiKey':
                $scheme['name'] = $this->api_key_name;
                $scheme['in'] = $this->api_key_in;
                break;

            case 'http':
                $scheme['scheme'] = $this->scheme;
                if ($this->bearer_format) {
                    $scheme['bearerFormat'] = $this->bearer_format;
                }
                break;

            case 'oauth2':
                $scheme['flows'] = $this->flows ?? [];
                break;

            case 'openIdConnect':
                $scheme['openIdConnectUrl'] = $this->open_id_connect_url;
                break;
        }

        return $scheme;
    }

    /**
     * Get the security requirement object for this scheme.
     */
    public function toSecurityRequirement(array $scopes = []): array
    {
        return [$this->name => $scopes];
    }

    /**
     * Create default security schemes from config.
     */
    public static function createFromConfig(): void
    {
        $schemes = config('openapi-generator.security_schemes', []);

        foreach ($schemes as $name => $config) {
            $data = [
                'name' => $name,
                'type' => $config['type'],
                'middleware' => $config['middleware'] ?? [],
            ];

            if ($config['type'] === 'apiKey') {
                $data['api_key_name'] = $config['name'] ?? 'X-API-Key';
                $data['api_key_in'] = $config['in'] ?? 'header';
            }

            if ($config['type'] === 'http') {
                $data['scheme'] = $config['scheme'] ?? 'bearer';
                $data['bearer_format'] = $config['bearerFormat'] ?? null;
            }

            if ($config['type'] === 'oauth2') {
                $data['flows'] = $config['flows'] ?? [];
            }

            if ($config['type'] === 'openIdConnect') {
                $data['open_id_connect_url'] = $config['openIdConnectUrl'] ?? null;
            }

            static::updateOrCreate(['name' => $name], $data);
        }
    }
}
