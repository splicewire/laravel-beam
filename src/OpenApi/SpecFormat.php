<?php

namespace Splicewire\Beam\OpenApi;

use Knuckles\Scribe\Writing\Writer;

/**
 * The two representations beam serves its OpenAPI artifact in (ADR-0211 §2).
 *
 * Both are REAL URLs, not `Accept` variants: every consumer of a spec — Scalar, Redoc, `curl`, an MCP
 * client — takes a URL, so content negotiation alone would leave a caller with no JSON link to paste.
 *
 * YAML is the source of truth because Scribe emits YAML only ({@see Writer} does
 * a single `Yaml::dump`); JSON is a derivation beam owns.
 */
enum SpecFormat: string
{
    case Yaml = 'yaml';

    case Json = 'json';

    /**
     * The response `Content-Type`. `application/yaml` is the registered media type (RFC 9512) —
     * `text/yaml` is the older de-facto spelling and Scalar accepts either.
     */
    public function contentType(): string
    {
        return match ($this) {
            self::Yaml => 'application/yaml',
            self::Json => 'application/json',
        };
    }

    /** The route name beam mounts this format under, for `route()` callers (a docs page's `specUrl`). */
    public function routeName(): string
    {
        return 'beam.openapi.'.$this->value;
    }
}
