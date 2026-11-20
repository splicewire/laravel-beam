<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Agreements\Http\RequireAgreement;

/**
 * **A route carrying the `agreement` middleware must be named** (agreements 07, lead ruling Q-07b; ADR-0247 amendment 07).
 *
 * A route's behavior key is `route:{name}`: an unnamed route has none, so its Requirement can't be declared, attached or
 * asked, and the middleware throws on its first request. This check finds it at boot instead, naming the URI.
 * Registered `gate: true`: the route is broken until it is named.
 */
class UnnamedRequirementRouteAudit implements DoctorAudit
{
    public const CHECK = 'requirements.route-unnamed';

    public function __construct(protected Router $router) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $unnamed = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if (($name === null || $name === '') && RequireAgreement::declaredOn($route) !== []) {
                $unnamed[] = sprintf('  %s %s', implode('|', $route->methods()), $route->uri());
            }
        }

        if ($unnamed === []) {
            return [Finding::pass(self::CHECK, 'Every route carrying the `agreement` middleware is named.')];
        }

        return [Finding::fail(self::CHECK, sprintf(
            "%d route%s carr%s the `agreement` middleware without a name, so %s no behavior key (`route:{name}`) and throw%s on the first request:\n%s\n\nName each route.",
            count($unnamed),
            count($unnamed) === 1 ? '' : 's',
            count($unnamed) === 1 ? 'ies' : 'y',
            count($unnamed) === 1 ? 'it has' : 'they have',
            count($unnamed) === 1 ? 's' : '',
            implode("\n", $unnamed),
        ))];
    }
}
