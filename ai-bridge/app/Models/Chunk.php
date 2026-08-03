<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Expression;

#[Fillable(['document_id', 'knowledge_base_id', 'content', 'token_count', 'metadata', 'embedding'])]
class Chunk extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<KnowledgeBase, $this> */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    /**
     * pgvector has no native Eloquent cast, so this accessor/mutator pair
     * translates between a plain PHP float array and the `vector(768)`
     * column (stored/returned by Postgres as a "[0.1,0.2,...]" string).
     *
     * @return Attribute<float[]|null, float[]|null>
     */
    protected function embedding(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null
                ? null
                : array_map(floatval(...), explode(',', trim($value, '[]'))),
            set: function (?array $value) {
                if ($value === null) {
                    return null;
                }

                // Expression wants a literal-string, but this is safely built
                // from floatval()-coerced numbers only — no user input
                // reaches this string.
                // @phpstan-ignore argument.type
                return new Expression("'[".implode(',', array_map(floatval(...), $value))."]'::vector");
            },
        );
    }
}
