<?php

namespace Verge\OpenAPIGenerator\Commands;

use Illuminate\Console\Command;
use Verge\OpenAPIGenerator\Analyzers\RouteAnalyzer;
use Verge\OpenAPIGenerator\Models\RouteMetadata;
use Verge\OpenAPIGenerator\Models\SchemaDefinition;
use Verge\OpenAPIGenerator\Models\SecurityScheme;

class SyncMetadataCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'openapi:sync 
                            {--clean : Remove orphaned metadata entries}
                            {--init-security : Initialize security schemes from config}';

    /**
     * The console command description.
     */
    protected $description = 'Synchronize OpenAPI metadata with current codebase';

    protected RouteAnalyzer $routeAnalyzer;

    public function __construct(RouteAnalyzer $routeAnalyzer)
    {
        parent::__construct();
        $this->routeAnalyzer = $routeAnalyzer;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Synchronizing OpenAPI metadata...');
        $this->newLine();

        // Initialize security schemes if requested
        if ($this->option('init-security')) {
            $this->initializeSecuritySchemes();
        }

        // Get current routes from codebase
        $codebaseRoutes = $this->routeAnalyzer->analyze();
        $codebaseKeys = $this->extractRouteKeys($codebaseRoutes);

        // Get routes from database
        try {
            $dbRoutes = RouteMetadata::all();
        } catch (\Throwable $e) {
            $this->error('Could not access database. Run migrations first.');
            return self::FAILURE;
        }

        // Find orphaned entries
        $orphaned = $dbRoutes->filter(function ($route) use ($codebaseKeys) {
            $key = $route->http_method . ':' . $route->uri;
            return !in_array($key, $codebaseKeys);
        });

        // Find routes missing metadata
        $missingMetadata = [];
        foreach ($codebaseRoutes as $routeData) {
            $routes = isset($routeData[0]) ? $routeData : [$routeData];
            foreach ($routes as $route) {
                $key = $route['method'] . ':' . $route['uri'];
                $existing = $dbRoutes->first(fn ($r) => $r->http_method . ':' . $r->uri === $key);
                
                if (!$existing) {
                    $missingMetadata[] = $route;
                } elseif (empty($existing->summary) && empty($existing->description)) {
                    $missingMetadata[] = array_merge($route, ['has_entry' => true]);
                }
            }
        }

        // Display sync status
        $this->info('Synchronization Status:');
        $this->newLine();

        // Show orphaned routes
        if ($orphaned->isNotEmpty()) {
            $this->warn("Found {$orphaned->count()} orphaned route(s) in database:");
            $this->table(
                ['Method', 'URI', 'Summary'],
                $orphaned->map(fn ($r) => [$r->http_method, $r->uri, $r->summary ?? '(none)'])->toArray()
            );

            if ($this->option('clean')) {
                if ($this->confirm('Delete these orphaned entries?', false)) {
                    $count = $orphaned->count();
                    foreach ($orphaned as $route) {
                        $route->delete();
                    }
                    $this->info("Deleted {$count} orphaned entries.");
                }
            } else {
                $this->info('Use --clean to remove orphaned entries.');
            }
        } else {
            $this->info('✓ No orphaned routes found.');
        }

        $this->newLine();

        // Show routes missing metadata
        $routesWithoutDocs = array_filter($missingMetadata, fn ($r) => isset($r['has_entry']));
        $routesNotInDb = array_filter($missingMetadata, fn ($r) => !isset($r['has_entry']));

        if (!empty($routesNotInDb)) {
            $this->warn("Found " . count($routesNotInDb) . " route(s) not in database:");
            $this->table(
                ['Method', 'URI', 'Controller'],
                array_map(fn ($r) => [$r['method'], $r['uri'], class_basename($r['controller'] ?? 'N/A')], array_slice($routesNotInDb, 0, 10))
            );
            
            if (count($routesNotInDb) > 10) {
                $this->info('... and ' . (count($routesNotInDb) - 10) . ' more');
            }

            $this->info('Run "php artisan openapi:scan" to add these routes.');
        } else {
            $this->info('✓ All routes are in database.');
        }

        $this->newLine();

        if (!empty($routesWithoutDocs)) {
            $this->warn("Found " . count($routesWithoutDocs) . " route(s) without documentation:");
            $this->table(
                ['Method', 'URI', 'Controller'],
                array_map(fn ($r) => [$r['method'], $r['uri'], class_basename($r['controller'] ?? 'N/A')], array_slice($routesWithoutDocs, 0, 10))
            );
            
            if (count($routesWithoutDocs) > 10) {
                $this->info('... and ' . (count($routesWithoutDocs) - 10) . ' more');
            }
        } else {
            $this->info('✓ All routes have documentation.');
        }

        // Summary
        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Routes in codebase', count($codebaseKeys)],
                ['Routes in database', $dbRoutes->count()],
                ['Routes with documentation', $dbRoutes->count() - count($routesWithoutDocs)],
                ['Orphaned entries', $orphaned->count()],
                ['Schemas in database', SchemaDefinition::count()],
                ['Security schemes', SecurityScheme::count()],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Extract route keys from analyzed routes.
     */
    protected function extractRouteKeys(array $routes): array
    {
        $keys = [];

        foreach ($routes as $routeData) {
            $items = isset($routeData[0]) ? $routeData : [$routeData];
            foreach ($items as $route) {
                $keys[] = $route['method'] . ':' . $route['uri'];
            }
        }

        return $keys;
    }

    /**
     * Initialize security schemes from config.
     */
    protected function initializeSecuritySchemes(): void
    {
        $this->info('Initializing security schemes from config...');

        try {
            SecurityScheme::createFromConfig();
            $count = SecurityScheme::count();
            $this->info("Created/updated {$count} security scheme(s).");
        } catch (\Throwable $e) {
            $this->error('Failed to initialize security schemes: ' . $e->getMessage());
        }

        $this->newLine();
    }
}
