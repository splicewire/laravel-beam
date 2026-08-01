<?php

namespace Splicewire\Beam\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in bot-defense middleware for the public intake door (beam-write-pipeline ticket 04), **default
 * off** — a host adds it (or flips `beam.intake.honeypot.enabled`) deliberately; it is never silently
 * imposed.
 *
 * A honeypot is a form field a human never fills but a naive bot does. When the configured field is
 * present, the request is silently accepted — a 201 indistinguishable from a real submission — and the
 * pipeline is short-circuited, so **nothing persists** and the bot gets no signal that it was caught.
 */
class HoneypotMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $field = (string) config('beam.core.intake.honeypot.field', 'website');

        if ($request->filled($field)) {
            // Silent success: look exactly like an accepted submission, persist nothing.
            return new JsonResponse(['id' => (string) Str::uuid7(), 'status' => 'accepted'], 201);
        }

        return $next($request);
    }
}
