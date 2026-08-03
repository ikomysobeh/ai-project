<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the internal WebAI-to-API service (see AI-BUILD-BRIEF.md
 * §2.1) — Laravel is the only thing allowed to talk to it.
 *
 * chatCompletions() targets WebAI-to-API's /v1/temporary/chat/completions,
 * not its /v1/chat/completions — deliberately. That's the endpoint we
 * patched (see WebAI-to-API/src/app/services/providers/gemini/temporary_chat.py)
 * to accept a per-request Gemini cookie override via headers, which is what
 * makes the per-user account pool possible at all: WebAI-to-API's main chat
 * path holds one Gemini session per process with no per-request override
 * seam (its SessionRegistry/SessionManager are shared, process-wide). The
 * "temporary" endpoint has no such conversation-persistence machinery to
 * fight with, and not needing Gemini's own conversation threading is fine
 * for us anyway — our OpenAI-compatible gateway is itself stateless
 * (callers resend full message history each call, same as real OpenAI).
 */
class WebAiClient
{
    protected PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl(config('services.webai.base_url'))
            ->timeout(120)
            ->acceptJson();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{psid: string, psidts: string}|null  $cookieOverride
     */
    public function chatCompletions(array $payload, ?array $cookieOverride = null): Response
    {
        return $this->http
            ->withHeaders($this->overrideHeaders($cookieOverride))
            ->post('/v1/temporary/chat/completions', $payload);
    }

    public function models(): Response
    {
        return $this->http->get('/v1/models');
    }

    /**
     * @param  array{psid: string, psidts: string}|null  $cookieOverride
     * @return array<string, string>
     */
    private function overrideHeaders(?array $cookieOverride): array
    {
        if ($cookieOverride === null) {
            return [];
        }

        return [
            'X-Gemini-1PSID' => $cookieOverride['psid'],
            'X-Gemini-1PSIDTS' => $cookieOverride['psidts'],
        ];
    }
}
