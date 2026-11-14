<?php

namespace Splicewire\Beam\Data;

use Spatie\LaravelData\Data;

/**
 * A stored reference to a product sentence, rendered later in its reader's language (localization 37; RULING-04 §4,
 * "Declared Data option"; OWNER-RESOLUTION R1). It's EXACTLY the declared shape:
 *
 *     version: 1
 *     key: string               the product key (FINAL 01 grammar), selected by product code, never arbitrary text
 *     parameters: map<string, string|int|float|bool|null>
 *     default: string           the original unexpanded English product template
 *     count: int|float|null     an explicit plural selector
 *
 * It is INTERNAL: status Display metadata (`properties.messageReference`) and session storage. It is not declared on
 * any wire output, so merely existing does not make it a new wire shape (§4). Rendering, parameter validation and
 * units belong to {@see \Splicewire\Beam\Localization\Presentation\MessagePresenters}.
 */
final class MessageReferenceData extends Data
{
    public const VERSION = 1;

    /**
     * @param  array<string, string|int|float|bool|null>  $parameters
     */
    public function __construct(
        public int $version,
        public string $key,
        public array $parameters,
        public string $default,
        public int|float|null $count = null,
    ) {}

    /**
     * A version-1 reference.
     *
     * @param  array<string, string|int|float|bool|null>  $parameters
     */
    public static function of(string $key, string $default, array $parameters = [], int|float|null $count = null): self
    {
        return new self(self::VERSION, $key, $parameters, $default, $count);
    }

    /**
     * The stored shape (the value of `properties.messageReference`).
     *
     * @return array{version: int, key: string, parameters: array<string, string|int|float|bool|null>, default: string, count: int|float|null}
     */
    public function toProperties(): array
    {
        return ['version' => $this->version, 'key' => $this->key, 'parameters' => $this->parameters, 'default' => $this->default, 'count' => $this->count];
    }

    /**
     * Read a stored reference TOLERANTLY. It returns null for anything that is not a well-formed version-1 reference:
     * an unknown version, a missing or non-string key or default, a non-scalar parameter, or a non-numeric count. The
     * reader then falls back to the row's legacy text (RULING-04 §5: "unknown versions use safe legacy field").
     */
    public static function fromProperties(mixed $stored): ?self
    {
        if (! is_array($stored) || ($stored['version'] ?? null) !== self::VERSION) {
            return null;
        }
        $key = $stored['key'] ?? null;
        $default = $stored['default'] ?? null;
        $parameters = $stored['parameters'] ?? [];
        $count = $stored['count'] ?? null;
        if (! is_string($key) || $key === '' || ! is_string($default) || ! is_array($parameters)
            || ($count !== null && ! is_int($count) && ! is_float($count))) {
            return null;
        }
        foreach ($parameters as $name => $value) {
            if (! is_string($name) || ($value !== null && ! is_scalar($value))) {
                return null;
            }
        }

        return new self(self::VERSION, $key, $parameters, $default, $count);
    }
}
