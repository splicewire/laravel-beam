<?php

use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ContextBlockFacts;
use Schemastud\Frame\Registry\ContextContributorRegistry;
use Schemastud\Frame\Registry\ContextManifest;
use Schemastud\Frame\Registry\DeferredWidgetLabel;
use Schemastud\Frame\Registry\WidgetContextProjector;
use Splicewire\Beam\Localization\BeamWidgetLabel;
use Splicewire\Beam\Particle\Contribution\ContributionContextNodes;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Tests\Fixtures\Localization\RouteRowData;
use Splicewire\Beam\Tests\TestCase;

/**
 * frame's provider is loaded for THIS file only (the per-class pattern of ModelLessReadGateTest:54 and
 * HookResponseDeclarationsTest:97): what is pinned here is frame's binding of the projector, and beam's base TestCase
 * deliberately boots without frame (tests/TestCase.php:53-58, ADR-0082; BeamBootTest asserts it). Without it the
 * container AUTOWIRES WidgetContextProjector with beam's singleton BeamWidgetLabel, which is not the path a host runs.
 */
class ProjectorLabelTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }
}

// declared ABOVE uses(): run alone, the parent TestCase is not loaded yet, so PHP does not hoist a subclass declared below
// and Pest's uses() would not find it (r7d: TestCaseClassOrTraitNotFound)
uses(ProjectorLabelTestCase::class);

/*
 * frame-foundation 89 (land step 3: lands with beam's lock bump to frame's 89 commit) — the context projector is handed in.
 * Beam binds frame's label hook (localization 10). These pin, by reflection, that the projector frame's provider builds
 * holds a DEFERRED handle on beam's hook (review MED 1), that beam's consumers hold THAT projector, and, by OUTPUT, that a
 * contributed list column's label is translated through the container's manifest (review item 3: what the ticket protects).
 */

function ff89ProjectorLabelHeld(object $consumer): WidgetContextProjector
{
    return (new ReflectionProperty($consumer, 'projector'))->getValue($consumer);
}

function ff89ProjectorLabelHook(WidgetContextProjector $projector): mixed
{
    $labels = (new ReflectionProperty($projector, 'labels'))->getValue($projector);

    return $labels instanceof DeferredWidgetLabel ? $labels->resolve() : $labels;
}

it('builds the bound projector with a deferred handle on beam\'s label hook', function (): void {
    $labels = (new ReflectionProperty($p = app(WidgetContextProjector::class), 'labels'))->getValue($p);

    expect($labels)->toBeInstanceOf(DeferredWidgetLabel::class)
        ->and(ff89ProjectorLabelHook($p))->toBeInstanceOf(BeamWidgetLabel::class);
});

it('gives the container-resolved ContextManifest a projector carrying beam\'s label hook', function (): void {
    expect(ff89ProjectorLabelHook(ff89ProjectorLabelHeld(app(ContextManifest::class))))->toBeInstanceOf(BeamWidgetLabel::class);
});

it('gives beam\'s contribution registry the container\'s projector', function (): void {
    $bound = new WidgetContextProjector(app(BeamWidgetLabel::class));
    app()->instance(WidgetContextProjector::class, $bound);
    app()->forgetInstance(ResourceContributionRegistry::class);

    expect(ff89ProjectorLabelHeld(app(ResourceContributionRegistry::class)))->toBe($bound);
});

it('hands the container\'s projector to the contribution nodes beam registers as a context contributor', function (): void {
    $bound = new WidgetContextProjector(app(BeamWidgetLabel::class));
    app()->instance(WidgetContextProjector::class, $bound);

    // frame-foundation 93: beam registers `beam.contributions` in frame's `frame.context-contributors` registry; its
    // factory resolves beam's scoped ContributionContextNodes binding when called (no port to walk any more)
    $factory = app(ContextContributorRegistry::class)->unfiltered()->resolve(ContextContributorRegistry::ROOT.'.beam.contributions');
    $node = $factory();

    expect($node)->toBeInstanceOf(ContributionContextNodes::class)
        ->and(ff89ProjectorLabelHeld($node))->toBe($bound);
});

it('translates a CONTRIBUTED list column\'s label in the container manifest (the label path the ticket protects)', function (): void {
    // the same lang-line shape as ColumnLabelTest: a column with no label of its own takes its property's translated title
    $root = sys_get_temp_dir().'/beam-ff89-labels-'.getmypid().'-'.bin2hex(random_bytes(4));
    @mkdir("{$root}/vendor/beam/es", 0777, true);
    file_put_contents("{$root}/vendor/beam/es/data.php", '<?php return '.var_export([
        'Splicewire/Beam/Tests/Fixtures/Localization/RouteRowData' => ['properties' => ['target' => ['title' => 'Destino']]],
    ], true).';');
    app()->useLangPath($root);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
    app()->setLocale('es');

    app(ResourceContributionRegistry::class)->register(new ResourceContribution(
        key: 'ff89-labels',
        as: 'route',
        data: RouteRowData::class,
        value: fn () => null,
    ));

    $byNode = app(ContextManifest::class)->forResource(RouteRowData::class, new ContextBlockFacts(key: 'ff89-labels'))['byNode'];

    expect($byNode['route.target']['list-column']['label'] ?? null)->toBe('Destino');
});
