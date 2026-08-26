<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in for whatever a host lets a hook narrow to (ticket 12 §1's nullable subject morph).
 *
 * A fixture rather than a real model on purpose: the pruner listens to `eloquent.deleted: *` and must
 * work for a class beam has never heard of, which is the whole reason it is a wildcard listener and
 * not a per-model observer. Using a beam model here would test the easy half.
 */
class HookSubjectFixture extends Model
{
    protected $table = 'hook_subjects';

    public $timestamps = false;

    protected $guarded = [];
}
