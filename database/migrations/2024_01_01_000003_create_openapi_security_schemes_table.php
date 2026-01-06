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

        Schema::create($prefix . 'security_schemes', function (Blueprint $table) {
            $table->id();
            
            // Scheme identification
            $table->string('name')->unique();
            
            // OpenAPI security scheme type
            $table->enum('type', ['apiKey', 'http', 'oauth2', 'openIdConnect']);
            
            // For apiKey type
            $table->string('api_key_name')->nullable();
            $table->enum('api_key_in', ['query', 'header', 'cookie'])->nullable();
            
            // For http type
            $table->string('scheme')->nullable(); // basic, bearer, etc.
            $table->string('bearer_format')->nullable(); // JWT, etc.
            
            // For oauth2 type
            $table->json('flows')->nullable();
            
            // For openIdConnect type
            $table->string('open_id_connect_url', 500)->nullable();
            
            // Documentation
            $table->text('description')->nullable();
            
            // Middleware mapping
            $table->json('middleware')->nullable();
            
            // Whether this is the default security scheme
            $table->boolean('is_default')->default(false);
            
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
        Schema::dropIfExists($prefix . 'security_schemes');
    }
};
