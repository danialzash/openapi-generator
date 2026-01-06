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

        Schema::create($prefix . 'schemas', function (Blueprint $table) {
            $table->id();
            
            // Schema identification
            $table->string('name')->unique();
            $table->string('source_class')->nullable()->index();
            
            // Schema type
            $table->enum('schema_type', ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'])
                ->default('object');
            
            // OpenAPI schema definition (full JSON Schema)
            $table->json('schema');
            
            // Documentation
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('example')->nullable();
            
            // Relationships
            $table->json('refs')->nullable(); // References to other schemas
            
            // Whether this schema was auto-generated
            $table->boolean('is_auto_generated')->default(false);
            
            // Timestamps
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $prefix = config('openapi-generator.table_prefix', 'openapi_');
        Schema::dropIfExists($prefix . 'schemas');
    }
};
