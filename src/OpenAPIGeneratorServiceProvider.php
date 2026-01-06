<?php

namespace Verge\OpenAPIGenerator;

use Illuminate\Support\ServiceProvider;
use Verge\OpenAPIGenerator\Analyzers\ControllerAnalyzer;
use Verge\OpenAPIGenerator\Analyzers\MiddlewareAnalyzer;
use Verge\OpenAPIGenerator\Analyzers\RequestAnalyzer;
use Verge\OpenAPIGenerator\Analyzers\ResponseAnalyzer;
use Verge\OpenAPIGenerator\Analyzers\RouteAnalyzer;
use Verge\OpenAPIGenerator\Builders\OpenAPIBuilder;
use Verge\OpenAPIGenerator\Builders\PathBuilder;
use Verge\OpenAPIGenerator\Builders\SchemaBuilder;
use Verge\OpenAPIGenerator\Commands\GenerateOpenAPICommand;
use Verge\OpenAPIGenerator\Commands\ScanRoutesCommand;
use Verge\OpenAPIGenerator\Commands\SyncMetadataCommand;

class OpenAPIGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/openapi-generator.php',
            'openapi-generator'
        );

        // Register analyzers as singletons
        $this->app->singleton(RouteAnalyzer::class, function ($app) {
            return new RouteAnalyzer($app['config']->get('openapi-generator'));
        });

        $this->app->singleton(RequestAnalyzer::class, function ($app) {
            return new RequestAnalyzer($app['config']->get('openapi-generator'));
        });

        $this->app->singleton(ControllerAnalyzer::class, function ($app) {
            return new ControllerAnalyzer($app['config']->get('openapi-generator'));
        });

        $this->app->singleton(ResponseAnalyzer::class, function ($app) {
            return new ResponseAnalyzer($app['config']->get('openapi-generator'));
        });

        $this->app->singleton(MiddlewareAnalyzer::class, function ($app) {
            return new MiddlewareAnalyzer($app['config']->get('openapi-generator'));
        });

        // Register builders
        $this->app->singleton(SchemaBuilder::class, function ($app) {
            return new SchemaBuilder($app['config']->get('openapi-generator'));
        });

        $this->app->singleton(PathBuilder::class, function ($app) {
            return new PathBuilder(
                $app['config']->get('openapi-generator'),
                $app->make(SchemaBuilder::class)
            );
        });

        $this->app->singleton(OpenAPIBuilder::class, function ($app) {
            return new OpenAPIBuilder(
                $app['config']->get('openapi-generator'),
                $app->make(RouteAnalyzer::class),
                $app->make(RequestAnalyzer::class),
                $app->make(ControllerAnalyzer::class),
                $app->make(ResponseAnalyzer::class),
                $app->make(MiddlewareAnalyzer::class),
                $app->make(PathBuilder::class),
                $app->make(SchemaBuilder::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/openapi-generator.php' => config_path('openapi-generator.php'),
        ], 'openapi-generator-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'openapi-generator-migrations');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanRoutesCommand::class,
                GenerateOpenAPICommand::class,
                SyncMetadataCommand::class,
            ]);
        }
    }
}
