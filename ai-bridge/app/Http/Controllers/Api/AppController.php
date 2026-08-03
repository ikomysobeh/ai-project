<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AttachKnowledgeBaseRequest;
use App\Http\Requests\Api\StoreAppRequest;
use App\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $apps = $this->visibleTo($request)->with('knowledgeBase')->latest()->get();

        return response()->json(['apps' => $apps]);
    }

    public function store(StoreAppRequest $request): JsonResponse
    {
        $app = Application::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['app' => $app], 201);
    }

    public function destroy(Request $request, int $app): JsonResponse
    {
        // Deliberately a plain int, not an Application $app type-hint: implicit
        // route-model-binding runs (via SubstituteBindings) before our
        // SetTenantContext middleware, so it would 404 against the
        // fail-closed tenant scope. Looking it up here — after all
        // middleware has run — keeps the scope intact.
        $app = $this->visibleTo($request)->findOrFail($app);

        $app->delete();

        return response()->json(status: 204);
    }

    public function attachKnowledgeBase(AttachKnowledgeBaseRequest $request, int $app): JsonResponse
    {
        $app = $this->visibleTo($request)->findOrFail($app);

        $app->forceFill(['knowledge_base_id' => $request->validated('knowledge_base_id')])->save();

        return response()->json(['app' => $app->fresh('knowledgeBase')]);
    }

    /**
     * Members only manage their own apps; owners/admins manage every app in
     * the tenant (mvp-scope.md §4 — tenant scoping is already handled by
     * BelongsToTenant, this is the additional per-user restriction on top).
     */
    /** @return Builder<Application> */
    private function visibleTo(Request $request): Builder
    {
        $user = $request->user();

        return $user->isAdmin()
            ? Application::query()
            : Application::where('user_id', $user->id);
    }
}
