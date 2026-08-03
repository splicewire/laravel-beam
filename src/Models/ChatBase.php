<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Rushing\PermissionCascade\Concerns\HasUserId;
use Rushing\PermissionCascade\Concerns\HasVisibility;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Rushing\Versioning\Contracts\Versionable;
use Spatie\ModelFlags\Models\Concerns\HasFlags;
use Spatie\ModelStatus\HasStatuses;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Splicewire\Beam\Enums\ThreadKind;

/**
 * The generic conversation base (recohere T02): a schema-typed, versioned `threads` row carrying
 * ONLY the verified-generic Thread machinery — UUIDs, ownership (HasUserId), visibility cascade,
 * flags, spatie model-status, and the versionable snapshot/restore of the conversation config.
 * The tower {@see \Splicewire\Tower\Models\Thread} extends this and layers on the KNOWLEDGE-grounding traits
 * (HasFragments / HasSilos / HasContextScopes / HasInstructionsProvider), tagging (HasTags — kept on
 * Thread to avoid the beam⇄beam-taxonomy cycle), AI orchestration, and the assistant relation.
 *
 * ChatBase deliberately does NOT carry HasTags: `Splicewire\Beam\Taxonomy` depends DOWN on beam-core,
 * so pulling HasTags into beam-core would form a beam ⇄ beam-taxonomy require cycle. Tagging therefore
 * stays a tower-Thread concern.
 *
 * Relations that reach tower-tier / beam-embed models ({@see messages()}, {@see visitor()}) are
 * declared by class-STRING (the message model is CONFIG-resolved via `config('embed.message_model')`)
 * so Eloquent resolves them lazily at call time — no autoload-time dependency from beam-core UP onto
 * tower-core or beam-embed.
 */
class ChatBase extends Model implements Versionable
{
    use HasFlags;
    use HasStatuses;
    use HasUserId;
    use HasUuids;
    use HasVisibility;
    use VersionableTrait;

    protected $table = 'threads';

    /**
     * The generic mass-assignable columns shared by every conversation kind (interactive / embed
     * template / embed session). Knowledge-grounding columns are set through Thread's own traits.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'assistant_id',
        'title',
        'model',
        'max_tool_steps',
        'model_params',
        'instructions_provider',
        'instructions_text',
        'instructions_schemas',
        'tools',
        'user_id',
        'visibility',
        'kind',
        'visitor_id',
        'published_from_id',
        'snapshot_config',
        'template_version',
        'embed_policy',
        'retention_days',
        'session_status',
        'created_at',
        'updated_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $casts = [
        'model_params' => SchemalessAttributes::class,
        'tools' => SchemalessAttributes::class,
        'instructions_schemas' => SchemalessAttributes::class,
        'kind' => ThreadKind::class,
        'snapshot_config' => 'array',
        'embed_policy' => 'array',
    ];

    private static $whiteListFilter = ['*'];

    public function scopeWithModelParams(): Builder
    {
        return $this->model_params->modelScope();
    }

    public function scopeWithTools(): Builder
    {
        return $this->tools->modelScope();
    }

    public function scopeWithInstructionsSchemas(): Builder
    {
        return $this->instructions_schemas->modelScope();
    }

    public function messages(): HasMany
    {
        // Config-resolve the host's message model (recohere follow-up): beam-core is the BOTTOM tier
        // and must NOT reference the host application namespace / tower. The host binds its concrete message class behind
        // `config('embed.message_model')` — mirroring the `config('embed.base_model')` seam T02
        // established for the base Thread. A bare beam site can leave it unset (null ⇒ Eloquent
        // derives it from this class, which a beam-only deployment never actually calls).
        // Explicit FK: the column is `thread_id` (the relation used to live on the tower Thread).
        return $this->hasMany(config('embed.message_model'), 'thread_id')->orderBy('created_at');
    }

    // -------------------------------------------------------------------------
    // Embed (DIE-04)
    // -------------------------------------------------------------------------

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(\Splicewire\Beam\Embed\Models\Visitor::class);
    }

    /** The template this session was spawned from (self-FK, sessions only). */
    public function publishedFrom(): BelongsTo
    {
        return $this->belongsTo(static::class, 'published_from_id');
    }

    public function isPublished(): bool
    {
        return $this->kind === ThreadKind::EmbedTemplate;
    }

    public function isSession(): bool
    {
        return $this->kind === ThreadKind::EmbedSession;
    }

    // -------------------------------------------------------------------------
    // Versionable (embed-instruction staging — ticket 13)
    //
    // A published embed's visitor-facing config is the *published* version, not the live working
    // row: editing an embed stages the change on the working row (HEAD lags), and a deliberate
    // Publish snapshots it (HEAD advances). The spawner reads HEAD, so a visitor never sees an
    // unpublished edit. Versions are keyed by getMorphClass() — always the base `thread` morph here.
    // -------------------------------------------------------------------------

    /**
     * Freeze the conversation config that publishes DELIBERATELY (ticket 13, payload width W3): the
     * instructions triplet + model/params/tool-budget/tools + title/assistant. Operational policy
     * (`enabled` kill-switch, `allowed_origins`, wallet, launcher cosmetics) is DELIBERATELY absent —
     * it stays live so safety controls apply instantly, never gated behind a re-publish. The leading
     * `_hash` lets a reader answer "has the working copy diverged from HEAD?" without diffing.
     *
     * @return array<string, mixed>
     */
    public function toVersionSnapshot(): array
    {
        $content = [
            'assistant_id' => $this->assistant_id,
            'title' => $this->title,
            'model' => $this->model,
            'max_tool_steps' => $this->max_tool_steps,
            'model_params' => $this->model_params?->toArray(),
            'instructions_provider' => $this->instructions_provider,
            'instructions_text' => $this->instructions_text,
            'instructions_schemas' => $this->instructions_schemas?->toArray(),
            'tools' => $this->tools?->toArray(),
        ];

        return ['_hash' => md5((string) json_encode($content)), ...$content];
    }

    /**
     * Apply a frozen conversation-config snapshot back onto this thread via the normal write path.
     * Only the staged fields are touched; operational policy is never in the snapshot, so it is left
     * exactly as-is. Used by the version store's pointer-move restore (rollback).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $this->fill([
            'assistant_id' => $snapshot['assistant_id'] ?? null,
            'title' => $snapshot['title'] ?? null,
            'model' => $snapshot['model'] ?? null,
            'max_tool_steps' => $snapshot['max_tool_steps'] ?? null,
            'model_params' => $snapshot['model_params'] ?? null,
            'instructions_provider' => $snapshot['instructions_provider'] ?? null,
            'instructions_text' => $snapshot['instructions_text'] ?? null,
            'instructions_schemas' => $snapshot['instructions_schemas'] ?? null,
            'tools' => $snapshot['tools'] ?? null,
        ])->save();
    }

    /** Sessions review query: `kind = embed_session` (optionally under a template). */
    public function scopeSessions(Builder $query, ?string $publishedFromId = null): Builder
    {
        $query->where('kind', ThreadKind::EmbedSession->value);

        if ($publishedFromId !== null) {
            $query->where('published_from_id', $publishedFromId);
        }

        return $query;
    }
}
