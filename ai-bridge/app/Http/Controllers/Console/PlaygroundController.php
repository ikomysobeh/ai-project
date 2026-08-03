<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\Gateway\ChatCompletionGateway;
use App\Services\WebAiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlaygroundController extends Controller
{
    public function index(Request $request, WebAiClient $webAi): Response
    {
        $user = $request->user();
        $apps = $user->isAdmin() ? Application::query() : Application::where('user_id', $user->id);

        return Inertia::render('console/playground', [
            'apps' => $apps->with('knowledgeBase')->get()->map(fn ($app) => [
                'id' => $app->id,
                'name' => $app->name,
                'default_model' => $app->default_model,
                'rag_ready' => $app->knowledgeBase?->status === 'ready',
                'knowledge_base_name' => $app->knowledgeBase?->name,
            ]),
            'models' => $this->modelIds($webAi),
        ]);
    }

    /**
     * Runs a REAL request through the actual gateway pipeline
     * (ChatCompletionGateway — the same one /v1/chat/completions uses), just
     * resolving the app from the authenticated user's own apps instead of a
     * Bearer token: tokens are shown once and never stored in retrievable
     * form, so the playground can't "pick an existing token" the way a
     * mocked UI might — this is the honest equivalent for an already
     * session-authenticated dashboard user testing their own app.
     */
    public function send(Request $request, int $app, ChatCompletionGateway $gateway): JsonResponse
    {
        $user = $request->user();
        $appQuery = $user->isAdmin() ? Application::query() : Application::where('user_id', $user->id);
        $app = $appQuery->with('knowledgeBase')->findOrFail($app);

        $validated = $request->validate([
            'model' => ['required', 'string'],
            'messages' => ['required', 'array', 'min:1'],
        ]);

        $result = $gateway->run($app, $validated, tokenId: null);

        return response()->json($result['body'], $result['status']);
    }

    /**
     * @return string[]
     */
    private function modelIds(WebAiClient $webAi): array
    {
        try {
            $response = $webAi->models();

            return $response->successful()
                ? collect($response->json('data', []))->pluck('id')->all()
                : [];
        } catch (ConnectionException) {
            return [];
        }
    }
}
