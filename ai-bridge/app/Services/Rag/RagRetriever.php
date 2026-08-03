<?php

namespace App\Services\Rag;

use App\Models\Chunk;
use App\Models\KnowledgeBase;
use Illuminate\Database\Query\Expression;

/**
 * Query-time half of RAG (Flow C step 4, AI-BUILD-BRIEF.md §5.1): embed the
 * question, then a tenant + KB-filtered vector search for the top-K nearest
 * chunks. The tenant filter isn't optional decoration — every retrieval
 * query must scope by tenant_id or one tenant can read another's documents
 * (AI-BUILD-BRIEF.md §10.3). Chunk's BelongsToTenant global scope covers
 * that automatically here; the explicit knowledge_base_id filter narrows it
 * further to the KB actually attached to the app.
 */
class RagRetriever
{
    public function __construct(private readonly OllamaClient $ollama) {}

    /**
     * @return string[] chunk contents, nearest first
     */
    public function topChunks(KnowledgeBase $knowledgeBase, string $query, int $limit = 5): array
    {
        $vector = $this->ollama->embed($query);
        $literal = "'[".implode(',', array_map(floatval(...), $vector))."]'::vector";

        // Expression wants a literal-string, but this is safely built from
        // floatval()-coerced numbers only — no user input reaches this string.
        // @phpstan-ignore argument.type
        $orderExpression = new Expression("embedding <=> {$literal}");

        return Chunk::where('knowledge_base_id', $knowledgeBase->id)
            ->orderBy($orderExpression)
            ->limit($limit)
            ->pluck('content')
            ->all();
    }
}
