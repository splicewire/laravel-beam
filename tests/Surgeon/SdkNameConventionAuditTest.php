<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Surgeon\Operation\FixableFinding;
use Splicewire\Beam\Codegen\SdkNaming;
use Splicewire\Beam\Surgeon\SdkNameConventionAudit;

/**
 * The "convention drives SDK names" pivot proof: {@see SdkNameConventionAudit}'s pure core measures each
 * existing `Splicewire\Client\Requests\<Domain>\<Class>` name against the {@see SdkNaming}
 * convention (resolved via the class's endpoint→route→@group), nominating a surgeon `relocation` where
 * the current name diverges.
 *
 * Pure unit test over in-memory fixtures — no splicewire/laravel-connector package, no route facade, no spec parse.
 */
class SdkNameConventionAuditTest extends TestCase
{
    private function audit(): SdkNameConventionAudit
    {
        return new SdkNameConventionAudit('/nonexistent', '/nonexistent'); // dirs unused by suggestFor()
    }

    public function test_a_divergent_class_nominates_a_relocation_to_the_convention_fqn(): void
    {
        // `TriggerRender` addresses `POST …/compositions/{id}` — an ITEM, so the convention
        // (POST to an item → Update<Resource>) is `UpdateComposition`. The current hand-picked name
        // diverges → nominate the rename.
        $sdk = [[
            'fqn' => 'Splicewire\\Client\\Requests\\Compositions\\TriggerRender',
            'domain' => 'Compositions',
            'class' => 'TriggerRender',
            'method' => 'POST',
            'literal' => '/api/v1/splice/compositions/{$this->id}',
        ]];
        $routes = ['api/v1/splice/compositions/{id}'];
        $tags = ['POST api/v1/splice/compositions/{param}' => 'Compositions'];

        $findings = $this->audit()->suggestFor($sdk, $routes, $tags);

        $this->assertCount(1, $findings);
        /** @var FixableFinding $finding */
        $finding = $findings[0];

        $this->assertTrue($finding->isFixable());
        $this->assertSame('warn', $finding->finding->status->value);
        $this->assertSame('sdk.name-nonconventional', $finding->finding->check);

        $this->assertNotNull($finding->suggestion);
        $this->assertSame('relocation', $finding->suggestion->kind);
        $this->assertSame(
            'Splicewire\\Client\\Requests\\Compositions\\TriggerRender',
            $finding->suggestion->payload['oldFqn'],
        );
        $this->assertSame(
            'Splicewire\\Client\\Requests\\Compositions\\UpdateComposition',
            $finding->suggestion->payload['newFqn'],
        );
    }

    public function test_a_conventional_class_yields_no_finding(): void
    {
        // `CreateIdea` for `POST …/studio/ideas` IS the convention — no finding.
        $sdk = [[
            'fqn' => 'Splicewire\\Client\\Requests\\Studio\\CreateIdea',
            'domain' => 'Studio',
            'class' => 'CreateIdea',
            'method' => 'POST',
            'literal' => '/api/v1/studio/ideas',
        ]];
        $routes = ['api/v1/studio/ideas'];
        $tags = ['POST api/v1/studio/ideas' => 'Studio'];

        $this->assertSame([], $this->audit()->suggestFor($sdk, $routes, $tags));
    }

    public function test_an_unresolvable_class_is_a_null_suggestion_advisory(): void
    {
        // The endpoint matches no app route (a known drift case) — convention cannot be resolved.
        $sdk = [[
            'fqn' => 'Splicewire\\Client\\Requests\\Models\\ListModels',
            'domain' => 'Models',
            'class' => 'ListModels',
            'method' => 'GET',
            'literal' => '/api/v1/models',
        ]];
        $routes = ['api/v1/splice/models']; // route moved / renamed — no shape match
        $tags = ['GET api/v1/splice/models' => 'Models'];

        $findings = $this->audit()->suggestFor($sdk, $routes, $tags);

        $this->assertCount(1, $findings);
        $this->assertNull($findings[0]->suggestion);
        $this->assertFalse($findings[0]->isFixable());
        $this->assertSame('warn', $findings[0]->finding->status->value);
    }

    public function test_tags_from_spec_keys_by_verb_and_route_shape(): void
    {
        $spec = [
            'paths' => [
                '/api/v1/studio/ideas' => [
                    'post' => ['tags' => ['Studio']],
                    'get' => ['tags' => ['Studio']],
                ],
                '/api/v1/splice/compositions/{id}' => [
                    'post' => ['tags' => ['Compositions']],
                ],
            ],
        ];

        $map = $this->audit()->tagsFromSpec($spec);

        $this->assertSame('Studio', $map['POST api/v1/studio/ideas']);
        $this->assertSame('Compositions', $map['POST api/v1/splice/compositions/{param}']);
    }

    public function test_parse_sdk_class_reconstructs_verb_domain_and_literal(): void
    {
        $source = <<<'PHP'
        <?php
        namespace Splicewire\Client\Requests\Media;
        use Saloon\Enums\Method;
        class GetAmediaItemsContents extends Request
        {
            protected Method $method = Method::GET;
            public function resolveEndpoint(): string
            {
                return "/api/v1/media/{$this->id}/content";
            }
        }
        PHP;

        $row = $this->audit()->parseSdkClass($source);

        $this->assertNotNull($row);
        $this->assertSame('Splicewire\\Client\\Requests\\Media\\GetAmediaItemsContents', $row['fqn']);
        $this->assertSame('Media', $row['domain']);
        $this->assertSame('GET', $row['method']);
        $this->assertSame('/api/v1/media/{$this->id}/content', $row['literal']);
    }
}
