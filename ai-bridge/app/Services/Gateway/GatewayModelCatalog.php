<?php

namespace App\Services\Gateway;

use App\Services\WebAiClient;
use Illuminate\Http\Client\ConnectionException;

/**
 * The subset of WebAI-to-API's model catalog this gateway can actually
 * serve. Only plain gemini-* ids work through /v1/temporary/chat/completions
 * — playwright/* and atlas/* prefixed models are unconditionally rejected
 * there with a 400 (see
 * WebAI-to-API/src/app/services/providers/gemini/temporary_chat.py's
 * _resolve_temporary_chat_model), so an app configured with one would look
 * fine right up until its first real gateway call.
 *
 * This is the one place both the "New App" model picker, the Playground,
 * and the public GET /v1/models response draw their model list from, so a
 * model only ever shows up here if this gateway can actually serve it.
 */
class GatewayModelCatalog
{
    /** @return string[] */
    public static function usableModelIds(WebAiClient $webAi): array
    {
        try {
            $response = $webAi->models();

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('data', []))
                ->pluck('id')
                ->filter(fn (string $id) => self::isUsable($id))
                ->values()
                ->all();
        } catch (ConnectionException) {
            return [];
        }
    }

    public static function isUsable(string $modelId): bool
    {
        return ! str_starts_with($modelId, 'playwright/') && ! str_starts_with($modelId, 'atlas/');
    }
}
