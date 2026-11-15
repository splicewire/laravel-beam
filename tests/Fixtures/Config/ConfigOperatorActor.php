<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Illuminate\Foundation\Auth\User;

/** Fixture: the signed-in actor the config-override ops are gated against (config-sections ticket 05). */
class ConfigOperatorActor extends User
{
    protected $table = 'config_read_actors';

    protected $guarded = [];

    public $timestamps = false;
}
