<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * Ticket 37 (surgeon-audit-viability): a `#[TypeScript]`-attributed class never intentionally
 * authors a property typed `unknown`/`unknown[]` — so that residue in `generated.d.ts` reliably
 * means Spatie's `TypeScriptTransformer` silently gave up resolving a docblock element type
 * (`@var SomeClass[]`), rather than erroring. The traced root cause: a bare, unqualified class
 * name in a `@var` hint that lives outside the declaring file's own namespace/imports — most
 * often a lower-tier DTO (e.g. `laravel-beam-workflows`) legitimately unable to `use`-import a
 * higher-tier class (e.g. `splicewire/tower`'s `GuardCatalogEntryData`) per the estate's declared
 * tier topology. The fix is a fully-qualified docblock reference (`@var \Fully\Qualified\Class[]`)
 * — pure comment text, no `use` import, no composer dependency, doesn't cross the topology
 * boundary — which this audit doesn't prescribe, it only surfaces the residue.
 *
 * Scoped to the single artifact both the app's own consumers and every `@splicewire/_resources`
 * bundle slice ultimately derive from (`TransformTypesStage` slices bundle files FROM this file
 * by class name, per `config/pipelines/resources.php`) — fixing it here fixes every downstream
 * projection without a separate check per bundle file.
 *
 * Plain {@see DoctorAudit}, no `SuggestsOperations` — the fix is a judgment call about which class
 * the docblock should reference, not a mechanical rewrite.
 */
class TypeScriptUnknownResolutionAudit implements DoctorAudit
{
    public const CHECK = 'typescript.unknown-resolution';

    public function __construct(
        protected ?string $dtsContent,
    ) {}

    public static function forApp(): self
    {
        $path = base_path('ui/src/types/generated.d.ts');

        return new self(is_readable($path) ? (string) file_get_contents($path) : null);
    }

    /** @return list<Finding> */
    public function run(): array
    {
        return $this->check($this->dtsContent);
    }

    /**
     * The pure core — a plain string in, no filesystem. Line-scans for `export type X = {` to track
     * the enclosing declaration, then flags any property inside it typed bare `unknown`/`unknown[]`.
     * Proportionate, not a full TS parser: `generated.d.ts` emits every declaration flush-left with
     * one property per line (confirmed against live output), so this needs no brace-depth tracking.
     *
     * @return list<Finding>
     */
    public function check(?string $dtsContent): array
    {
        if ($dtsContent === null) {
            return [];
        }

        $findings = [];
        $currentClass = null;

        foreach (explode("\n", $dtsContent) as $line) {
            if (preg_match('/^export type (\w+)\s*=\s*\{/', $line, $classMatch)) {
                $currentClass = $classMatch[1];

                continue;
            }

            if ($currentClass === null) {
                continue;
            }

            if (preg_match('/^\};/', $line)) {
                $currentClass = null;

                continue;
            }

            if (preg_match('/^(\w+)\??:\s*(unknown\[\]|unknown)\s*[,;]/', $line, $propertyMatch)) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    "%s.%s resolves as '%s' in generated.d.ts — likely a #[TypeScript]-attributed class's ".
                    'docblock (`@var SomeClass[]`) referencing a class outside its own namespace/imports '.
                    'without a fully-qualified name; Spatie\'s TypeScriptTransformer silently degrades an '.
                    'unresolvable element type to `unknown` instead of erroring.',
                    $currentClass,
                    $propertyMatch[1],
                    $propertyMatch[2],
                ));
            }
        }

        return $findings;
    }
}
