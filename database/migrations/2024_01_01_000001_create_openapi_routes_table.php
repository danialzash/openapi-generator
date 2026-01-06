<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $prefix = config('openapi-generator.table_prefix', 'openapi_');

        Schema::create($prefix . 'routes', function (Blueprint $table) {
            $table->id();
            
            // Route identification
            $table->string('route_name')->nullable()->index();
            $table->string('http_method', 10)->index();
            $table->string('uri', 500)->index();
            $table->string('controller')->nullable();
            $table->string('action')->nullable();
            
            // Unique constraint for method + uri combination
            $table->unique(['http_method', 'uri'], 'unique_route');
            
            // OpenAPI metadata
            $table->string('operation_id')->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('deprecated')->default(false);
            
            // External documentation
            $table->string('external_docs_url', 500)->nullable();
            $table->string('external_docs_description')->nullable();
            
            // Request body documentation
            $table->string('request_body_description')->nullable();
            $table->json('request_body_example')->nullable();
            $table->boolean('request_body_required')->default(true);
            
            // Response documentation (JSON object keyed by status code)
            $table->json('response_descriptions')->nullable();
            $table->json('response_examples')->nullable();
            
            // Custom parameters (additional query/header params not auto-detected)
            $table->json('custom_parameters')->nullable();
            
            // Security overrides (null = use auto-detected)
            $table->json('security_requirements')->nullable();
            
            // Visibility
            $table->boolean('is_hidden')->default(false);
            
            // Auto-detected data cache
            $table->json('auto_detected_data')->nullable();
            
            // Timestamps
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $prefix = config('openapi-generator.table_prefix', 'openapi_');
        Schema::dropIfExists($prefix . 'routes');
    }
};
