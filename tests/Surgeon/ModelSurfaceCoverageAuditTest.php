<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\ModelResourceIndex;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\ModelSurfaceCoverageAudit;
use Splicewire\Beam\Surgeon\Support\EloquentModelSource;
use Splicewire\Beam\Tests\Surgeon\Fixtures\SourceOnlyBacking;
use Splicewire\Beam\Tests\Surgeon\Fixtures\WidgetBacking;
use Splicewire\Beam\Tests\TestCase;

/**
 * competitive-landscape ticket 02 — *"this model has no declared surface at all"*, the question
 * `UndeclaredSurfaceAudit` is structurally unable to ask because it computes from the live route
 * table and an unsurfaced model has no route.
 *
 * ## What each test is here to catch, since a coverage audit is easy to write green
 *
 * Every case below was put through a mutation pass — break what it covers, watch exactly the intended
 * case go red — and two came back GREEN the first time and were sharpened rather than accepted. Both
 * corrections are recorded where they belong (the abstract case, in
 * {@see EloquentModelSource::declaresEloquentModel()}; the backing case, on the two tests themselves),
 * because "this test passed" and "this test could fail" are different facts.
 *
 * The four load-bearing cases:
 *
 *  - {@see test_a_backing_object_covers_the_model_it_declares_not_its_own_class()} — the `backing:`
 *    slot is polymorphic, and comparing the SLOT against the model census is the shortcut that stays
 *    green against every ordinary declaration and is wrong for every backing object.
 *  - {@see test_an_empty_population_reads_inconclusive_rather_than_pass()} — this estate's signature
 *    defect. The population is the host's own source plus what it composes, so from a package
 *    testbench this scans NOTHING, and a confident `pass()` over zero files reads exactly like a real
 *    all-clear.
 *  - {@see test_a_file_that_names_no_class_is_counted_and_the_reading_is_declared_a_floor()} — the
 *    census is a source-text census, so a file it cannot name is a hole in the DENOMINATOR. An
 *    instrument that enumerates only its known blind spots reads as thorough exactly where it is
 *    weakest.
 *  - {@see test_a_backed_model_the_census_cannot_see_is_reported_as_under_reach()} — and the KNOWN one,
 *    given a number rather than a docblock: 9 backed models the census could not find, against 137 it
 *    could, measured at `~/Herd/splicewire-app`.
 */
class ModelSurfaceCoverageAuditTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    /** A fresh base path, keyed per-run: the estate's fixed-scratch-dir collision has cost a suite before. */
    private function makeRoot(): string
    {
        $this->root = sys_get_temp_dir().'/beam-model-surface-'.getmypid().'-'.bin2hex(random_bytes(6));

        mkdir($this->root.'/app/Models', 0777, true);
        $this->app->setBasePath($this->root);

        return $this->root;
    }

    private function writeModel(string $shortName, string $body = ''): string
    {
        file_put_contents($this->root.'/app/Models/'.$shortName.'.php', <<<PHP
        <?php

        namespace App\\Models;

        use Illuminate\\Database\\Eloquent\\Model;

        class {$shortName} extends Model
        {
        {$body}
        }
        PHP);

        return 'App\\Models\\'.$shortName;
    }

    /**
     * @param  array<string, string>  $resources  key => backing class-string
     * @param  list<string>|null  $roots
     */
    private function audit(array $resources = [], ?array $roots = null, ?array $excluded = null): ModelSurfaceCoverageAudit
    {
        $registry = new ParticleResourceRegistry;

        foreach ($resources as $key => $backing) {
            // `readOnly` because a backing that is not `WritesRecords` refuses the default affordances
            // at registration — capability is the ceiling. Irrelevant to coverage, required to register.
            $registry->register(new ParticleResource(key: $key, backing: $backing, readOnly: true));
        }

        return new ModelSurfaceCoverageAudit(
            new ModelResourceIndex($registry),
            new EloquentModelSource(new AttributedClassScanner),
            roots: $roots ?? [$this->root.'/app'],
            excludedModels: $excluded,
        );
    }

    /** @return list<string> check keys, in order */
    private function checks(array $findings): array
    {
        return array_map(fn ($f) => $f->check, $findings);
    }

    private function details(array $findings): string
    {
        return implode("\n", array_map(fn ($f) => $f->detail, $findings));
    }

    // ── the finding, and its remedy ─────────────────────────────────────────────────────────────────

    public function test_a_model_with_no_particle_resource_is_reported(): void
    {
        $this->makeRoot();
        $this->writeModel('Widget');

        $findings = $this->audit()->run();

        $this->assertSame(['model-surface.uncovered'], $this->checks($findings));
        $this->assertStringContainsString('App\\Models\\Widget', $findings[0]->detail);
    }

    /**
     * ⚠️ The remedy string the ticket proposed — `make:particle-resource --model=…` alone — DOES NOT
     * RUN: the Data-class name is a required ARGUMENT and `--model` merely overrides the name it
     * would otherwise be derived from (`MakeParticleResourceCommand::handle()`). A finding naming a
     * command that errors is worse than one naming none, so the argument is asserted here.
     */
    public function test_the_remedy_names_a_command_that_actually_runs(): void
    {
        $this->makeRoot();
        $this->writeModel('Widget');

        $this->assertStringContainsString(
            "splicewire:beam:make:particle-resource WidgetData --model='App\\Models\\Widget'",
            $this->details($this->audit()->run()),
        );
    }

    public function test_a_model_backed_by_a_registered_resource_is_not_reported(): void
    {
        $this->makeRoot();
        $model = $this->writeModel('Widget');

        $findings = $this->audit(['widgets' => $model])->run();

        $this->assertSame(['model-surface.coverage'], $this->checks($findings));
        $this->assertTrue($findings[0]->conclusive, 'a real all-clear must be CONCLUSIVE');
    }

    /**
     * The discriminating half of the polymorphic-`backing:` pair, and the reason coverage is read from
     * {@see ModelResourceIndex} rather than from the declaration's own slot.
     *
     * A backing OBJECT names its model through `modelClass()`, so the model is NOT the string in
     * `backing:`. An audit that compared the slot against the model census — the obvious shortcut, and
     * one that stays green against every `backing: Model::class` declaration in the estate — records
     * coverage against `WidgetBacking` and reports `App\Models\Widget` as unsurfaced. This is the case
     * that goes red for it.
     */
    public function test_a_backing_object_covers_the_model_it_declares_not_its_own_class(): void
    {
        $this->makeRoot();
        $this->writeModel('Widget');

        $this->assertSame(
            ['model-surface.coverage'],
            $this->checks($this->audit(['widgets' => WidgetBacking::class])->run()),
        );
    }

    /**
     * The negative counterpart: a {@see ResourceBacking} that is not
     * {@see BacksModel} backs NO single model, so it covers nothing.
     *
     * ⚠️ This case was GREEN under every mutation when first written, and was kept only as a
     * regression guard — the honest reading at the time, and recorded as such. It became
     * discriminating for a reason worth keeping: once `model-surface.underreach` landed, mutating
     * `ModelResourceIndex` to read the `backing:` slot puts `SourceOnlyBacking` into the covered set,
     * where it is a "backed model" no census can find, and this test reds on the extra finding.
     *
     * The transferable half is that a test's discrimination is a property of the SYSTEM, not of the
     * test — it can appear later, and "it passed the mutation pass once" is not a durable verdict.
     */
    public function test_a_source_backed_resource_is_not_coverage_for_any_model(): void
    {
        $this->makeRoot();
        $this->writeModel('Widget');

        $findings = $this->audit(['reports' => SourceOnlyBacking::class])->run();

        $this->assertSame(['model-surface.uncovered'], $this->checks($findings));
    }

    // ── the host's escape hatch ─────────────────────────────────────────────────────────────────────

    public function test_a_host_excluded_model_produces_no_finding(): void
    {
        $this->makeRoot();
        $model = $this->writeModel('Widget');

        $this->assertSame(['model-surface.coverage'], $this->checks($this->audit(excluded: [$model])->run()));
    }

    public function test_the_exclusion_list_is_read_from_host_config_when_none_is_injected(): void
    {
        $this->makeRoot();
        $model = $this->writeModel('Widget');

        config()->set('beam.core.surgeon.model_surface_coverage.exclude_models', [$model]);

        $audit = $this->audit();
        // `excludedModels: null` is the wiring a container-resolved audit gets, so the config read is
        // the only thing that can silence this — not the injected list every other test uses.
        $this->assertSame(['model-surface.coverage'], $this->checks($audit->run()));
    }

    // ── what is deliberately not a model ────────────────────────────────────────────────────────────

    public function test_an_abstract_model_is_not_reported(): void
    {
        $this->makeRoot();
        file_put_contents($this->root.'/app/Models/BaseWidget.php', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        abstract class BaseWidget extends Model
        {
        }
        PHP);

        $this->assertSame(['model-surface.coverage'], $this->checks($this->audit()->run()));
    }

    /**
     * The three shapes the pattern's alternation and modifier group exist for, and which no other case
     * in this file reaches — so each was silently optional until now.
     *
     * Measured 2026-09-04 by mutation: narrowing the alternation to `Model` (dropping `Pivot`), dropping
     * `final\s+` from the modifier group, and dropping the fully-qualified
     * `Illuminate\Database\Eloquent\` prefix each leave the ENTIRE suite green. Two of the three are
     * live — `extends Pivot` has two declarations across the estate's package roots today.
     *
     * The reason a narrowing must be caught HERE and cannot be caught downstream: a model the census
     * stops recognising is not a finding that disappears, it is a model that leaves the DENOMINATOR. The
     * audit's terminal reading for "recognised nothing" is `Finding::pass` — *"All 0 Eloquent model(s)
     * across N scanned file(s)"*, conclusive — which is this estate's signature defect and is
     * indistinguishable from a real all-clear.
     */
    public function test_pivots_final_models_and_fully_qualified_extends_are_all_in_the_population(): void
    {
        $this->makeRoot();

        file_put_contents($this->root.'/app/Models/Link.php', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Relations\Pivot;

        class Link extends Pivot
        {
        }
        PHP);

        file_put_contents($this->root.'/app/Models/Sealed.php', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        final class Sealed extends Model
        {
        }
        PHP);

        file_put_contents($this->root.'/app/Models/Qualified.php', <<<'PHP'
        <?php

        namespace App\Models;

        class Qualified extends \Illuminate\Database\Eloquent\Model
        {
        }
        PHP);

        $detail = $this->details($findings = $this->audit()->run());

        $this->assertSame(
            ['model-surface.uncovered', 'model-surface.uncovered', 'model-surface.uncovered'],
            $this->checks($findings),
            'a model shape the census cannot see leaves the denominator, and the audit then passes',
        );

        foreach (['App\\Models\\Link', 'App\\Models\\Sealed', 'App\\Models\\Qualified'] as $class) {
            $this->assertStringContainsString($class, $detail);
        }
    }

    public function test_a_class_that_is_not_an_eloquent_model_is_not_reported(): void
    {
        $this->makeRoot();
        file_put_contents($this->root.'/app/Models/WidgetPolicy.php', <<<'PHP'
        <?php

        namespace App\Models;

        class WidgetPolicy
        {
        }
        PHP);

        $this->assertSame(['model-surface.coverage'], $this->checks($this->audit()->run()));
    }

    // ── clean vs. did-not-look ──────────────────────────────────────────────────────────────────────

    /**
     * The estate's signature defect, at this audit's own boundary. Run where nothing is on disk, a
     * `pass()` would say "every model here is surfaced" about a population of zero — indistinguishable
     * from a real all-clear. It must say it did not look.
     */
    public function test_an_empty_population_reads_inconclusive_rather_than_pass(): void
    {
        $this->makeRoot();

        $findings = $this->audit(roots: [$this->root.'/does-not-exist'])->run();

        $this->assertSame(['model-surface.coverage'], $this->checks($findings));
        $this->assertFalse($findings[0]->conclusive, 'zero files scanned is "did not look", never "clean"');
        $this->assertStringContainsString('No PHP source was scanned', $findings[0]->detail);
    }

    /**
     * A file the source census believes declares a model but whose tokens name no class — a fragment
     * with no open tag, a file that would not read. It is a hole in the DENOMINATOR: the audit cannot
     * say whether that model is surfaced, so the run is a floor and must not be reported as clean.
     */
    public function test_a_file_that_names_no_class_is_counted_and_the_reading_is_declared_a_floor(): void
    {
        $this->makeRoot();
        // No `<?php`, so `token_get_all()` sees one T_INLINE_HTML blob and names no class, while the
        // source-text census matches the declaration perfectly well.
        file_put_contents(
            $this->root.'/app/Models/Fragment.php',
            "class Fragment extends Model\n{\n}\n",
        );

        $findings = $this->audit()->run();

        $this->assertSame(['model-surface.unnamed'], $this->checks($findings));
        $this->assertFalse($findings[0]->conclusive, 'a hole in the denominator is not a conclusive reading');
        $this->assertStringContainsString('Fragment.php', $findings[0]->detail);
        $this->assertStringContainsString('floor', $findings[0]->detail);
    }

    /**
     * The KNOWN blind spot, made a number instead of a docblock.
     *
     * The census reads `extends Model`/`extends Pivot` from source text, so a model further from `Model`
     * — `App\Models\User` through `Authenticatable`, a token model through Sanctum's — is invisible to
     * it. Every model a registered resource BACKS demonstrably exists, so the backed models the scan did
     * not find are a free lower bound on that gap. Measured at `~/Herd/splicewire-app` on 2026-09-04:
     * 137 models seen, 9 backed models unseen, all of exactly this shape.
     *
     * It causes only under-reporting, never a false finding. That is precisely why it needs saying out
     * loud: "137 models, none missing" and "137 models, and at least 9 more I cannot see" would
     * otherwise print identically.
     */
    public function test_a_backed_model_the_census_cannot_see_is_reported_as_under_reach(): void
    {
        $this->makeRoot();
        $seen = $this->writeModel('Widget');

        // `App\Models\User` is backed but nothing on disk declares it — the stand-in for a model whose
        // parent is `Authenticatable`. `Widget` is there and covered, so the ONLY reading left is the
        // admission that the census is short.
        $findings = $this->audit(['widgets' => $seen, 'users' => 'App\\Models\\User'])->run();

        $this->assertSame(['model-surface.underreach'], $this->checks($findings));
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('App\\Models\\User', $findings[0]->detail);
    }

    /**
     * And the two must compose: an unnamed file does not swallow the real findings, and a real
     * finding does not swallow the admission that the census is incomplete.
     */
    public function test_an_unnamed_file_is_reported_alongside_the_real_findings(): void
    {
        $this->makeRoot();
        $this->writeModel('Widget');
        file_put_contents($this->root.'/app/Models/Fragment.php', "class Fragment extends Model\n{\n}\n");

        $this->assertSame(
            ['model-surface.uncovered', 'model-surface.unnamed'],
            $this->checks($this->audit()->run()),
        );
    }

    /**
     * The pass is a CLAIM, so it has to carry what it measured. A pass that says only "all clear"
     * cannot be told from one taken over two files.
     */
    public function test_the_pass_states_the_population_it_measured(): void
    {
        $this->makeRoot();
        $model = $this->writeModel('Widget');

        $detail = $this->details($this->audit(['widgets' => $model])->run());

        $this->assertStringContainsString('1 Eloquent model', $detail);
    }
}
