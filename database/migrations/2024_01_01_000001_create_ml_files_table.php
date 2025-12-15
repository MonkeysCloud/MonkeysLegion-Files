<?php

declare(strict_types=1);

use MonkeysLegion\Database\Migration\AbstractMigration;
use MonkeysLegion\Database\Schema\Blueprint;

/**
 * Migration: Create ml_files table for file tracking.
 * 
 * This table stores metadata and tracking information for all uploaded files.
 */
return new class extends AbstractMigration
{
    public function up(): void
    {
        $this->schema->create('ml_files', function (Blueprint $table) {
            // Primary identifier
            $table->uuid('id')->primary();
            
            // Storage location
            $table->string('disk', 32)->default('local');
            $table->string('path', 1024);
            $table->string('original_name', 255)->nullable();
            
            // File metadata
            $table->string('mime_type', 127)->nullable();
            $table->string('extension', 32)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            
            // Integrity
            $table->string('checksum_md5', 32)->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            
            // Visibility and access
            $table->enum('visibility', ['public', 'private'])->default('private');
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            
            // Polymorphic relationship (optional)
            $table->string('fileable_type', 255)->nullable();
            $table->string('fileable_id', 36)->nullable();
            $table->string('collection', 64)->nullable();
            
            // Custom metadata
            $table->json('metadata')->nullable();
            
            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('deleted_at')->nullable();
            
            // Indexes
            $table->index('disk');
            $table->index('mime_type');
            $table->index('collection');
            $table->index('checksum_sha256');
            $table->index(['fileable_type', 'fileable_id'], 'idx_fileable');
            $table->index('created_at');
            $table->index('deleted_at');
            
            // Unique constraint on disk + path
            $table->unique(['disk', 'path'], 'uq_disk_path');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('ml_files');
    }
};
