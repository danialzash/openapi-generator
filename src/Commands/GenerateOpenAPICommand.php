<?php

namespace Verge\OpenAPIGenerator\Commands;

use Illuminate\Console\Command;
use Verge\OpenAPIGenerator\Builders\OpenAPIBuilder;

class GenerateOpenAPICommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'openapi:generate 
                            {--output= : Output file path}
                            {--format= : Output format (yaml or json)}
                            {--stdout : Output to stdout instead of file}';

    /**
     * The console command description.
     */
    protected $description = 'Generate OpenAPI specification from analyzed routes';

    protected OpenAPIBuilder $builder;

    public function __construct(OpenAPIBuilder $builder)
    {
        parent::__construct();
        $this->builder = $builder;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Generating OpenAPI specification...');
        $this->newLine();

        try {
            // Build the specification
            $this->info('Analyzing routes...');
            $spec = $this->builder->build();

            // Get statistics
            $stats = $this->builder->getStatistics();

            $this->info('Analysis complete!');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Paths', $stats['paths']],
                    ['Operations', $stats['operations']],
                    ['Schemas', $stats['schemas']],
                    ['Security Schemes', $stats['security_schemes']],
                    ['Tags', $stats['tags']],
                ]
            );

            $this->newLine();

            // Output to stdout
            if ($this->option('stdout')) {
                $format = $this->option('format') ?? 'yaml';
                $content = $format === 'json' ? $this->builder->toJson() : $this->builder->toYaml();
                $this->line($content);
                return self::SUCCESS;
            }

            // Save to file
            $outputPath = $this->option('output') ?? config('openapi-generator.output.path');
            $format = $this->option('format') ?? config('openapi-generator.output.format', 'yaml');

            $this->info("Saving to: {$outputPath}");

            $success = $this->builder->save($outputPath, $format);

            if ($success) {
                $this->info('OpenAPI specification generated successfully!');
                $this->info("File: {$outputPath}");
                return self::SUCCESS;
            }

            $this->error('Failed to save OpenAPI specification.');
            return self::FAILURE;

        } catch (\Throwable $e) {
            $this->error('Error generating OpenAPI specification:');
            $this->error($e->getMessage());
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
