<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Facades\Beam;

/**
 * An operator lock on one pointer of one section (config-sections ticket 07; PRD "Operator locks are central data"). The
 * operator narrows the package's declared ceiling on this install: the locked pointer, and every pointer below it,
 * refuses a tenant-tier write with {@see $reason} and is shown locked in the tenant's row. A lock never widens a ceiling
 * and never touches the operator's own tier.
 *
 * @property string $id
 * @property string $section
 * @property string $pointer
 * @property string $reason
 * @property string|null $locked_by
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ConfigOverrideLock extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = ['section', 'pointer', 'reason', 'locked_by'];

    public function getTable(): string
    {
        return Beam::table('config_locks');
    }

    /** Whether this lock covers `$pointer`: the locked pointer itself, or one below it. */
    public function covers(string $pointer): bool
    {
        return $pointer === $this->pointer || str_starts_with($pointer, rtrim($this->pointer, '/').'/');
    }
}
