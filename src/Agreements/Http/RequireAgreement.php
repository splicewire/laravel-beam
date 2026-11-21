<?php

namespace Splicewire\Beam\Agreements\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Splicewire\Beam\Agreements\Requires;
use Symfony\Component\HttpFoundation\Response;

/**
 * agreements 07: an ordinary route requires an Agreement. Aliased `agreement` (lead ruling Q-07b):
 *
 *     Route::get('/reports/{report}', ShowReport::class)->name('reports.show')
 *         ->middleware('agreement:terms');                  // party scope
 *         ->middleware('agreement:data-use,report');        // subject scope: the bound {report}
 *         ->middleware('agreement:terms,,organisation');    // organisation scope
 *     // or RequireAgreement::using('data-use', subject: 'report')
 *
 * The route's NAME is its behavior, as `route:{name}` (ADR-0247 amendment 07); an unnamed route throws on its first
 * request and fails the doctor's `requirements.route-unnamed`. The declaration reaches the requirements port through
 * {@see \Splicewire\Beam\Agreements\SlotRequirementDeclarations} (it reads routes), so the resolver, the route manifest
 * and the ability map see it like any slot. The check itself is {@see RouteRequirementGate}'s: no agreement logic here.
 */
final class RequireAgreement
{
    public const ALIAS = 'agreement';

    public function __construct(private RouteRequirementGate $gate) {}

    public static function using(string $agreement, ?string $subject = null, ?string $scope = null): string
    {
        if ($scope === 'subject' && ($subject === null || $subject === '')) {
            throw new LogicException(sprintf('agreement:%s has subject scope but names no route parameter for the subject; pass subject: (ADR-0247 amendment 07).', $agreement));
        }

        $arguments = array_map(static fn (?string $value): string => $value ?? '', [$agreement, $subject, $scope]);

        return self::ALIAS.':'.rtrim(implode(',', $arguments), ',');
    }

    public function handle(Request $request, Closure $next, string $agreement, ?string $subject = null, ?string $scope = null): Response
    {
        return $this->gate->handle($request, $next, $subject === '' ? null : $subject);
    }

    /**
     * The Requirements one route declares through this middleware, each spelled `agreement:{key}[,{param}[,{scope}]]`
     * (or by its class name). Read straight from the route's own middleware list: no routing pass, no container.
     *
     * @return list<array{requires: Requires, subject: ?string}>
     */
    public static function declaredOn(Route $route): array
    {
        $declared = [];
        foreach ((array) ($route->getAction('middleware') ?? []) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            [$name, $arguments] = array_pad(explode(':', $middleware, 2), 2, '');
            if ($name !== self::ALIAS && $name !== self::class) {
                continue;
            }
            [$agreement, $subject, $scope] = array_pad(explode(',', $arguments), 3, '');
            if ($agreement === '') {
                continue;
            }
            $subject = $subject === '' ? null : $subject;
            $scope = $scope !== '' ? $scope : ($subject !== null ? 'subject' : 'party');
            $declared[] = ['requires' => new Requires($agreement, scope: $scope), 'subject' => $subject];
        }

        return $declared;
    }

    /** The route parameter a route's own `agreement:` middleware names as the subject, if any. */
    public static function subjectParameterOf(Route $route): ?string
    {
        foreach (self::declaredOn($route) as $declaration) {
            if ($declaration['subject'] !== null) {
                return $declaration['subject'];
            }
        }

        return null;
    }
}
