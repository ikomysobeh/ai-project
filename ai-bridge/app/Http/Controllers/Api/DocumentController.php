<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDocumentRequest;
use App\Jobs\IngestDocumentJob;
use App\Models\Document;
use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request, int $knowledgeBase): JsonResponse
    {
        $kb = $this->kbVisibleTo($request)->findOrFail($knowledgeBase);

        return response()->json(['documents' => $kb->documents()->latest()->get()]);
    }

    public function store(StoreDocumentRequest $request, int $knowledgeBase): JsonResponse
    {
        $kb = $this->kbVisibleTo($request)->findOrFail($knowledgeBase);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        $document = Document::create([
            'knowledge_base_id' => $kb->id,
            'source_name' => $file->getClientOriginalName(),
            'source_type' => $extension,
            'status' => 'indexing',
        ]);

        $kb->forceFill(['status' => 'indexing'])->save();

        // Stored under a per-document, hard-to-guess path rather than the
        // original filename — the queue worker (a separate container) reads
        // it from the same shared storage, then deletes it once ingested.
        $path = $file->storeAs('rag-uploads', "{$document->id}-".Str::random(8).".{$extension}", 'local');

        if ($path === false) {
            $document->forceFill(['status' => 'failed'])->save();

            return response()->json(['error' => ['message' => 'Could not store the uploaded file.']], 500);
        }

        IngestDocumentJob::dispatch($document->id, $path);

        return response()->json(['document' => $document], 201);
    }

    public function destroy(Request $request, int $document): JsonResponse
    {
        $document = Document::whereIn('knowledge_base_id', $this->kbVisibleTo($request)->pluck('id'))
            ->findOrFail($document);

        $document->delete();

        return response()->json(status: 204);
    }

    /**
     * Same "members only their own, owners/admins see everything in the
     * tenant" rule as AppController.
     */
    /** @return Builder<KnowledgeBase> */
    private function kbVisibleTo(Request $request): Builder
    {
        $user = $request->user();

        return $user->isAdmin()
            ? KnowledgeBase::query()
            : KnowledgeBase::where('user_id', $user->id);
    }
}
