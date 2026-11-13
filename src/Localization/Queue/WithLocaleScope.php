<?php

namespace Splicewire\Beam\Localization\Queue;

use Closure;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\LocaleScope;

/**
 * The one opt-in job middleware (localization 06, ruling 03 §3): a family job declares it, and the job runs inside a
 * {@see LocaleScope} with its OWN context, never the worker's or the dispatching request's residue.
 *
 *     public function middleware(): array
 *     {
 *         return [new WithLocaleScope($this->locale, $this->realm)];
 *     }
 *
 * A null locale enters the supported application default; a null realm enters the base rung. Beam installs no host-wide
 * Bus pipeline: a job that does not declare this is not claimed localized.
 */
final class WithLocaleScope
{
    public function __construct(
        public readonly ?string $locale = null,
        public readonly ?string $realm = null,
    ) {}

    public function handle(mixed $job, Closure $next): mixed
    {
        $locale = Locales::supportedTag($this->locale) ?? Locales::applicationDefault();

        return LocaleScope::runIn($locale, $this->realm, fn () => $next($job));
    }
}
