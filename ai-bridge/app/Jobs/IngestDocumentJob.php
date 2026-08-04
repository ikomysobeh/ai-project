<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\DocumentTextExtractor;
use App\Services\Rag\OllamaClient;
use App\Services\Rag\TextChunker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Flow E (mvp-scope.md §5): extract → chunk (~800 tokens, overlap) → embed
 * each chunk → store in pgvector → flip document status. Runs in the queue
 * container, never in the web request (AI-BUILD-BRIEF.md §5.2).
 */
class IngestDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $documentId,
        private readonly string $storagePath,
    ) {}

    public function handle(OllamaClient $ollama): void
    {
        // Queue workers have no HTTP request to set this via middleware —
        // every tenant-scoped model touched below needs it set explicitly.
        $document = Document::withoutGlobalScopes()->findOrFail($this->documentId);
        app(TenantContext::class)->set($document->tenant_id);

        try {
            $extension = strtolower(pathinfo($this->storagePath, PATHINFO_EXTENSION));
            $absolutePath = Storage::disk('local')->path($this->storagePath);
            $text = DocumentTextExtractor::extract($absolutePath, $extension);

            if (trim($text) === '') {
                throw new \RuntimeException('Uploaded document has no extractable text.');
            }

            foreach (TextChunker::chunk($text) as $chunk) {
                Chunk::create([
                    'document_id' => $document->id,
                    'knowledge_base_id' => $document->knowledge_base_id,
                    'content' => $chunk['content'],
                    'token_count' => $chunk['token_count'],
                    'embedding' => $ollama->embed($chunk['content']),
                ]);
            }

            $document->forceFill(['status' => 'ready'])->save();
        } catch (Throwable $e) {
            Log::error("Document ingestion failed for document {$document->id}: {$e->getMessage()}");
            $document->forceFill(['status' => 'failed'])->save();
        } finally {
            Storage::disk('local')->delete($this->storagePath);
            $this->recomputeKnowledgeBaseStatus($document);
        }
    }

    private function recomputeKnowledgeBaseStatus(Document $document): void
    {
        $kb = $document->knowledgeBase()->withoutGlobalScopes()->first();

        if ($kb === null) {
            return;
        }

        $statuses = Document::withoutGlobalScopes()
            ->where('knowledge_base_id', $kb->id)
            ->pluck('status');

        $kb->forceFill([
            'status' => match (true) {
                $statuses->contains('indexing') => 'indexing',
                $statuses->contains('ready') => 'ready',
                default => 'failed',
            },
        ])->save();
    }
}
