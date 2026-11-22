<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Splicewire\Beam\Config\ConfigDataTypes;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\Fixtures\Config\IntersectInner;
use Splicewire\Beam\Tests\Fixtures\Config\IntersectSection;
use Splicewire\Beam\Tests\Fixtures\Config\MailerEntry;
use Splicewire\Beam\Tests\Fixtures\Config\MailersSection;
use Splicewire\Beam\Tests\Fixtures\Config\MergeConflictReversedSection;
use Splicewire\Beam\Tests\Fixtures\Config\MergeConflictSection;
use Splicewire\Beam\Tests\Fixtures\Config\TreeBaseNode;
use Splicewire\Beam\Tests\Fixtures\Config\TreeChildNode;
use Splicewire\Beam\Tests\Fixtures\Config\TreeSection;
use Splicewire\Beam\Tests\Fixtures\Config\UnionEitherSection;
use Splicewire\Beam\Tests\Fixtures\Config\UnionPlainInner;
use Splicewire\Beam\Tests\Fixtures\Config\UnionSecretInner;
use Splicewire\Beam\Tests\Fixtures\Config\UnionSecretSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * config-sections 20, review round 2 (Codex): the remaining type shapes, and the conflicting-merge rule.
 *
 * - An INTERSECTION type (`Data&Interface`) is flattened to its Data member; `self` and `parent` resolve against the
 *   property's declaring class. A secret below either is found, at storage and at the reader.
 * - Union members that disagree on merge keep `extend`; on effect, the heaviest; on `env`, none unless all agree.
 * - The one type reading {@see ConfigDataTypes} serves both walks, and the two DIFFER on purpose: Coverage resolves only a
 *   single Data member (`single()`), the declaration walk every Data member (`nested()`).
 *
 * Every value here is FAKE.
 */
class SecretBelowSymbolicShapesTest extends TestCase
{
    private const OP_SECRETS = ['fake-int-op', 'fake-tree-op-child', 'fake-tree-op-up'];

    private const HOST_SECRETS = ['fake-int-host', 'fake-tree-host-pin', 'fake-tree-host-child', 'fake-tree-host-up'];

    private static string $database;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $connection = ['driver' => 'sqlite', 'database' => self::$database, 'prefix' => '', 'foreign_key_constraints' => false];
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('database.connections.central', $connection);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        $stem = tempnam(sys_get_temp_dir(), 'beam-config-20c-');
        @unlink($stem);
        self::$database = $stem.'.sqlite';
        touch(self::$database);

        parent::setUp();

        foreach (['create_beam_versions_table', 'create_beam_config_overrides_table'] as $stub) {
            (require __DIR__.'/../../database/migrations/shared/'.$stub.'.php.stub')->up();
        }

