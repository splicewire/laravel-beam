<?php

namespace Splicewire\Beam\Localization;

/**
 * Who owns a declaration's text, and so which translator namespace its derived key names (localization ruling 01 §3).
 *
 * - `host`: the declaration is the host application's; its derived keys carry no namespace.
 * - `package`: a package owns it; `$namespace` is that package's translator namespace.
 * - `refused`: the owning package's namespace is contested (two installed packages shorten to it and neither declares
 *   its own), so the declaration gets NO derived key and renders its declared literal.
 */
final readonly class TextOwner
{
    private function __construct(
        public string $kind,
        public ?string $namespace,
        public ?string $package,
    ) {}

    public static function host(): self
    {
        return new self('host', null, null);
    }

    public static function package(string $package, string $namespace): self
    {
        return new self('package', $namespace, $package);
    }

    public static function refused(string $package): self
    {
        return new self('refused', null, $package);
    }

    public function isRefused(): bool
    {
        return $this->kind === 'refused';
    }

    /** The prefix a derived key starts with: `beam-ux::`, or nothing for the host. Null when refused. */
    public function keyPrefix(): ?string
    {
        return match ($this->kind) {
            'host' => '',
            'package' => $this->namespace.'::',
            default => null,
        };
    }
}
