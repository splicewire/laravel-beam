<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Workflows\Display\Concerns\HasStatusChannel;

/**
 * Catches a hardcoded FE Echo channel literal reconstructing beam-workflows' Seam A broadcast-channel
 * convention (`StatusEmitted::channelNameFor()` — a subject's dotted runtime FQCN) instead of reading
 * the server-resolved `status_channel` attribute ({@see HasStatusChannel}).
 * Found live (surgeon-audit-viability ticket 35): `` `status.App.Models.Composition.${id}` `` had gone
 * stale the moment `Composition` relocated out of `App\Models` into its own package — a real,
 * live-broken channel with `beam.workflows.broadcast` enabled, masked only by an unrelated on-action
 * refresh fallback. The FQCN is exactly the kind of thing that drifts routinely in this estate (see
 * this map's whole doc-propagation-gap pattern) — a hardcoded reconstruction of it in TS has no
 * compiler to catch the drift, unlike the PHP side.
 *
 * Scoped to TEMPLATE-LITERAL reconstructions only (`` `status.Some.Dotted.Path.${expr}` ``) — a
 * backstop, not a full parser: every real occurrence found this session was a template literal, and a
 * plain string-concatenation reconstruction is rare enough not to be worth a heavier AST pass.
 */
class StatusChannelLiteralDriftAudit implements DoctorAudit
{
    public const CHECK = 'beam.status-channel-literal-drift';

    protected const PATTERN = '/`status\.((?:[A-Z][A-Za-z0-9_]*\.)+)\$\{/';

    /**
     * @param  list<array{file: string, line: int, dottedPath: string}>  $literals  Every
     *                                                                              `` `status.<Dotted.Path>.${`` template-literal reconstruction found under the scanned directory.
     */
    public function __construct(protected array $literals) {}

    /** The default host wiring: scan the app's product SPA source tree. */
    public static function forApp(): self
    {
        return new self(self::collectLiterals(base_path('ui/src')));
    }

    /** @return list<Finding> */
    public function run(): array
    {
        return $this->check($this->literals);
    }

    /**
     * The pure core — a plain list in, no filesystem. Directly unit-testable.
     *
     * @param  list<array{file: string, line: int, dottedPath: string}>  $literals
     * @return list<Finding>
     */
    public function check(array $literals): array
    {
        $findings = [];

        foreach ($literals as $literal) {
            $fqcn = str_replace('.', '\\', $literal['dottedPath']);

            if (class_exists($fqcn)) {
                continue;
            }

            $findings[] = Finding::fail(self::CHECK, sprintf(
                "%s:%d hardcodes a Seam A status-channel literal for '%s', which does not resolve to a ".
                'real class — either the model relocated (see HasStatusChannel) or this was never a real '.
                'class. Read the server-resolved `statusChannel` field off the DTO instead of '.
                'reconstructing the dotted FQCN client-side.',
                $literal['file'],
                $literal['line'],
                $fqcn,
            ));
        }

        return $findings;
    }

    /**
     * @return list<array{file: string, line: int, dottedPath: string}>
     */
    protected static function collectLiterals(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $rows = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach (explode("\n", $source) as $i => $lineContent) {
                if (! preg_match(self::PATTERN, $lineContent, $matches)) {
                    continue;
                }

                $rows[] = [
                    'file' => $file->getPathname(),
                    'line' => $i + 1,
                    'dottedPath' => rtrim($matches[1], '.'),
                ];
            }
        }

        return $rows;
    }
}
