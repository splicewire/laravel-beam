<?php

namespace Splicewire\Beam\Webhooks;

use Splicewire\Beam\Models\Hook;

/**
 * The outbound delivery signature (api-surface-coherence ticket 38, decided by ticket 12 §5).
 *
 * ## The scheme
 *
 * `X-Beam-Signature: t=1756180800,v1=9f0c…`
 *
 * `v1` is `hash_hmac('sha256', "{t}.{rawBody}", $secret)`, hex. The timestamp is INSIDE the signed
 * input, which is the whole reason it is carried in the header rather than as a separate one: an
 * attacker who captures a delivery cannot re-present it with a fresh `t`, because changing `t`
 * invalidates `v1` and they do not hold the secret to recompute it. A signature over the body alone
 * would be replayable forever.
 *
 * This is the Stripe/GitHub-family scheme rather than a new one, chosen because it is the scheme
 * receivers already have library code for. The estate had no outbound scheme to reuse: a grep for
 * `hash_hmac` across every family package's `src` on 2026-08-26 found four call sites and none
 * of them was an outbound webhook signature — marquee's bypass token, beam-embed's capability token,
 * tower's federation path, and `beam-market-packages`' GithubWebhookSignature, which VERIFIES
 * GitHub's inbound `sha256=…` and is the closest relative. The satellite inbound spine
 * (`SplicewirePublishVerifyProfile`) authenticates with a shared bearer and no HMAC at all.
 *
 * The `v1` prefix is version-space, not decoration: adding `v2=` alongside it is how this rotates
 * without a flag day, because a receiver checking `v1` ignores keys it does not know.
 *
 * ## Where the secret lives
 *
 * On the {@see Hook} row, in the clear, minted at create by
 * {@see Hook::mintSecret()} (32 CSPRNG bytes, hex) and revealed to the
 * caller exactly once — the `tokens` precedent. It is stored recoverable rather than hashed because
 * it is an HMAC KEY the platform must present on every delivery, not a credential the platform
 * verifies; there is nothing a hash could ever be compared against. Rotation is therefore a new
 * mint, not a rehash.
 *
 * ## Replay protection, and the half that is the receiver's
 *
 * The sender's half ships here and is complete: a signed timestamp so a capture cannot be re-dated,
 * and a per-delivery uuid echoed as both `X-Beam-Delivery` and `Idempotency-Key` so a receiver that
 * dedupes on it collapses a redelivery — including our own queue retries, which reuse the uuid
 * deliberately.
 *
 * The receiver's half — rejecting a signature whose `t` is outside a tolerance window — cannot be
 * enforced from the sending side, because we do not run the receiver. {@see verify()} implements it
 * with {@see DEFAULT_TOLERANCE} so an in-estate receiver and this package's own tests share one
 * implementation and the documented window is executable rather than prose.
 */
class HookSignature
{
    /** Seconds either side of `t` a receiver should accept. Five minutes, the family default. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * The header value for one delivery.
     *
     * @param  string  $body  the RAW serialized body, byte for byte as it goes on the wire — signing a
     *                        re-encoded array would let a whitespace difference break every receiver
     */
    public static function sign(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, static::digest($body, $secret, $timestamp));
    }

    /** The bare `v1` digest for `$body` at `$timestamp`. */
    public static function digest(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Verify a received header. Constant-time on the digest compare, and tolerance-checked — both
     * halves, because either one alone is not verification.
     *
     * @param  int|null  $tolerance  seconds; pass 0 to skip the freshness check (a replayable read,
     *                               and named so at the call site rather than defaulted to)
     */
    public static function verify(string $header, string $body, string $secret, ?int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        $parsed = static::parse($header);

        if ($parsed === null) {
            return false;
        }

        [$timestamp, $signature] = $parsed;

        if ($tolerance !== null && $tolerance > 0 && abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(static::digest($body, $secret, $timestamp), $signature);
    }

    /**
     * `t=…,v1=…` → `[timestamp, signature]`, or null when the header is not that.
     *
     * Unknown keys are ignored rather than rejected — that is what makes `v2=` addable without
     * breaking a `v1` reader.
     *
     * @return array{0: int, 1: string}|null
     */
    public static function parse(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1') {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null || $signature === '') {
            return null;
        }

        return [$timestamp, $signature];
    }

    /**
     * The header-name prefix, host-overridable (ticket 12 §5). `X-Beam-*` and NOT `X-Splicewire-*`:
     * this is a FREE-TIER package and `Splicewire` is the paid vendor name, so stamping it on every
     * delivery a bare beam host sends would advertise a product the host has not bought.
     */
    public static function headerPrefix(): string
    {
        $prefix = (string) config('webhooks.outbound.header_prefix', 'X-Beam');

        return rtrim($prefix, '-');
    }

    /** `signature` → `X-Beam-Signature`, through the configured prefix. */
    public static function header(string $name): string
    {
        return static::headerPrefix().'-'.ucfirst($name);
    }
}
