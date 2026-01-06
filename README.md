# Laravel OpenAPI Generator

A powerful Laravel package that automatically generates OpenAPI 3.1 specifications from your Laravel application by analyzing routes, form requests, controllers, and JSON resources.

## Features

- **Automatic Route Analysis**: Scans all Laravel routes and extracts parameters, middleware, and controller information
- **Request Validation Mapping**: Converts Laravel validation rules to OpenAPI schema definitions
- **Response Schema Detection**: Analyzes JsonResource classes to generate response schemas
- **Security Scheme Detection**: Maps authentication middleware to OpenAPI security schemes
- **Database-Driven Metadata**: Store custom descriptions, examples, and tags in the database
- **Incremental Updates**: Track route changes and update documentation incrementally

## Installation

### 1. Add the package to your composer.json

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/openapi-generator"
        }
    ],
    "require": {
        "verge/laravel-openapi-generator": "*"
    }
}
```

### 2. Run composer update

```bash
composer update
```

### 3. Publish the configuration (optional)

```bash
php artisan vendor:publish --tag=openapi-generator-config
```

### 4. Run migrations

```bash
php artisan migrate
```

## Usage

### Scan Routes

Scan your application routes and populate the metadata database:

```bash
# Scan routes
php artisan openapi:scan

# Rescan all routes (fresh start)
php artisan openapi:scan --fresh

# Preview changes without saving
php artisan openapi:scan --dry-run
```

### Generate OpenAPI Specification

Generate the OpenAPI specification file:

```bash
# Generate to default location (public/docs/openapi.yaml)
php artisan openapi:generate

# Generate to custom location
php artisan openapi:generate --output=/path/to/openapi.yaml

# Generate as JSON
php artisan openapi:generate --format=json

# Output to stdout
php artisan openapi:generate --stdout
```

### Sync Metadata

Synchronize database metadata with current codebase:

```bash
# Check sync status
php artisan openapi:sync

# Remove orphaned entries
php artisan openapi:sync --clean

# Initialize security schemes from config
php artisan openapi:sync --init-security
```

## Configuration

The configuration file allows you to customize:

### API Info

```php
'info' => [
    'title' => env('OPENAPI_TITLE', 'API Documentation'),
    'version' => env('OPENAPI_VERSION', '1.0.0'),
    'description' => '',
],
```

### Route Filters

```php
'route_filters' => [
    'include_prefixes' => ['api/', 'v1/'],
    'exclude_prefixes' => ['_ignition', 'sanctum'],
    'exclude_middleware' => ['web'],
],
```

### Security Schemes

```php
'security_schemes' => [
    'bearerAuth' => [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
        'middleware' => ['auth:sanctum', 'auth:api'],
    ],
],
```

### Response Macros

Map custom response helpers to HTTP status codes:

```php
'response_macros' => [
    'show' => ['status' => 200, 'description' => 'Successful response'],
    'created' => ['status' => 201, 'description' => 'Resource created'],
],
```

## Adding Custom Metadata

### Via Database

After scanning routes, you can update metadata directly in the database:

```php
use Verge\OpenAPIGenerator\Models\RouteMetadata;

$route = RouteMetadata::findByRoute('GET', '/api/users');
$route->update([
    'summary' => 'List all users',
    'description' => 'Retrieve a paginated list of all users in the system.',
    'tags' => ['Users', 'Admin'],
    'request_body_example' => ['name' => 'John Doe'],
]);
```

### Via Custom Schemas

Add custom schema definitions:

```php
use Verge\OpenAPIGenerator\Models\SchemaDefinition;

SchemaDefinition::create([
    'name' => 'UserResponse',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string', 'format' => 'email'],
        ],
    ],
    'description' => 'User response object',
]);
```

## Validation Rule Mapping

The package automatically maps Laravel validation rules to OpenAPI types:

| Laravel Rule | OpenAPI Type |
|--------------|--------------|
| `string` | `type: string` |
| `integer` | `type: integer` |
| `numeric` | `type: number` |
| `boolean` | `type: boolean` |
| `array` | `type: array` |
| `email` | `type: string, format: email` |
| `url` | `type: string, format: uri` |
| `uuid` | `type: string, format: uuid` |
| `date` | `type: string, format: date` |
| `in:a,b,c` | `enum: [a, b, c]` |
| `min:N` | `minimum: N` or `minLength: N` |
| `max:N` | `maximum: N` or `maxLength: N` |
| `required` | Added to `required` array |
| `nullable` | `nullable: true` |

## Programmatic Usage

```php
use Verge\OpenAPIGenerator\Builders\OpenAPIBuilder;

$builder = app(OpenAPIBuilder::class);

// Build the specification
$spec = $builder->build();

// Get as YAML
$yaml = $builder->toYaml();

// Get as JSON
$json = $builder->toJson();

// Save to file
$builder->save('/path/to/openapi.yaml', 'yaml');

// Get statistics
$stats = $builder->getStatistics();
```

## License

MIT License
