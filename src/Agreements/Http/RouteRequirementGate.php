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
        $subject = $subjectParameter === null ? null : $route->parameter($subjectParameter);

        $refusal = $this->admission->inspect($this->actors->actor(), $behavior, $subject, []);
        if ($refusal === null) {
            return $next($request);
        }

        if ($this->isDataRequest($request) || ! RouteFacade::has(self::PROMPT_ROUTE)) {
            return (new RequirementRefused($refusal, $behavior))->toResponse();
        }

        $intended = $request->isMethod('GET') || $request->isMethod('HEAD') ? $request->fullUrl() : url()->previous();
        $request->session()->put(self::SESSION_KEY, [
            'behavior' => $behavior,
            'subject' => $subject instanceof Model ? $subject->getKey() : (is_scalar($subject) ? $subject : null),
            'refusal' => $refusal->toArray(),
            'intended' => $intended,
            'back' => url()->previous() !== $request->fullUrl() ? url()->previous() : url('/'),
        ]);

        return redirect()->route(self::PROMPT_ROUTE, status: 303);
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
