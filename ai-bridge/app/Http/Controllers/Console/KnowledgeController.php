<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\KnowledgeBase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $kbs = $user->isAdmin() ? KnowledgeBase::query() : KnowledgeBase::where('user_id', $user->id);

        return Inertia::render('console/knowledge', [
            'knowledgeBases' => $kbs->withCount(['documents', 'chunks'])
                ->get()
                ->map(fn ($kb) => [
                    'id' => $kb->id,
                    'name' => $kb->name,
                    'status' => $kb->status,
                    'embedding_model' => $kb->embedding_model,
                    'documents_count' => $kb->documents_count,
                    'chunks_count' => $kb->chunks_count,
                    'attached_app' => Application::where('knowledge_base_id', $kb->id)->value('name'),
                ]),
        ]);
    }
}
