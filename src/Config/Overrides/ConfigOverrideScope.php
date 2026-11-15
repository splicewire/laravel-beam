<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Concerns\Versionable as VersionableTrait;
use Rushing\Versioning\Contracts\Versionable;
use Splicewire\Beam\Facades\Beam;

/**
 * One section's override scope at the operator tier (ADR-0245 §9; config-sections ticket 05): the working DRAFT, a
 * map of pointer → that pointer's current revision in {@see ConfigOverrideRevision}, and two pins into
 * `rushing/laravel-versioning`'s store — HEAD (`head_version`, the last saved draft) and `published_version`, the one
 * the runtime applies. A draft is pending when the two differ.
 *
 * A draft entry is `{op: set|null, revision: <uuid>, secret: bool, set: bool, setAt: <iso8601>, value?: mixed}`;
 * `value` is present for a non-secret `set` only, so neither the scope nor any version snapshot ever holds a secret
 * value (ADR-0245 §6). An `unset` is the ABSENCE of an entry: the pointer inherits the tiers below (decision 2).
 *
 * The operator tier is central data, so the scope lives on the `central` connection, which beam aliases to the host's
 * default when the host declares none ({@see \Splicewire\Beam\BeamServiceProvider::registerCentralConnectionAlias()}).
 *
 * @property string $id
 * @property string $section
 * @property array<string, array<string, mixed>>|null $draft
 * @property string|null $head_version
 * @property string|null $published_version
 */
class ConfigOverrideScope extends Model implements Versionable
{
    use HasUuids;
    use VersionableTrait;

    protected $connection = 'central';

    protected $fillable = ['section', 'draft', 'head_version', 'published_version'];

    protected $casts = ['draft' => 'array'];

    public function getTable(): string
    {
        return Beam::table('config_scopes');
    }

    /** The durable alias this record writes into `beam_versions.versionable_type`, registered in beam's morph map. */
    public function getMorphClass(): string
    {
        return 'beam_config_scope';
    }

    /** @return array<string, mixed> */
    public function toVersionSnapshot(): array
    {
        return ['draft' => $this->draft ?? []];
    }

    /** @param  array<string, mixed>  $snapshot */
    public function restoreVersionSnapshot(array $snapshot): void
    {
        $draft = $snapshot['draft'] ?? [];
        $this->draft = is_array($draft) ? $draft : [];
    }

    /** @return array<string, array<string, mixed>> the draft, pointer-ordered */
    public function draftEntries(): array
    {
        $draft = $this->draft ?? [];
        ksort($draft);

        return $draft;
    }
}
