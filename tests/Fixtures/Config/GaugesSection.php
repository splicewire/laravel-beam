<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/** A map-shaped, host-only fixture section, keyed by dotted names like `app.meters`. */
#[ConfigRoot(root: 'fleet.gauges', title: 'Gauges', description: 'Named cost dimensions.', entries: GaugeEntry::class)]
class GaugesSection extends ConfigSection {}
