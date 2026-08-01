<?php

namespace Splicewire\Beam\Tests\Schema\Fakes;

use ReflectionClass;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaId;

/**
 * A beam test-fake {@see SchemaTargetResolver} standing in — with NO behaviour
 * change — for the app's `App\Schema\TargetSchemaResolver` the ported read-at-version
 * tests once passed into the adapter. It keeps those tests free of any `App\` class.
 *
 * The policy it encodes (mirroring the host's system-schema branch):
 *
 *  - CURRENT (`$version === null`) — the target is PROJECTED from the live Data
 *    class via the generator (the historical behaviour).
 *  - A PINNED PRIOR version — you cannot project an old shape from today's class, so
 *    the frozen artifact is RESOLVED from the registry by the class's stem at that
 *    version. Asking for the version the live class already projects is just current.
 *
 * A bare stem (non-class record type) resolves straight from the registry at the
 * chosen (or latest) version — enough for the seam tests, which only ever pass Data
 * class-strings.
 */
class FakeSchemaTargetResolver implements SchemaTargetResolver
{
    public function __construct(
        private JsonSchemaGenerator $generator,
        private SchemaRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function targetFor(string $recordType, ?int $version = null): array
    {
        if (class_exists($recordType)) {
            return $this->classTarget($recordType, $version);
        }

        // A bare-stem record type IS the stem — resolve it straight from the registry
        // (latest when no version is pinned). No extra `.stem()` strip: the caller
        // already passes the full `<base>/<name>` stem, and stripping again would
        // wrongly drop the last name segment.
        return $this->registryTarget($recordType, $version);
    }

    /**
     * @param  class-string  $dataClass
     * @return array<string, mixed>
     */
    private function classTarget(string $dataClass, ?int $version): array
    {
        $projected = $this->generator->generate(new ReflectionClass($dataClass));

        if ($version === null) {
            return $projected;
        }

        $id = $projected['$id'] ?? null;
        if (! is_string($id) || $id === '') {
            return $projected;
        }

        $current = SchemaId::from($id);

        // Asking for the version the live class already projects is just "current".
        if ($current->version() === $version) {
            return $projected;
        }

        return $this->registryTarget($current->stem(), $version);
    }

    /**
     * The registered artifact for `$stem` at `$version` (or latest when null); an
     * empty array when nothing is registered — a total, non-throwing "no target".
     *
     * @return array<string, mixed>
     */
    private function registryTarget(string $stem, ?int $version): array
    {
        $resolved = $version ?? $this->latestVersion($stem);
        if ($resolved === null) {
            return [];
        }

        return $this->registry->get($stem.'/'.$resolved) ?? [];
    }

    /**
     * The highest registered version integer for `$stem`, or null when none.
     */
    private function latestVersion(string $stem): ?int
    {
        if (! $this->registry instanceof EnumeratesVersions) {
            return null;
        }

        $versions = $this->registry->versionsFor($stem);

        return $versions === [] ? null : max($versions);
    }
}
