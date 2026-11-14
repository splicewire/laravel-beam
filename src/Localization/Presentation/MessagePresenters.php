<?php

namespace Splicewire\Beam\Localization\Presentation;

use Carbon\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Splicewire\Beam\Data\MessageReferenceData;
use Splicewire\Beam\Localization\BeamTranslator;

/**
 * The ONE place a persisted or broadcast product line is rendered for its reader (localization 37; RULING-04 §4–§5).
 *
 * - **Adapters** (`adapter()`): the per-key registry, contributed by each owning package. A key declares its
 *   parameters and their units; only scalars are accepted, and units format in the READER's locale:
 *   - `string`, `int`, `float`, `bool`: as given;
 *   - `money:USD` (any ISO code): a numeric amount formatted in that currency, NEVER today's configured currency;
 *   - `date`: an ISO date-time formatted under the host's timezone policy.
 *   A leading `?` makes a parameter optional. Parameters a key does not declare are refused.
 * - **Identity presenters** (`identity()`): per family (`status`, `activity`, …), a callable that maps a row's KNOWN
 *   structured identity (ref/state, log_name/event, kind, …) to a reference, or null. This is how existing rows
 *   localize with no back-fill and no prose parsing.
 * - **Rendering** happens in the caller's current scope (03/06/36): a request's resolved locale and realm, a delivery's,
 *   or a job's. It goes through 07's ladder, and an explicit `count` selects plural forms by the answering locale's rules.
 * - **Failure:** a missing key or invalid reference THROWS under tests. Production renders the reference's own
 *   `default` with its parameters, plus a log diagnostic. It never shows a raw key or executable data.
 *
 * Nothing here exposes `properties.diagnostic` (storage and log only unless a gate is proved), and nothing populates
 * the PHP client catalog.
 */
final class MessagePresenters
{
    private const SCALARS = ['string', 'int', 'float', 'bool'];

    /** @var array<string, array{schema: array<string, string>, format: ?callable}> */
    private array $adapters = [];

    /** @var array<string, list<callable(array<string, mixed>): ?MessageReferenceData>> */
    private array $identities = [];

    /**
     * Register (or replace) a key's adapter.
     *
     * @param  array<string, string>  $schema  parameter => type (`string|int|float|bool|money:<ISO>|date`, `?` = optional)
     * @param  (callable(array<string, mixed>, MessageReferenceData): array<string, mixed>)|null  $format  final display parameters
     */
    public function adapter(string $key, array $schema = [], ?callable $format = null): static
    {
        $this->adapters[$key] = ['schema' => $schema, 'format' => $format];

        return $this;
    }

    public function hasAdapter(string $key): bool
    {
        return isset($this->adapters[$key]);
    }

    /**
     * Register a known-identity presenter for a family. Presenters are asked in registration order; the first non-null
     * reference wins.
     *
     * @param  callable(array<string, mixed>): ?MessageReferenceData  $present
     */
    public function identity(string $family, callable $present): static
    {
        $this->identities[$family][] = $present;

        return $this;
    }

    /** Render a reference for the current reader. */
    public function render(MessageReferenceData $reference): PresentedLine
    {
        return new PresentedLine($this->renderText($reference), PresentedLine::REFERENCE);
    }

    /**
     * The reader rule (RULING-04 §5), in one call:
     * 1. a stored reference (`properties.messageReference`, a new write) renders;
     * 2. else a registered presenter for the row's known identity renders;
     * 3. else the stored text, byte-identical, as `legacy` (never passed through `__()`, never parsed).
     *
     * @param  array<string, mixed>  $identity
     */
    public function present(string $family, array $identity, mixed $storedReference, ?string $legacy): PresentedLine
    {
        $stored = MessageReferenceData::fromProperties($storedReference);
        if ($stored !== null) {
            return new PresentedLine($this->renderText($stored), PresentedLine::REFERENCE);
        }

        foreach ($this->identities[$family] ?? [] as $present) {
            $reference = $present($identity);
            if ($reference instanceof MessageReferenceData) {
                return new PresentedLine($this->renderText($reference), PresentedLine::IDENTITY);
            }
        }

        return $legacy === null ? new PresentedLine(null, PresentedLine::NONE) : new PresentedLine($legacy, PresentedLine::LEGACY);
    }

