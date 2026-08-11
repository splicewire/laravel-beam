<?php

namespace Splicewire\Beam\Tests\Topology;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Rushing\Graphine\Drivers\RelationalDriverFactory;
use Rushing\PackageTopology\Contract\RuleKind;
use Rushing\PackageTopology\Contract\TopologyViolation;
use Rushing\PackageTopology\Evaluator\TopologyEvaluator;
use Rushing\PackageTopology\Sources\ComposerManifestGraphSource;
use Rushing\PackageTopology\Sources\DeclaredContractSource;
use Rushing\PackageTopology\Tests\Feature\DeclaredManifestTest;

/**
 * Marketplace build ticket 01: beam's own `extra.package-topology.policy.noRequire`
 * array (declared in THIS package's own composer.json, the "actual live
 * enforcement mechanism", not a central policy file) now carries two estate-wide
 * rules: the pre-existing `beam ↛ satellite` (R2) and the new `beam ↛ tower` (R3).
 * Both are read straight off this package's own composer.json — never
 * hand-copied — and proven enforced (not just declared) by planting a require
 * edge for each forbidden target inside a throwaway vendor tree and asserting the
 * declared-manifest evaluator catches it. Mirrors the fixture pattern
 * `rushing/php-package-topology`'s own test suite uses
 * ({@see DeclaredManifestTest}) and
 * `laravel-beam-market`'s sibling `ForbiddenRequireTest`.
 */
class PolicyNoRequireTest extends BaseTestCase
{
    private function beamsOwnDeclaration(): array
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

        return $manifest['extra']['package-topology'];
    }

    private function forbiddenVendorTree(string $forbiddenRequire): string
    {
        $root = sys_get_temp_dir().'/beam-policy-topology-'.substr(md5($forbiddenRequire), 0, 12);

        $packages = [
            'splicewire/laravel-beam' => [
                'require' => [$forbiddenRequire => '*'],
                'extra' => ['package-topology' => $this->beamsOwnDeclaration()],
            ],
            $forbiddenRequire => [],
        ];

        foreach ($packages as $name => $spec) {
            $dir = "{$root}/{$name}";
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $manifestOut = ['name' => $name];
            if (! empty($spec['require'])) {
                $manifestOut['require'] = (object) $spec['require'];
            }
            if (! empty($spec['extra'])) {
                $manifestOut['extra'] = $spec['extra'];
            }
            file_put_contents("{$dir}/composer.json", json_encode($manifestOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $root;
    }

    /**
     * @return list<TopologyViolation>
     */
    private function evaluate(string $vendorPath): array
    {
        $contract = (new DeclaredContractSource($vendorPath))->contract();
        $store = RelationalDriverFactory::make(
            new ComposerManifestGraphSource($vendorPath, ['rushing/*', 'splicewire/*', 'schemastud/*']),
            'beam-policy-forbidden-require',
        );

        return (new TopologyEvaluator)->evaluate($contract, $store, $vendorPath);
    }

    public function test_it_declares_both_the_satellite_and_tower_norequire_rules(): void
    {
        $rules = $this->beamsOwnDeclaration()['policy']['noRequire'];
        $targets = array_column($rules, 'toPrefix');

        $this->assertContains('splicewire/laravel-satellite', $targets);
        $this->assertContains('splicewire/tower', $targets);
    }

    public function test_a_beam_to_satellite_require_is_mechanically_rejected(): void
    {
        $this->assertForbiddenEdgeCaught('splicewire/laravel-satellite');
    }

    public function test_a_beam_to_tower_require_is_mechanically_rejected(): void
    {
        $this->assertForbiddenEdgeCaught('splicewire/tower');
    }

    /**
     * Beam's own declaration also carries `mustRequire: ["schemastud/laravel-frame"]`
     * (R1), which the throwaway fixture doesn't satisfy — so the evaluator
     * legitimately reports that finding too. Isolate the ForbiddenDirectEdge
     * finding for the planted target specifically, rather than assuming it's the
     * only (or first) violation in the list.
     */
    private function assertForbiddenEdgeCaught(string $forbiddenRequire): void
    {
        $violations = $this->evaluate($this->forbiddenVendorTree($forbiddenRequire));

        $forbidden = array_values(array_filter(
            $violations,
            static fn ($v) => $v->kind === RuleKind::ForbiddenDirectEdge && str_contains($v->message(), $forbiddenRequire),
        ));

        $this->assertNotSame([], $forbidden, "Expected a ForbiddenDirectEdge violation mentioning {$forbiddenRequire}");
    }
}
