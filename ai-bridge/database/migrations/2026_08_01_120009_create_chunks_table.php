<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vector dimension is hardcoded to 768 (nomic-embed-text's output size).
     * The MVP locks one embedding model system-wide, so this doesn't need to
     * be dynamic — see AI-BUILD-BRIEF.md §3.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->unsignedInteger('token_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE chunks ADD COLUMN embedding vector(768)');

        // Tenant-filtered vector search is a security requirement, not just
        // performance (AI-BUILD-BRIEF.md §10.3) — every retrieval query must
        // filter by tenant_id, so it needs to be indexed alongside the vector.
        DB::statement('CREATE INDEX chunks_tenant_kb_idx ON chunks (tenant_id, knowledge_base_id)');
        DB::statement('CREATE INDEX chunks_embedding_hnsw_idx ON chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
