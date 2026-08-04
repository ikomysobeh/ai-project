<?php

namespace App\Services\Gateway;

use Yethee\Tiktoken\EncoderProvider;

/**
 * Estimated token counts for usage tracking (usage_records.prompt_tokens /
 * completion_tokens / total_tokens). Gemini's web interface — unlike its
 * paid API — never reports real token counts (see
 * docs/12-using-the-platform.md); every number here is a best-effort
 * approximation using OpenAI's cl100k_base BPE encoding (via tiktoken-php),
 * not Gemini's actual, non-public tokenizer. Good for comparing usage
 * across apps/tokens/time; not exact, not billing-grade.
 *
 * Requires outbound internet access the first time this runs in a given
 * container — it downloads and caches the ~1.7MB cl100k_base vocab file
 * from openaipublic.blob.core.windows.net on first use (see
 * docs/08-deployment-docker.md). The cache is pointed at storage/ (bind-
 * mounted, survives a container recreate) instead of the default temp dir.
 */
class TokenEstimator
{
    private static ?EncoderProvider $provider = null;

    public static function count(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        return count(self::provider()->get('cl100k_base')->encode($text));
    }

    private static function provider(): EncoderProvider
    {
        if (self::$provider === null) {
            self::$provider = new EncoderProvider();
            self::$provider->setVocabCache(storage_path('app/tiktoken-cache'));
        }

        return self::$provider;
    }
}
