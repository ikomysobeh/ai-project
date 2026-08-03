<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\Gateway\ChatCompletionGateway;
use App\Services\WebAiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The gateway's public, token-authenticated entry point (mvp-scope.md build
 * order steps 5-7 — Flow C in AI-BUILD-BRIEF.md §5.1). The actual pipeline
 * (RAG, account rotation, usage logging) lives in ChatCompletionGateway,
 * shared with the console Playground — see that class's docblock for why.
 * Rate/quota limits are enforced earlier, by the api.limits middleware
 * (routes/gateway.php), before this controller runs.
 */
class GatewayController extends Controller
{
    public function __construct(
        private readonly WebAiClient $webAi,
        private readonly ChatCompletionGateway $gateway,
    ) {}

    public function chatCompletions(Request $request): JsonResponse
    {
        $app = Application::with('knowledgeBase')->find($request->attributes->get('gateway_app_id'));

        if ($app === null) {
            return response()->json(['error' => ['message' => 'App not found.']], 404);
        }

        $result = $this->gateway->run(
            $app,
            [...$request->all(), 'model' => $request->input('model')],
            $request->attributes->get('gateway_token_id'),
        );

        return response()->json($result['body'], $result['status']);
    }

    public function models(): JsonResponse
    {
        try {
            $response = $this->webAi->models();
        } catch (ConnectionException) {
            return response()->json([
                'error' => ['message' => 'Upstream (WebAI-to-API) is unreachable.'],
            ], 502);
        }

        return response()->json($response->json(), $response->status());
    }
}
