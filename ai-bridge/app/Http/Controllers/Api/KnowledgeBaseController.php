<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreKnowledgeBaseRequest;
use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $knowledgeBases = $this->visibleTo($request)->latest()->get();

        return response()->json(['knowledge_bases' => $knowledgeBases]);
    }

    public function store(StoreKnowledgeBaseRequest $request): JsonResponse
    {
        $kb = KnowledgeBase::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['knowledge_base' => $kb], 201);
    }

    /**
     * Same "members only their own, owners/admins see everything in the
     * tenant" rule as AppController.
     */
    /** @return Builder<KnowledgeBase> */
    private function visibleTo(Request $request): Builder
    {
        $user = $request->user();

        return $user->isAdmin()
            ? KnowledgeBase::query()
            : KnowledgeBase::where('user_id', $user->id);
    }
}
