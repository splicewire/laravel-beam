<?php

namespace Splicewire\Beam\Agreements\Http;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use LogicException;
use Splicewire\Beam\Agreements\NullRequirementResolver;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementRefused;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Agreements\SlotRequirementResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Symfony\Component\HttpFoundation\Response;

/**
 * agreements 07: the one check behind both route middlewares ({@see RequireAgreement}, {@see EnforceRouteRequirements}).
 * It holds no agreement logic: it names the behavior (`route:{name}`, ADR-0247 amendment 07), takes the actor from
 * beam's {@see ActorPort} and the subject from the route parameter the middleware names, and asks the SAME
 * {@see RequirementAdmission} the operation transport asks.
 *
 * - No seam (the bound resolver is still beam's own Slot/Null default): every request passes (ticket 07, lead ruling
 *   Q-07c; unlike 05's unseamed slots, which refuse as `unregistered`).
 * - A data request (`expectsJson()` and not an Inertia request, Q-07d) gets 428 with the declared refusal body.
 * - Any other request (a browser visit, an Inertia visit full or partial) is sent with 303 to the seam's prompt
 *   (`beam.agreements.required`) with the intended destination in the session; with no prompt route it gets the 428.
 * Asked at most once per request, whichever middleware reaches it first.
 */
final class RouteRequirementGate
{
    public const BEHAVIOR_PREFIX = 'route:';

    public const PROMPT_ROUTE = 'beam.agreements.required';

    public const SESSION_KEY = 'agreements.required';

    private const ASKED = 'beam.agreements.route-gate-asked';

    public function __construct(
        private RequirementResolver $resolver,
        private RequirementDeclarations $declarations,
        private RequirementAdmission $admission,
        private ActorPort $actors,
    ) {}

    public static function behaviorOf(Route $route): string
    {
        $name = $route->getName();
        if ($name === null || $name === '') {
            throw new LogicException(sprintf(
                'Route [%s] carries the agreement middleware but has no name; a route\'s behavior key is route:{name} (ADR-0247, agreements 07). Name the route.',
                $route->uri(),
            ));
        }

        return self::BEHAVIOR_PREFIX.$name;
    }

    /** Is an agreements seam bound? Beam's own defaults know no Agreement, so a route passes under them. */
    public function seamed(): bool
    {
        return ! $this->resolver instanceof SlotRequirementResolver
            && ! $this->resolver instanceof NullRequirementResolver;
    }

