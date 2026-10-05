<?php

namespace Splicewire\Beam\Brand;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The install's brand: M8 of the ux-walkthrough SPEC (IA-14), `{name, logo, titleTemplate, legalEntity, passkeyCopy}`.
 *
 * Declared once here. Hosts CARRY it: Inertia shares it as `brand`, and the SPA receives it in runtime config. Both
 * render these fields and never spell a brand themselves. It is read through {@see Brand::for()} (a resolver), never
 * as raw config, so a per-subtree brand (docs-walkthrough C-1, `beam.brands`) can land later without touching readers.
 *
 * The defaults name no company: an install that declares no legal entity must not claim one. The self-host processor
 * wording is the owner's call (tower-is-splicewire Q8).
 */
#[TypeScript]
final class BrandData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $logo,
        public readonly ?string $titleTemplate,
        public readonly ?string $legalEntity,
        public readonly string $passkeyCopy,
    ) {}

    /**
     * A brand from a `beam.brand`-shaped array: an unset key falls back to `config('app.name')` or a neutral sentence.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $name = self::filled($config['name'] ?? null) ?? (string) config('app.name');

        return new self(
            name: $name,
            logo: self::filled($config['logo'] ?? null),
            titleTemplate: self::filled($config['title_template'] ?? null),
            legalEntity: self::filled($config['legal_entity'] ?? null),
            passkeyCopy: self::filled($config['passkey_copy'] ?? null)
                ?? "Passkeys secure your {$name} login. They're tied to your account.",
        );
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
