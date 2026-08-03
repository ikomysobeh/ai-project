<?php

namespace App\Services\Rag;

/**
 * Splits plain text into ~800-token chunks with a small overlap
 * (AI-BUILD-BRIEF.md §5.2). There's no tokenizer dependency in this
 * project, so token counts are approximated at ~0.75 words/token (a common
 * rough ratio for English text) and chunking is done on word boundaries.
 * Good enough for chunk sizing — RAG doesn't need exact token counts, just
 * chunks that are small enough to embed and retrieve sensibly.
 */
class TextChunker
{
    private const WORDS_PER_TOKEN = 0.75;

    /**
     * @return array<int, array{content: string, token_count: int}>
     */
    public static function chunk(string $text, int $chunkTokens = 800, int $overlapTokens = 100): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return [];
        }

        $chunkWords = max(1, (int) round($chunkTokens * self::WORDS_PER_TOKEN));
        $overlapWords = min($chunkWords - 1, max(0, (int) round($overlapTokens * self::WORDS_PER_TOKEN)));
        $step = $chunkWords - $overlapWords;

        $chunks = [];

        for ($start = 0; $start < count($words); $start += $step) {
            $slice = array_slice($words, $start, $chunkWords);
            $content = implode(' ', $slice);

            $chunks[] = [
                'content' => $content,
                'token_count' => (int) round(count($slice) / self::WORDS_PER_TOKEN),
            ];

            if ($start + $chunkWords >= count($words)) {
                break;
            }
        }

        return $chunks;
    }
}