    private function renderText(MessageReferenceData $reference): string
    {
        $problem = $this->problemWith($reference);
        $translator = app('translator');
        if ($problem === null) {
            $exists = $translator instanceof BeamTranslator
                ? $translator->resolve($reference->key, null, true, $translator->requestRealm()) !== null
                : Lang::has($reference->key);
            if (! $exists) {
                $problem = "no catalog answers the product key [{$reference->key}]";
            }
        }

        if ($problem !== null) {
            if (app()->runningUnitTests()) {
                throw new InvalidMessageReference($problem);
            }
            Log::warning('localization: a message reference could not render; showing its default', ['key' => $reference->key, 'problem' => $problem]);

            return $this->fallback($reference);
        }

        $parameters = $this->displayParameters($reference);
        if ($reference->count !== null) {
            return $translator instanceof BeamTranslator
                ? $translator->choiceIn($translator->requestRealm(), $reference->key, $reference->count, $parameters)
                : trans_choice($reference->key, $reference->count, $parameters);
        }
        $line = $translator instanceof BeamTranslator
            ? $translator->getIn($translator->requestRealm(), $reference->key, $parameters)
            : __($reference->key, $parameters);

        return is_string($line) ? $line : $this->fallback($reference);
    }

    /** The reference's own English default with its scalar parameters: production's safe fallback. */
    private function fallback(MessageReferenceData $reference): string
    {
        $replace = [];
        foreach ($reference->parameters as $name => $value) {
            $replace[$name] = $value === null ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
        $translator = app('translator');

        return $translator instanceof BeamTranslator ? $translator->present($translator->replaceIn($reference->default, $replace)) : strtr($reference->default, array_combine(
            array_map(static fn (string $k): string => ':'.$k, array_keys($replace)),
            array_values($replace),
        ) ?: []);
    }

    /** Why a reference cannot render, or null when it matches its key's adapter. */
    private function problemWith(MessageReferenceData $reference): ?string
    {
        if ($reference->version !== MessageReferenceData::VERSION) {
            return "unknown reference version [{$reference->version}]";
        }
        $adapter = $this->adapters[$reference->key] ?? null;
        if ($adapter === null) {
            return "no adapter is registered for the product key [{$reference->key}]";
        }
        foreach ($adapter['schema'] as $name => $type) {
            $optional = str_starts_with($type, '?');
            $type = ltrim($type, '?');
            $present = array_key_exists($name, $reference->parameters) && $reference->parameters[$name] !== null;
            if (! $present) {
                if (! $optional) {
                    return "the parameter [{$name}] is required by [{$reference->key}]";
                }

                continue;
            }
            if (! self::fits($reference->parameters[$name], $type)) {
                return "the parameter [{$name}] is not a {$type} for [{$reference->key}]";
            }
        }
        foreach (array_keys($reference->parameters) as $name) {
            if (! array_key_exists($name, $adapter['schema'])) {
                return "the parameter [{$name}] is not declared by [{$reference->key}]";
            }
        }

        return null;
    }

    private static function fits(mixed $value, string $type): bool
    {
        return match (true) {
            $type === 'string' => is_string($value),
            $type === 'int' => is_int($value),
            $type === 'float' => is_int($value) || is_float($value),
            $type === 'bool' => is_bool($value),
            str_starts_with($type, 'money:') => is_int($value) || is_float($value),
            $type === 'date' => is_string($value) && strtotime($value) !== false,
            default => false,
        };
    }

    /**
     * The display parameters: units formatted in the reader's locale, then the adapter's own formatter.
     *
     * @return array<string, mixed>
     */
    private function displayParameters(MessageReferenceData $reference): array
    {
        $adapter = $this->adapters[$reference->key];
        $locale = app()->getLocale();
        $display = [];
        foreach ($reference->parameters as $name => $value) {
            $type = ltrim($adapter['schema'][$name] ?? 'string', '?');
            $display[$name] = match (true) {
                $value === null => '',
                str_starts_with($type, 'money:') => Number::currency((float) $value, in: substr($type, 6), locale: $locale),
                $type === 'date' => Carbon::parse((string) $value)->locale($locale)->isoFormat('LL'), // @phpstan-ignore method.nonObject (locale(string) returns the instance)
                is_bool($value) => $value ? 'true' : 'false',
                in_array($type, self::SCALARS, true) => $value,
                default => $value,
            };
        }

        return $adapter['format'] !== null ? ($adapter['format'])($display, $reference) : $display;
    }
}
