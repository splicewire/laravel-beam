<?php

namespace Splicewire\Beam\Localization;

/**
 * An error a client can receive (localization ticket 13): a string-backed enum case whose VALUE is its stable wire code
 * and whose message is localized. Implement it with {@see Concerns\IsCodedError} and declare each case's English with
 * {@see Attributes\Message}.
 *
 * The code never changes and never depends on the language, so clients and tests match the code, not the prose.
 */
interface CodedError
{
    /** The stable wire code: the case's backing value. */
    public function code(): string;

    /** The case's derived key, or null when its owner's namespace is refused (it then renders its English). */
    public function translationKey(): ?string;

    /** The case's declared English message, before replacement. */
    public function defaultMessage(): string;

    /**
     * The message in the requested (or current) locale, with `:name` parameters replaced.
     *
     * @param  array<string, scalar>  $params
     */
    public function message(array $params = [], ?string $locale = null, ?string $realm = null): string;
}
