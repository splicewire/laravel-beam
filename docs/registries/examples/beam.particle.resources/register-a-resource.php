<?php

use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ResourceRegistryReport;
use Splicewire\Beam\Tests\Fixtures\Backing\ArmRowData;
use Splicewire\Beam\Tests\Fixtures\Backing\InMemoryArm;

// A package registers a read-only resource over rows it holds in memory (no model), projected through ArmRowData.
$registry = app(ParticleResourceRegistry::class)->register(new ParticleResource(
    key: 'review-items',
    backing: new InMemoryArm,
    data: ArmRowData::class,
    label: 'Review items',
    section: 'work',
    readOnly: true,
), by: 'acme/review');

// The same entry, read three ways: the registry's keys, the row `splicewire:beam:particle:resources` reports,
// and the definition Frame draws its list and detail screens from.
$row = collect(app(ResourceRegistryReport::class)->rows())->firstWhere('key', 'review-items');

return [
    'keys' => array_map('strval', $registry->keys()),
    'report' => $row,
    'frame' => $registry->get('review-items')->toResourceDefinition(),
];
