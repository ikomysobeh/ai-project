<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Ollama's embeddings API (AI-BUILD-BRIEF.md §2.4). The
 * embedding model is locked system-wide via config('services.ollama.embed_model')
 * — matching it at ingest and query time is a non-negotiable RAG rule
 * (AI-BUILD-BRIEF.md §5.2), so nothing here accepts a model override.
 */
class OllamaClient
{
    /**
     * @return float[]
     */
    public function embed(string $text): array
    {
        $response = Http::baseUrl(config('services.ollama.base_url'))
            ->timeout(60)
            ->post('/api/embeddings', [
                'model' => config('services.ollama.embed_model'),
                'prompt' => $text,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Ollama embedding request failed: {$response->status()} {$response->body()}");
        }

        $embedding = $response->json('embedding');

        if (! is_array($embedding)) {
            throw new RuntimeException('Ollama embedding response was missing the "embedding" field.');
        }

        return $embedding;
    }
}
