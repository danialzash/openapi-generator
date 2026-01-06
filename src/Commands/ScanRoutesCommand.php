<?php

namespace Verge\OpenAPIGenerator\Commands;

use Illuminate\Console\Command;
use Verge\OpenAPIGenerator\Analyzers\RouteAnalyzer;
use Verge\OpenAPIGenerator\Models\RouteMetadata;

class ScanRoutesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'openapi:scan 
                            {--fresh : Drop existing route metadata and rescan}
                            {--dry-run : Show what would be changed without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Scan routes and populate the route metadata database';

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
        $this->info('Scanning routes...');

        $dryRun = $this->option('dry-run');
        $fresh = $this->option('fresh');

        // Get existing routes from database
        $existingRoutes = [];
        try {
            $existingRoutes = RouteMetadata::all()
                ->keyBy(fn ($r) => $r->http_method . ':' . $r->uri)
                ->all();
        } catch (\Throwable $e) {
            $this->warn('Could not load existing routes from database: ' . $e->getMessage());
            $this->warn('Run migrations first: php artisan migrate');
            return self::FAILURE;
        }

        if ($fresh && !$dryRun) {
            $this->info('Clearing existing route metadata...');
            RouteMetadata::truncate();
            $existingRoutes = [];
        }

        // Analyze routes
        $analyzedRoutes = $this->routeAnalyzer->analyze();

        $newRoutes = [];
        $updatedRoutes = [];
        $unchangedRoutes = [];
        $scannedKeys = [];

        foreach ($analyzedRoutes as $routeData) {
            // Handle multiple methods for same route
            $routes = isset($routeData[0]) ? $routeData : [$routeData];

            foreach ($routes as $route) {
                $key = $route['method'] . ':' . $route['uri'];
                $scannedKeys[] = $key;

                if (!isset($existingRoutes[$key])) {
                    $newRoutes[] = $route;
                } else {
                    $existing = $existingRoutes[$key];
                    
                    // Check if route has changed
                    if ($this->hasRouteChanged($existing, $route)) {
                        $updatedRoutes[] = $route;
                    } else {
                        $unchangedRoutes[] = $route;
                    }
                }
            }
        }

        // Find removed routes
        $removedRoutes = array_filter($existingRoutes, fn ($r, $key) => !in_array($key, $scannedKeys), ARRAY_FILTER_USE_BOTH);

        // Display summary
        $this->newLine();
        $this->info('Scan Results:');
        $this->table(
            ['Status', 'Count'],
            [
                ['New routes', count($newRoutes)],
                ['Updated routes', count($updatedRoutes)],
                ['Unchanged routes', count($unchangedRoutes)],
                ['Removed routes', count($removedRoutes)],
            ]
        );

        // Show new routes
        if (!empty($newRoutes)) {
            $this->newLine();
            $this->info('New Routes:');
            $this->table(
                ['Method', 'URI', 'Controller'],
                array_map(fn ($r) => [$r['method'], $r['uri'], class_basename($r['controller'] ?? 'N/A')], $newRoutes)
            );
        }

        // Show removed routes
        if (!empty($removedRoutes)) {
            $this->newLine();
            $this->warn('Removed Routes (no longer in codebase):');
            $this->table(
                ['Method', 'URI', 'Controller'],
                array_map(fn ($r) => [$r->http_method, $r->uri, class_basename($r->controller ?? 'N/A')], $removedRoutes)
            );
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run - no changes made.');
            return self::SUCCESS;
        }

        // Apply changes
        $this->newLine();
        $this->info('Applying changes...');

        $bar = $this->output->createProgressBar(count($newRoutes) + count($updatedRoutes));
        $bar->start();

        // Insert new routes
        foreach ($newRoutes as $route) {
            RouteMetadata::updateOrCreateFromScan($route);
            $bar->advance();
        }

        // Update existing routes
        foreach ($updatedRoutes as $route) {
            RouteMetadata::updateOrCreateFromScan($route);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Scan completed successfully!');
        $this->info("Total routes in database: " . RouteMetadata::count());

        return self::SUCCESS;
    }

    /**
     * Check if a route has changed.
     */
    protected function hasRouteChanged(RouteMetadata $existing, array $scanned): bool
    {
        return $existing->controller !== ($scanned['controller'] ?? null) ||
               $existing->action !== ($scanned['action'] ?? null) ||
               $existing->route_name !== ($scanned['name'] ?? null);
    }
}
