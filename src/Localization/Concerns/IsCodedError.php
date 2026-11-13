<?php

namespace Splicewire\Beam\Localization\Concerns;

use LogicException;
use ReflectionEnumBackedCase;
use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\TextNamespaces;

/**
 * The one trait a coded error enum uses (localization ticket 13; research/00 A2's prahsys pattern, without A4's failure).
 *
 * - The **code** is the case's backing value: stable, language-free, what a client matches.
 * - The **key** is DERIVED from the case (ruling 01 §2): `<ns>::enums.<Enum FQCN, \ as />.cases.<CaseName>.message`, the
 *   namespace being the enum's owning package (ruling 01 §3). Nobody types it, so it cannot be mistyped into a literal.
 * - The **message** walks beam's ladder for that key; when every rung misses, it is the case's declared English
 *   ({@see Message}). It never asks the translator for an arbitrary string: an exception's text is a diagnostic, never a
 *   translation key.
 *
 * @phpstan-require-implements CodedError
 */
trait IsCodedError
{
    public function code(): string
    {
        return (string) $this->value;
    }

    public function translationKey(): ?string
    {
        $prefix = app(TextNamespaces::class)->ownerOfClass(static::class)->keyPrefix();

        return $prefix === null
            ? null
            : $prefix.'enums.'.str_replace('\\', '/', static::class).'.cases.'.$this->name.'.message';
    }

    public function defaultMessage(): string
    {
        $attributes = (new ReflectionEnumBackedCase(static::class, $this->name))->getAttributes(Message::class);

        if ($attributes === []) {
            throw new LogicException(sprintf('Coded error [%s::%s] declares no #[Message].', static::class, $this->name));
        }

        return $attributes[0]->newInstance()->text;
    }

    /**
     * @param  array<string, scalar>  $params
     */
    public function message(array $params = [], ?string $locale = null, ?string $realm = null): string
    {
        $translator = app('translator');
        $key = $this->translationKey();

        if ($translator instanceof BeamTranslator) {
            $resolved = $key === null
                ? null
                : $translator->resolve($key, $locale, true, $realm ?? $translator->requestRealm());

            $line = is_string($resolved?->line) ? $resolved->line : $translator->present($this->defaultMessage());

            return $translator->replaceIn($line, $params);
        }

        return strtr($this->defaultMessage(), array_combine(
            array_map(static fn (string $name): string => ':'.$name, array_keys($params)),
            array_map(static fn (mixed $value): string => (string) $value, array_values($params)),
        ) ?: []);
    }
}
