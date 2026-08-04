<?php

namespace App\Services\Gateway;

use App\Models\Application;
use App\Models\UpstreamAccount;
use App\Models\UsageRecord;
use App\Services\Rag\RagRetriever;
use App\Services\WebAiClient;
use Illuminate\Http\Client\ConnectionException;

/**
 * The actual gateway pipeline (Flow C, AI-BUILD-BRIEF.md §5.1) — extracted
 * out of GatewayController so both the token-authenticated public
 * /v1/chat/completions route AND the session-authenticated console
 * Playground (Console\PlaygroundController) can run the SAME real logic
 * (RAG, account rotation, usage logging) against an already-resolved App,
 * rather than duplicating it or forcing the Playground to fake a Bearer
 * token it can't actually have — the Playground is a session-authenticated
 * dashboard user testing their own app, not a token holder, so it resolves
 * the App directly instead of going through token auth (see
 * PlaygroundController).
 */
class ChatCompletionGateway
{
    public function __construct(
        private readonly WebAiClient $webAi,
        private readonly RagRetriever $rag,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: mixed}
     */
    public function run(Application $app, array $payload, ?int $tokenId): array
    {
        $startedAt = microtime(true);
        $model = $payload['model'] ?: $app->default_model;
        $messages = $payload['messages'] ?? [];
        $usedRag = false;

        if ($app->knowledgeBase !== null && $app->knowledgeBase->status === 'ready') {
            $question = $this->lastUserMessageText($messages);

            if ($question !== null) {
                $chunks = $this->rag->topChunks($app->knowledgeBase, $question);

                if (! empty($chunks)) {
                    $messages = [$this->contextMessage($chunks), ...$messages];
                    $usedRag = true;
                }
            }
        }

        $forwardPayload = [...$payload, 'model' => $model, 'messages' => $messages];

        // LRU: never-used accounts first (null last_used_at), then oldest-used.
        $accounts = UpstreamAccount::where('user_id', $app->user_id)
            ->where('status', 'active')
            ->orderByRaw('last_used_at IS NOT NULL, last_used_at ASC')
            ->get();

        if ($accounts->isEmpty()) {
            $this->logUsage($app, $tokenId, $model, $startedAt, $usedRag, status: 'error', errorType: 'no_active_accounts');

            return [
                'status' => 503,
                'body' => ['error' => ['message' => 'No active Gemini accounts for this app\'s owner. Add or re-authenticate an account.']],
            ];
        }

        foreach ($accounts as $account) {
            try {
                $response = $this->webAi->chatCompletions($forwardPayload, $account->cookies_encrypted);
            } catch (ConnectionException) {
                $this->logUsage($app, $tokenId, $model, $startedAt, $usedRag, status: 'error', errorType: 'upstream_unreachable', account: $account);

                return ['status' => 502, 'body' => ['error' => ['message' => 'Upstream (WebAI-to-API) is unreachable.']]];
            }

            if ($response->successful()) {
                $account->forceFill(['status' => 'active', 'last_used_at' => now()])->save();

                $body = $response->json();
                $promptTokens = TokenEstimator::count($this->messagesText($messages));
                $completionTokens = TokenEstimator::count((string) ($body['choices'][0]['message']['content'] ?? ''));

                // WebAI-to-API's own `usage` is always {0,0,0} — Gemini's web
                // interface never reports real token counts (see
                // TokenEstimator) — so replace it with our estimate rather
                // than handing the caller a number we know is meaningless.
                $body['usage'] = [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                ];

                $this->logUsage($app, $tokenId, $model, $startedAt, $usedRag, status: 'success', account: $account, promptTokens: $promptTokens, completionTokens: $completionTokens);

                return ['status' => $response->status(), 'body' => $body];
            }

            // Only these two are account-health problems worth rotating past;
            // anything else (a malformed request, a genuine 5xx) would fail
            // identically on every other account too, so surface it directly
            // instead of burning through the whole pool pointlessly.
            if ($response->status() === 401) {
                $reason = $response->json('detail') ?? $response->json('error.message');
                $account->markExpired(is_string($reason) ? $reason : null);

                continue;
            }

            if ($response->status() === 429) {
                $account->markCoolingDown();

                continue;
            }

            $this->logUsage($app, $tokenId, $model, $startedAt, $usedRag, status: 'error', errorType: 'upstream_error', account: $account);

            return ['status' => $response->status(), 'body' => $response->json()];
        }

        // All accounts in the pool failed — no shared fallback exists by
        // design (mvp-scope.md §2), so this is a hard, visible failure.
        $this->logUsage($app, $tokenId, $model, $startedAt, $usedRag, status: 'error', errorType: 'all_accounts_unavailable');

        return [
            'status' => 503,
            'body' => ['error' => ['message' => 'All Gemini accounts for this app\'s owner are unavailable. Re-authenticate an account and try again.']],
        ];
    }

    private function logUsage(
        Application $app,
        ?int $tokenId,
        string $model,
        float $startedAt,
        bool $usedRag,
        string $status,
        ?string $errorType = null,
        ?UpstreamAccount $account = null,
        int $promptTokens = 0,
        int $completionTokens = 0,
    ): void {
        UsageRecord::create([
            'app_id' => $app->id,
            'token_id' => $tokenId,
            'upstream_account_id' => $account?->id,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => $status,
            'error_type' => $errorType,
            'used_rag' => $usedRag,
        ]);
    }

    /**
     * Flattens every message's text into one string for token estimation —
     * content is either a plain string or an array of OpenAI-style content
     * parts (see docs/12-using-the-platform.md); only `type: text` parts
     * carry estimable token cost, file parts don't.
     *
     * @param  array<int, array{role?: string, content?: mixed}>  $messages
     */
    private function messagesText(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            $content = $message['content'] ?? null;

            if (is_string($content)) {
                $parts[] = $content;
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    if (($part['type'] ?? null) === 'text' && isset($part['text'])) {
                        $parts[] = $part['text'];
                    }
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<int, array{role?: string, content?: mixed}>  $messages
     */
    private function lastUserMessageText(array $messages): ?string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? null) === 'user' && is_string($messages[$i]['content'] ?? null)) {
                return $messages[$i]['content'];
            }
        }

        return null;
    }

    /**
     * @param  string[]  $chunks
     * @return array{role: string, content: string}
     */
    private function contextMessage(array $chunks): array
    {
        return [
            'role' => 'system',
            'content' => "Use the following context to answer the user's question if relevant:\n\n"
                .implode("\n\n---\n\n", $chunks),
        ];
    }
}
