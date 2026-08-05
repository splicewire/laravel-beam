<?php

namespace Splicewire\Beam\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Rushing\Versioning\Contracts\Versionable;
use Rushing\Versioning\Models\Version;

/**
 * The conversation surface the embed subsystem depends on — extracted from the former `ChatBase` (TH-07
 * step 1) so consumers depend on the ROLE, not a concrete model. TH-07 then DELETED `ChatBase`: the shared
 * machinery moved to the beam-threads `ConversationParticle` trait, and tower Thread + beam-embed Embed
 * `use` it and implement THIS interface directly. The column/AI-tier migration onto the beam-threads
 * particle is TH-08.
 *
 * A role interface, deliberately NOT a mirror of the whole conversation model: it declares ONLY the methods
 * the embed/tower seams actually call on their `Conversation` parameters. It extends {@see Versionable}
 * (the conversation particle already implements it) because the embed publish/spawn path reads and advances the
 * conversation-config version HEAD; it adds {@see headVersion()}/{@see snapshotVersion()} (supplied by the
 * versioning trait, not the base contract) and the embed-kind + message-relation surface the seams touch.
 *
 * Column/relation reads the seams do through the magic `__get` accessor (`embed_policy`,
 * `template_version`, `retention_days`, `id`, `visitor_id`, `published_from_id`, `title`, `created_at`)
 * are dynamic on the concrete Eloquent model and are not declared here — an interface cannot honestly
 * declare magic properties. Likewise the concrete `::query()` / `newInstance()` runtime plumbing stays on
 * the host's concrete conversation model (config-resolved `embed.base_model`) until TH-08; this role only
 * covers the instance methods the seams invoke.
 */
interface Conversation extends Versionable
{
    /** The primary key (UUID) of the conversation row. */
    public function getKey();

    /** Whether this conversation is a published embed template. */
    public function isPublished(): bool;

    /** The version this conversation's HEAD pin currently points at, if any. */
    public function headVersion(): ?Version;

    /** Freeze the current conversation config into a new version and advance HEAD to it. */
    public function snapshotVersion(?string $label = null): Version;

    /** The conversation's messages relation (host message model, config-resolved). */
    public function messages(): HasMany;

    /**
     * Persist attribute changes through the normal model write path. Untyped return to stay
     * signature-compatible with Eloquent's `Model::update()` (which declares no return type).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     * @return bool
     */
    public function update(array $attributes = [], array $options = []);
}
