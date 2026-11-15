<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Facades\Beam;

/**
 * One write to one pointer of one section: the config override store's OWN append-only revision row (ADR-0245
 * §Open questions 3, settled by config-sections ticket 05).
 *
 * Why the store's own and not `rushing/laravel-versioning`'s: a version's snapshot is plain JSON, and ADR-0245 §6 rules
 * that a secret value is never written to a version. So a secret's encrypted value lives only here, in a row that is
 * never updated or deleted; a version snapshot (and, in ticket 08, a Release member) carries this row's id plus `set`
 * and `setAt`, so a later write cannot change what an earlier version or Release selects.
 *
 * @property string $id
 * @property string $section
 * @property string $pointer
 * @property ConfigOverrideOp $op
 * @property mixed $value
 * @property string|null $encrypted_value the decrypted secret (Laravel's `encrypted` cast)
 * @property int $revision
 * @property string|null $created_by
 */
class ConfigOverrideRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $connection = 'central';

    protected $fillable = ['section', 'pointer', 'op', 'value', 'encrypted_value', 'revision', 'created_by'];

    protected $casts = [
        'op' => ConfigOverrideOp::class,
        'value' => 'json',
        'encrypted_value' => 'encrypted',
        'revision' => 'integer',
    ];

    public function getTable(): string
    {
        return Beam::table('config_overrides');
    }

    /** Append-only: a revision is the address a version or a Release member names, so it never changes. */
    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('A config override revision is never updated.'));
        static::deleting(static fn () => throw new LogicException('A config override revision is never deleted.'));
    }

    /** The value this revision sets: the decrypted secret, or the plain value. Null for an explicit null or an unset. */
    public function settingValue(): mixed
    {
        if ($this->op !== ConfigOverrideOp::Set) {
            return null;
        }

        return $this->encrypted_value !== null ? json_decode($this->encrypted_value, true) : $this->value;
    }
}
