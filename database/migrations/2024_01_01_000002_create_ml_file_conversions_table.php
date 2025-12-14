<?php

declare(strict_types=1);

use MonkeysLegion\Database\Migration\AbstractMigration;
use MonkeysLegion\Database\Schema\Blueprint;

/**
 * Migration: Create ml_file_conversions table for file variants.
 * 
 * This table stores converted versions of files (thumbnails, optimized images, etc).
 */
return new class extends AbstractMigration
{
    public function up(): void
    {
        $this->schema->create('ml_file_conversions', function (Blueprint $table) {
            // Primary identifier
            $table->uuid('id')->primary();
            
            // Reference to original file
            $table->uuid('file_id');
            $table->foreign('file_id')
                ->references('id')
                ->on('ml_files')
                ->onDelete('cascade');
            
            // Conversion details
            $table->string('conversion_name', 64); // e.g., 'thumb', 'medium', 'webp'
            $table->string('disk', 32)->default('local');
            $table->string('path', 1024);
            
            // Converted file metadata
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            
            // Image-specific dimensions
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            
            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');
            $table->text('error_message')->nullable();
            
            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            
            // Indexes
            $table->index('file_id');
            $table->index('conversion_name');
            $table->index('status');
            
            // Unique constraint: one conversion per file per name
            $table->unique(['file_id', 'conversion_name'], 'uq_file_conversion');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('ml_file_conversions');
    }
};