        config([
            'qa.intersect' => ['creds' => ['label' => 'host label', 'token' => 'fake-int-host']],
            'qa.tree' => ['node' => [
                'name' => 'n', 'pin' => 'fake-tree-host-pin',
                'child' => ['name' => 'c', 'pin' => 'fake-tree-host-child', 'child' => null],
                'up' => ['name' => 'u', 'pin' => 'fake-tree-host-up', 'child' => null],
            ]],
            'qa.merge' => ['y' => ['k' => 'host k']],
            'qa.merge-reversed' => ['y' => ['k' => 'host k']],
        ]);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(__DIR__.'/../Fixtures/Config/host'));
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->forgetInstance(ConfigOverrideStore::class);
        $this->app->forgetInstance(ConfigOverlay::class);
        foreach ([IntersectSection::class, TreeSection::class, MergeConflictSection::class, MergeConflictReversedSection::class] as $section) {
            app(ConfigSectionRegistry::class)->section($section, owner: 'qa/fixture');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$database);
    }

    private function store(): ConfigOverrideStore
    {
        return app(ConfigOverrideStore::class);
    }

    private function stored(): string
    {
        return (string) json_encode([
            DB::connection('central')->table(Beam::table('config_overrides'))->get(),
            DB::connection('central')->table(Beam::table('config_scopes'))->get(),
            DB::connection('central')->table(Beam::table('versions'))->get(),
        ]);
    }

    private function wire(string $root): string
    {
        return (string) json_encode(app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get($root)));
    }

    private function assertNoOperatorSecretStored(): void
    {
        foreach (self::OP_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $this->stored(), "not in an override row, the scope draft or a version ({$secret})");
        }
    }

    // ---- intersection ----

    public function test_a_secret_below_an_intersection_type_is_found(): void
    {
        $this->assertTrue(IntersectSection::keyDeclaration(['creds', 'token'])->secret);
        $this->assertFalse(IntersectSection::keyDeclaration(['creds', 'label'])->secret);
    }

    public function test_a_secret_below_an_intersection_type_is_never_stored_in_plain_nor_served(): void
    {
        $this->assertStringNotContainsString('fake-int-host', $this->wire('qa.intersect'), 'the reader redacts the host value');

        $this->store()->saveDraft('qa.intersect', [['pointer' => '/creds', 'op' => 'set', 'value' => ['label' => 'op label', 'token' => 'fake-int-op']]]);
        $this->store()->publish('qa.intersect');
        app(ConfigOverlay::class)->sync();

        $this->assertNoOperatorSecretStored();
        $this->assertTrue($this->store()->scope('qa.intersect')->draft['/creds/token']['secret'] ?? false);
        $this->assertSame('fake-int-op', config('qa.intersect.creds.token'), 'published, the running configuration holds it');
    }

    // ---- self and parent ----

    public function test_self_and_parent_resolve_against_the_declaring_class(): void
    {
        $this->assertTrue(TreeSection::keyDeclaration(['node', 'pin'])->secret, 'a named type, as before');
        $this->assertTrue(TreeSection::keyDeclaration(['node', 'child', 'pin'])->secret, '?self');
        $this->assertTrue(TreeSection::keyDeclaration(['node', 'child', 'child', 'pin'])->secret, '?self, twice');
        $this->assertTrue(TreeSection::keyDeclaration(['node', 'up', 'pin'])->secret, '?parent');
        $this->assertFalse(TreeSection::keyDeclaration(['node', 'up', 'name'])->secret);
    }

    /**
     * The reader redacts a secret below `?self`/`?parent`. The STORE cannot save such a section at all today: schemastud's
     * schema generator (laravel-data-schemas fcd54d8f9, JsonSchemaGenerator::analyzeType) degrades the unknown class
     * name `self`/`parent` to `{"type": "string"}`, so the section schema rejects the object and every draft is refused
     * before anything is written (a follow-up on schemastud/laravel-data-schemas). Pinned here: the refusal writes no
     * plaintext. When the schema accepts it, the declaration test above already makes the split happen.
     */
    public function test_a_secret_below_self_or_parent_is_never_served_nor_stored_in_plain(): void
    {
        foreach (['fake-tree-host-pin', 'fake-tree-host-child', 'fake-tree-host-up'] as $secret) {
            $this->assertStringNotContainsString($secret, $this->wire('qa.tree'), "the reader redacts {$secret}");
        }

        // Strict (review r2 LOW 5): today the schema refuses. When F2 is fixed this goes red on purpose: then assert the
        // split (`draft['/node/child/pin']['secret']`) instead, which the declaration test above already guarantees.
        try {
            $this->store()->saveDraft('qa.tree', [
                ['pointer' => '/node/child/pin', 'op' => 'set', 'value' => 'fake-tree-op-child'],
                ['pointer' => '/node/up/pin', 'op' => 'set', 'value' => 'fake-tree-op-up'],
            ]);
            $this->fail('F2 is fixed (the schema accepted a ?self section): replace this refusal check with the split assertion');
        } catch (ValidationException $refused) {
            $this->assertStringContainsString('does not validate against its section schema', json_encode($refused->errors(), JSON_UNESCAPED_SLASHES));
        }

        $this->assertNoOperatorSecretStored();
    }

    // ---- conflicting merge, effect and env (Codex HIGH) ----

    public function test_union_members_that_disagree_on_merge_effect_and_env_fold_conservatively_in_either_order(): void
    {
        foreach ([MergeConflictSection::class, MergeConflictReversedSection::class] as $section) {
            $declared = $section::keyDeclaration(['y', 'k']);
            $this->assertSame(
                [ConfigTier::Operator, ConfigMerge::Extend, ConfigEffect::Reload, null],
                [$declared->ceiling, $declared->merge, $declared->effect, $declared->env],
                "{$section}: extend if any member extends, the heavier effect, no env when the members name different ones",
            );
        }
    }

    public function test_the_store_applies_the_folded_extend_merge_in_either_order(): void
    {
        foreach (['qa.merge', 'qa.merge-reversed'] as $root) {
            try {
                $this->store()->saveDraft($root, [['pointer' => '/y/k', 'op' => 'set', 'value' => 'operator k']]);
                $this->fail("{$root}: the operator replaced /y/k, which one member declares extend (add, never replace)");
            } catch (ValidationException $refused) {
                $this->assertStringContainsString('extend key', json_encode($refused->errors()), $root);
            }
        }
    }

    // ---- the one type reading, and how the two walks differ ----

    public function test_the_shared_reading_resolves_each_shape_and_coverage_stays_single_member(): void
    {
        $prop = fn (string $class, string $name): ReflectionProperty => new ReflectionProperty($class, $name);

        $this->assertSame([UnionSecretInner::class], ConfigDataTypes::nested($prop(UnionSecretSection::class, 'optNull')));
        $this->assertSame(UnionSecretInner::class, ConfigDataTypes::single($prop(UnionSecretSection::class, 'optNull')), 'Coverage resolves Nested|Optional|null');

        $this->assertSame([UnionPlainInner::class, UnionSecretInner::class], ConfigDataTypes::nested($prop(UnionEitherSection::class, 'either')));
        $this->assertNull(ConfigDataTypes::single($prop(UnionEitherSection::class, 'either')), 'Coverage keeps a two-Data union ambiguous (unresolved), as before');

        $this->assertSame([IntersectInner::class], ConfigDataTypes::nested($prop(IntersectSection::class, 'creds')), 'an intersection is flattened for the declaration walk');
        $this->assertNull(ConfigDataTypes::single($prop(IntersectSection::class, 'creds')), 'Coverage leaves an intersection unresolved, as before');

        $this->assertSame([TreeBaseNode::class], ConfigDataTypes::nested($prop(TreeBaseNode::class, 'child')), '?self');
        $this->assertSame([TreeBaseNode::class], ConfigDataTypes::nested($prop(TreeChildNode::class, 'up')), '?parent');

        $this->assertSame(MailerEntry::class, ConfigDataTypes::mapEntry($prop(MailersSection::class, 'mailers')));
        $this->assertSame(MailerEntry::class, ConfigDataTypes::listEntry($prop(MailersSection::class, 'relays')));
        $this->assertNull(ConfigDataTypes::mapEntry($prop(MailersSection::class, 'relays')), 'Coverage walks maps only; a list stays one value for it');
    }
}
