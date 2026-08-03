<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['knowledge_base_id', 'source_name', 'source_type', 'status'])]
class Document extends Model
{
    use BelongsToTenant;

    /** @return BelongsTo<KnowledgeBase, $this> */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    /** @return HasMany<Chunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
