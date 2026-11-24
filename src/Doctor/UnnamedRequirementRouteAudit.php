<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Agreements\Http\RequireAgreement;
use Splicewire\Beam\Agreements\Http\RouteRequirementGate;
use Splicewire\Beam\Agreements\RequirementDeclarations;

/**
 * **A route carrying the `agreement` middleware must be named** (agreements 07, lead ruling Q-07b; ADR-0247 amendment 07).
 *
 * A route's behavior key is `route:{name}`: an unnamed route has none, so its Requirement can't be declared, attached or
 * asked, and the middleware throws on its first request. This check finds it at boot instead, naming the URI.
 * Registered `gate: true`: the route is broken until it is named. It also runs {@see self::CHECK_SUBJECT} (review P2 3).
 */
class UnnamedRequirementRouteAudit implements DoctorAudit
{
    public const CHECK = 'requirements.route-unnamed';

    /** review P2 3: a subject-scoped route Requirement with no route parameter to bind the subject. */
    public const CHECK_SUBJECT = 'requirements.route-subject';

    public function __construct(protected Router $router, protected RequirementDeclarations $declarations) {}

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

        return [$this->unnamedFinding($unnamed), $this->subjectFinding()];
    }

    /** @param list<string> $unnamed */
    private function unnamedFinding(array $unnamed): Finding
    {
        if ($unnamed === []) {
            return Finding::pass(self::CHECK, 'Every route carrying the `agreement` middleware is named.');
        }

        return Finding::fail(self::CHECK, sprintf(
            "%d route%s carr%s the `agreement` middleware without a name, so %s no behavior key (`route:{name}`) and throw%s on the first request:\n%s\n\nName each route.",
            count($unnamed),
            count($unnamed) === 1 ? '' : 's',
            count($unnamed) === 1 ? 'ies' : 'y',
            count($unnamed) === 1 ? 'it has' : 'they have',
            count($unnamed) === 1 ? 's' : '',
            implode("\n", $unnamed),
        ));
    }

    /**
     * Why a named subject parameter can't bind a subject, as far as can be known without a request: it isn't one of the
     * route's parameters, or nothing binds it to a model (no explicit binding, and no closure/controller parameter of that
     * name typed as a routable model). Null when it looks bound; the gate checks the bound value on every request.
     */
    private function subjectParameterProblem(Route $route, string $parameter): ?string
    {
        if (! in_array($parameter, $route->parameterNames(), true)) {
            return sprintf('the subject parameter [%s] is not a parameter of the route', $parameter);
        }
        if ($this->router->getBindingCallback($parameter) !== null) {
            return null;
        }
        try {
            foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $typed) {
                if ($typed->getName() === $parameter) {
                    return null;
                }
            }
        } catch (\Throwable) {
            return null; // the action can't be reflected here (an unresolvable controller): leave it to the request check
        }

        return sprintf('the subject parameter [%s] has no model binding', $parameter);
    }

    /**
     * Every named route whose `route:{name}` carries a subject-scoped Requirement (its own middleware's, or one a provider
     * attached) must name the route parameter that binds the subject; otherwise the gate throws on its first request.
     */
    private function subjectFinding(): Finding
    {
        $missing = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || $name === '') {
                continue;
            }
            $parameter = RequireAgreement::subjectParameterOf($route);
            if ($parameter !== null) {
                // review P2 3, completion: the named subject parameter must exist on the route and be model-bound.
                $problem = $this->subjectParameterProblem($route, $parameter);
                if ($problem !== null) {
                    $missing[] = sprintf('  %s %s (route:%s: %s)', implode('|', $route->methods()), $route->uri(), $name, $problem);
                }

                continue;
            }
            foreach ($this->declarations->requirementsFor(RouteRequirementGate::BEHAVIOR_PREFIX.$name) as $requirement) {
                if ($requirement->scope === 'subject') {
                    $missing[] = sprintf('  %s %s (route:%s requires %s)', implode('|', $route->methods()), $route->uri(), $name, $requirement->label());
                    break;
                }
            }
        }

        if ($missing === []) {
            return Finding::pass(self::CHECK_SUBJECT, 'No subject-scoped route Requirement lacks a subject parameter.');
        }

        return Finding::fail(self::CHECK_SUBJECT, sprintf(
            "%d route%s carr%s a subject-scoped Requirement but name%s no BOUND route parameter for the subject, so the person could never satisfy it:\n%s\n\nSpell it agreement:{key},{parameter}.",
            count($missing),
            count($missing) === 1 ? '' : 's',
            count($missing) === 1 ? 'ies' : 'y',
            count($missing) === 1 ? 's' : '',
            implode("\n", $missing),
        ));
    }
}
