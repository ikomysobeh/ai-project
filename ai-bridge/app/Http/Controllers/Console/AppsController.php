<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\KnowledgeBase;
use App\Services\WebAiClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppsController extends Controller
{
    public function index(Request $request, WebAiClient $webAi): Response
    {
        $user = $request->user();
        $apps = $user->isAdmin() ? Application::query() : Application::where('user_id', $user->id);

        return Inertia::render('console/apps', [
            'apps' => $apps->with('knowledgeBase')
                ->withCount(['tokens' => fn ($q) => $q->whereNull('revoked_at')])
                ->latest()
                ->get()
                ->map(fn ($app) => [
                    'id' => $app->id,
                    'name' => $app->name,
                    'default_model' => $app->default_model,
                    'knowledge_base' => $app->knowledgeBase?->only(['id', 'name']),
                    'tokens_count' => $app->tokens_count,
                    'requests' => $app->usageRecords()->count(),
                ]),
            'knowledgeBases' => ($user->isAdmin() ? KnowledgeBase::query() : KnowledgeBase::where('user_id', $user->id))
                ->get(['id', 'name']),
            'models' => $this->modelIds($webAi),
        ]);
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
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return [];
        }
    }
}
