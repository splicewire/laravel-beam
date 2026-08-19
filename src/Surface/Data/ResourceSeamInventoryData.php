<?php

namespace Splicewire\Beam\Surface\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Surface\SpecSource;

/**
 * The **seam inventory** — everything one OpenAPI document says about the surface it describes,
 * parsed and nothing more.
 *
 * This is the output of {@see SpecSource} and the declared output of the
 * `inventory` operation. It is the half of the mechanism that generalizes to a foreign spec: no
 * Laravel, no Splicewire, no compliance vocabulary appears in it, so a vendor's document yields a
 * real inventory with no application booted.
 */
#[TypeScript]
class ResourceSeamInventoryData extends Data
{
    /**
     * @param  list<string>  $securitySchemes  scheme names the document defines under `components`
     * @param  list<string>|null  $defaultSecurity  document-root security requirement; null ⇒ none declared
     * @param  list<ResourceSeamData>  $seams
     */
    public function __construct(
        public ?string $title = null,
        public ?string $version = null,
        public array $securitySchemes = [],
        public ?array $defaultSecurity = null,
        public array $seams = [],
    ) {}

    public function count(): int
    {
        return count($this->seams);
    }

    /** @return list<string> */
    public function signatures(): array
    {
        return array_map(fn (ResourceSeamData $seam) => $seam->signature(), $this->seams);
    }

    public function seam(string $method, string $path): ?ResourceSeamData
    {
        $signature = strtoupper($method).' '.$path;

        foreach ($this->seams as $seam) {
            if ($seam->signature() === $signature) {
                return $seam;
            }
        }

        return null;
    }

    /**
     * Seams the document does not describe the security of at all — the inventory's own gap list,
     * available before any runtime is in the picture.
     *
     * @return list<ResourceSeamData>
     */
    public function undeclaredSecurity(): array
    {
        return array_values(array_filter($this->seams, fn (ResourceSeamData $seam) => $seam->security === null));
    }
}
