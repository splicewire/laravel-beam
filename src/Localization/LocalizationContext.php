<?php

namespace Splicewire\Beam\Localization;

/**
 * The explicit realm a {@see LocaleScope} entered, if any (localization 06). Bound `scoped()`, so it never outlives a
 * request or a job under Octane or a queue worker. Beam keeps no ambient realm otherwise: without an entered scope the
 * translator reads the current request's route, and off HTTP the base answers.
 */
final class LocalizationContext
{
    private bool $realmSet = false;

    private ?string $realm = null;

    private int $depth = 0;

    private ?string $base = null;

    /**
     * The application locale as it stood before the OUTERMOST open scope, or null outside any scope. `App::setLocale()`
     * also rewrites `config('app.locale')`, so inside a scope that key holds the scope's locale; the application default
     * a job or delivery falls back to must be this one, never the dispatching scope's residue.
     */
    public function base(): ?string
    {
        return $this->base;
    }

    public function opened(string $previousLocale): void
    {
        if ($this->depth++ === 0) {
            $this->base = $previousLocale;
        }
    }

    public function closed(): void
    {
        if (--$this->depth <= 0) {
            $this->depth = 0;
            $this->base = null;
        }
    }

    /** @return array{0: bool, 1: ?string} whether a scope set the realm, and which (null: the base) */
    public function realm(): array
    {
        return [$this->realmSet, $this->realm];
    }

    /** @param  array{0: bool, 1: ?string}  $state */
    public function restore(array $state): void
    {
        [$this->realmSet, $this->realm] = $state;
    }

    public function enter(?string $realm): void
    {
        $this->realmSet = true;
        $this->realm = $realm;
    }
}
