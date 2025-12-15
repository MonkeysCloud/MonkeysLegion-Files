<?php

declare(strict_types=1);

use MonkeysLegion\Database\Migration\AbstractMigration;
use MonkeysLegion\Database\Schema\Blueprint;

/**
 * Migration: Create ml_chunked_uploads table for tracking multipart uploads.
 * 
 * This table tracks incomplete chunked uploads for cleanup and resume capability.
 */
return new class extends AbstractMigration
{
    public function up(): void
    {
        $this->schema->create('ml_chunked_uploads', function (Blueprint $table) {
            // Primary identifier (upload session ID)
            $table->string('id', 64)->primary();
            
            // User/owner tracking
            $table->string('user_id', 36)->nullable();
            
            // File information
            $table->string('filename', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('total_size');
            
            // Chunk tracking
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('uploaded_chunks')->default(0);
            
            // Status
            $table->enum('status', ['pending', 'uploading', 'completed', 'aborted', 'expired'])
                ->default('pending');
            
            // Storage
            $table->string('disk', 32)->default('local');
            $table->string('temp_path', 1024)->nullable();
            $table->string('final_path', 1024)->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->json('chunks_info')->nullable(); // Array of chunk details
            
            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            
            // Indexes
            $table->index('user_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('ml_chunked_uploads');
    }
};
