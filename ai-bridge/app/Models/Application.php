<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Maps to the `apps` table (mvp-scope.md §6/§7 call this resource "apps").
 * Named Application, not App, so it doesn't collide with the `App` root
 * namespace when imported via `use App\Models\App;` alongside other
 * fully-qualified `App\...` references.
 */
#[Fillable(['user_id', 'name', 'default_model', 'knowledge_base_id', 'status'])]
class Application extends Model
{
    use BelongsToTenant;

    protected $table = 'apps';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<KnowledgeBase, $this> */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    /** @return HasMany<ApiToken, $this> */
    public function tokens(): HasMany
    {
        // Explicit FK: Eloquent's default guess is `application_id` (based on
        // this class's name), but the column is `app_id` (see the class docblock
        // on why this model isn't named `App`).
        return $this->hasMany(ApiToken::class, 'app_id');
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class, 'app_id');
    }
}