    /**
     * @param  string|null  $subjectParameter  the route parameter whose bound record is the subject
     */
    public function handle(Request $request, Closure $next, ?string $subjectParameter = null): Response
    {
        if ($request->attributes->get(self::ASKED) === true || ! $this->seamed()) {
            return $next($request);
        }
        $request->attributes->set(self::ASKED, true);

        $route = $request->route();
        if (! $route instanceof Route) {
            return $next($request);
        }

        $behavior = self::behaviorOf($route);
        $subjectParameter ??= RequireAgreement::subjectParameterOf($route);
        // review P2 3 (lead ruling): a subject-scoped Requirement needs a bound subject. Without a parameter to resolve it
        // the person could accept forever and never pass, so the configuration is rejected, never asked.
        if ($subjectParameter === null && $this->subjectScoped($behavior)) {
            throw new LogicException(sprintf(
                'Route [%s] (%s) has a subject-scoped Requirement but names no route parameter for the subject. Spell it agreement:{key},{parameter} (ADR-0247 amendment 07).',
                $route->getName(),
                $route->uri(),
            ));
        }
        $subject = $subjectParameter === null ? null : $route->parameter($subjectParameter);
        // review P2 3, completion (lead rule): a subject Requirement needs the named parameter ON the route and BOUND to a
        // model. A missing name or a scalar would leave it outstanding while accepting records only a party Assent: an
        // endless prompt. Like an unnamed route, it's a developer misconfiguration, so it fails loud and never prompts.
        if ($subjectParameter !== null && $this->subjectScoped($behavior)) {
            if (! in_array($subjectParameter, $route->parameterNames(), true)) {
                throw new LogicException(sprintf(
                    'Route [%s] (%s) names [%s] as the subject of its Requirement, but the route has no such parameter (ADR-0247 amendment 07).',
                    $route->getName(), $route->uri(), $subjectParameter,
                ));
            }
            if (! $subject instanceof Model) {
                throw new LogicException(sprintf(
                    'Route [%s] (%s) names [%s] as the subject of its Requirement, but it isn\'t bound to a model (got %s). Bind it (an implicit or explicit route-model binding) (ADR-0247 amendment 07).',
                    $route->getName(), $route->uri(), $subjectParameter, get_debug_type($subject),
                ));
            }
        }

        $refusal = $this->admission->inspect($this->actors->actor(), $behavior, $subject, []);
        if ($refusal === null) {
            return $next($request);
        }

        // review P2 2 (lead ruling): the page-visit path needs a session to carry the destination. A session-less request
        // (a stateless `api` group) gets the data refusal whatever it accepts.
        if ($this->isDataRequest($request) || ! $request->hasSession() || ! RouteFacade::has(self::PROMPT_ROUTE)) {
            return (new RequirementRefused($refusal, $behavior))->toResponse();
        }

        // review P2 1 (lead ruling): url()->previous() trusts the raw Referer, so only a SAME-ORIGIN destination is kept.
        $home = url('/');
        $previous = self::sameOrigin(url()->previous(), $home);
        $intended = $request->isMethod('GET') || $request->isMethod('HEAD') ? $request->fullUrl() : $previous;
        $request->session()->put(self::SESSION_KEY, [
            'behavior' => $behavior,
            'subject' => $subject instanceof Model ? $subject->getKey() : (is_scalar($subject) ? $subject : null),
            'refusal' => $refusal->toArray(),
            'intended' => $intended,
            'back' => $previous !== $request->fullUrl() ? $previous : $home,
        ]);

        return redirect()->route(self::PROMPT_ROUTE, status: 303);
    }

    /**
     * A same-origin destination, or $fallback. Same-origin means a relative path (`/x`, never `//host` or a scheme) or an
     * absolute http(s) URL whose scheme, host and port are the app's own (`url('/')`). Anything else (another host, a
     * protocol-relative `//evil.example`, `javascript:`) is replaced, so the prompt never sends anyone off-site.
     */
    public static function sameOrigin(?string $url, string $fallback): string
    {
        if ($url === null || $url === '') {
            return $fallback;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\')) {
            return $url;
        }
        $candidate = parse_url($url);
        $app = parse_url(url('/'));
        if (! is_array($candidate) || ! is_array($app) || ! in_array(strtolower($candidate['scheme'] ?? ''), ['http', 'https'], true)) {
            return $fallback;
        }
        $port = static fn (array $u): int => (int) ($u['port'] ?? (strtolower($u['scheme'] ?? '') === 'https' ? 443 : 80));

        return strtolower($candidate['scheme']) === strtolower($app['scheme'] ?? '')
            && strtolower($candidate['host'] ?? '') === strtolower($app['host'] ?? '')
            && $port($candidate) === $port($app)
            ? $url
            : $fallback;
    }

    /** Does any Requirement on this behavior need a subject? */
    private function subjectScoped(string $behavior): bool
    {
        foreach ($this->declarations->requirementsFor($behavior) as $requirement) {
            if ($requirement->scope === 'subject') {
                return true;
            }
        }

        return false;
    }

    /** Q-07d: a data request asks for JSON and isn't Inertia's (whose visits, full or partial, follow a redirect). */
    public function isDataRequest(Request $request): bool
    {
        return $request->expectsJson() && ! $request->headers->has('X-Inertia');
    }

    /** For {@see EnforceRouteRequirements}: does the current route's behavior carry any Requirement? One port lookup. */
    public function declaresRoute(Route $route): bool
    {
        $name = $route->getName();

        return $name !== null && $name !== '' && $this->declarations->declares(self::BEHAVIOR_PREFIX.$name);
    }
}
