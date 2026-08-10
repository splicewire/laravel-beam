<?php

namespace Splicewire\Beam\Surgeon;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * The PHP side of the SDK-hook migration scan (client-sdk-codegen #05 rollout;
 * `.scratch/surgeon-audit-viability/tickets/08-...` in splicewire-app). Lossless TS/TSX parsing needs
 * the JS toolchain (recast + babel), so the scan lives in the Node `@splicewire/beam-ux` package's
 * `beam-ux-sdk-hook-migration` CLI, shelled via Symfony Process — the same split as `PuckBridge`.
 *
 * **Degrade-not-fabricate:** {@see available()} reports whether the Node interpreter + the bridge
 * script are both present. {@see SdkHookMigrationAudit} treats an unavailable bridge as zero findings,
 * not a failure — a host without the JS toolchain installed simply doesn't get this audit's checks.
 */
class SdkHookMigrationBridge
{
    public function __construct(
        protected ?string $script = null,
        protected string $node = 'node',
        protected int $timeout = 60,
    ) {}

    /** Is the Node bridge usable — the script path configured AND the file present on disk? */
    public function available(): bool
    {
        return $this->script !== null && $this->script !== '' && is_file($this->script);
    }

    /**
     * Run the scan and return its decoded report.
     *
     * `$routesManifestPath` — optional, `ui/src/generated/routes.ts` (the `name → path-template` map
     * the same `splicewire:beam:generate:client` command emits alongside the hooks). Wiring this
     * through additionally recognizes call sites that hand-write a plain string/template-literal path
     * instead of `route('name')` (v2) — pure JS-side manifest consumption on the Node side, this is
     * just passing a computed path string through, the same category as `$hooksDir`/`$apiRoot`.
     *
     * @param  list<string>  $excludeDirNames
     * @return array{candidates: list<array<string, mixed>>, exceptions: list<array<string, mixed>>}
     */
    public function scan(string $hooksDir, string $apiRoot, string $importerRoot, array $excludeDirNames = [], ?string $routesManifestPath = null): array
    {
        if (! $this->available()) {
            throw new RuntimeException('The beam-ux sdk-hook-migration bridge is unavailable (config beam.client.surgeon.sdk_hook_migration.bridge.script).');
        }

        $config = (string) json_encode([
            'hooksDir' => $hooksDir,
            'apiRoot' => $apiRoot,
            'importerRoot' => $importerRoot,
            'excludeDirNames' => $excludeDirNames,
            'routesManifestPath' => $routesManifestPath,
        ]);

        $process = new Process([$this->node, $this->script, 'scan'], null, null, $config, $this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (! is_array($decoded)) {
            return ['candidates' => [], 'exceptions' => []];
        }

        return [
            'candidates' => $decoded['candidates'] ?? [],
            'exceptions' => $decoded['exceptions'] ?? [],
        ];
    }
}
