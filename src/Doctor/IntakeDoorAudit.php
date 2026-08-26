<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Intake\PublicIntakeWriteGate;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

/**
 * **A mounted intake door must be able to answer** — the door is live at `POST /beam/intake/{schema}`
 * and every schema it is configured to accept resolves and is allow-listed (beam-facade ticket 53).
 *
 * ## Why this replaced a check that could not fail
 * Its predecessor, `SchemaFormsDoorAudit`, was presence-conditional on `splicewire/laravel-schema-forms`
 * and on a `schema-forms.submit` route. That package was **built and entirely removed** (ticket 41), and
 * ticket 39 deleted the last such route, so the audit reported the same reassuring INFO at every host in
 * the estate — a check for a package that no longer exists, answering that its absence is fine. Ticket 40
 * already ruled that **a check whose subject dissolved is not repaired by re-aiming it at a different
 * subject**; this one survives the rename only because it acquired a predicate that asserts something,
 * and shipping the door's presence as a permanent Pass line would have asserted nothing twice.
 *
 * ## The predicate, and why it is the door's own code path
 * Ticket 39's live trap: **the route is mounted and the host's schema registry cannot answer it.** Then
 * every submission 404s — {@see PublicIntakeController} resolves the target through
 * {@see SchemaTargetResolver}, an unknown stem returns `[]`, and `[]` is a `NotFoundHttpException`. That
 * stood at 8 of 9 hosts before ticket 48 registered their artifacts, which is why this could not ship
 * earlier: a check that flags its whole population teaches the estate to ignore it (19's precedent).
 *
 * Three things are asked, in the order the door asks them, each against the door's own collaborators
 * rather than a re-implementation — the point being that a check which reasons about the door by
 * copying its rules drifts from it, while one that calls {@see SchemaTargetResolver} and
 * {@see PublicIntakeWriteGate} cannot:
 *
 *  1. is the registry answerable at all (an empty one 404s every slug);
 *  2. does each `beam.core.intake.slugs` slug's stem resolve to a registered artifact (404 if not);
 *  3. is that stem on `beam.core.intake.public_schemas` (403 if not — deny-by-default, and a host that
 *     registers the artifact and forgets the allow-list gets a door that is live, correct, and refuses).
 *
 * ## Report the location, never the config key
 * Ticket 48 named the `file` tier as `resources/schemas/registry/` per `config('data-schemas.registry_directory')`
 * and found that true at exactly one of six hosts: the others carried no such key, so the tier resolved
 * to the package default under the **gitignored** `storage_path('app/schemas/registry')`, where
 * registering an artifact passes every gate while committing nothing. A package default is wiring too,
 * and the wrong location raises no error — so this reports the directory the **resolved object** is
 * actually reading, taken off the instance ({@see describeRegistry()}), not the config value that was
 * supposed to produce it.
 *
 * ## What it deliberately does not reach
 * A **record's** `schema_ref` that no longer resolves. That is the same silent-`[]` family one layer
 * down — a capture that succeeds, 201s, and notifies nobody — and it is
 * `beam-facade` ticket 62's, whose population is stored rows and the stems host controllers stamp. This
 * audit's population is the door's *configuration*, which is static, enumerable, and repaired by editing
 * a config file. Widening it to rows would also sweep in the pre-48 historical refs that ticket 47 ruled
 * a legitimate miss.
 *
 * Advisory, on the estate's own precedent (1 of ~30 audits gates). A door that cannot answer is a
 * misconfiguration of an opt-in feature, not an unbootable app.
 */
class IntakeDoorAudit implements DoctorAudit
{
    public const CHECK = 'beam.intake.door';

    /** The name {@see BeamServiceProvider::registerIntakeRoute()} mounts the door under. */
    public const ROUTE = 'beam.intake.submit';

    public function __construct(protected Container $app) {}

    public static function forApp(?Container $app = null): self
    {
        return new self($app ?? \Illuminate\Container\Container::getInstance());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! $this->mounted()) {
            return [Finding::pass(self::CHECK, sprintf(
                'No intake door mounted (%s is not registered) — a beam app that captures through its own '.
                'controllers, or captures nothing, is valid.',
                self::ROUTE,
            ))];
        }

        $registry = $this->registry();
        $targets = $this->targets();

        if ($registry === null || $targets === null) {
            return [Finding::warn(self::CHECK, sprintf(
                'The intake door is mounted at %s and %s is not bound, so the door cannot resolve any '.
                'schema and every submission 404s. Install/register schemastud data-schemas, or turn the '.
                'door off with beam.core.intake.enabled.',
                self::ROUTE,
                $registry === null ? SchemaRegistry::class : SchemaTargetResolver::class,
            ))];
        }

        $location = $this->describeRegistry($registry);
        $ids = $this->ids($registry);

        if ($ids === []) {
            return [Finding::warn(self::CHECK, sprintf(
                'The intake door is mounted at %s and the schema registry is EMPTY (%s), so every '.
                'submission 404s — the door resolves its target through the registry and an unknown stem '.
                'is a 404. Register the intake schema as a committed artifact, and check the location '.
                'above is the one you commit to: an untracked directory passes this check locally and '.
                'ships an empty registry.',
                self::ROUTE,
                $location,
            ))];
        }

        // A host whose PUBLISHED config still carries the pre-126 `forms` key reads as "declares no
        // slugs" and 404s every slug it thinks it declared — the door falls back to treating the URL
        // segment as a raw stem. That is silent, so it is reported first and as a WARN, ahead of the
        // no-slugs PASS it would otherwise be mistaken for.
        if ($this->config('beam.core.intake.forms', null) !== null) {
            return [Finding::warn(self::CHECK,
                'This host publishes the retired beam.core.intake.forms key. It was renamed to '.
                'beam.core.intake.slugs (beam-facade ticket 126) and NOTHING reads the old name, so '.
                'every slug it maps 404s: the door falls back to treating the URL segment as a raw '.
                'schema stem. Rename the key in config/beam/core.php — the values are unchanged.'
            )];
        }

        $slugs = $this->slugs();

        if ($slugs === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'The intake door is mounted at %s over a registry holding %d schema(s) (%s). '.
                'beam.core.intake.slugs declares no slugs, so the door takes a schema stem straight off '.
                'the URL and there is no configured population to resolve ahead of a request.',
                self::ROUTE,
                count($ids),
                $location,
            ))];
        }

        $gate = new PublicIntakeWriteGate(array_values($this->publicSchemas()));
        $findings = [];
        $answered = [];

        foreach ($slugs as $slug => $stem) {
            if ($targets->targetFor($stem) === []) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    'POST /beam/intake/%s 404s on every submission: beam.core.intake.slugs maps it to '.
                    'the stem [%s], which has no registered version in the registry (%s). Either the '.
                    'artifact was never registered, or its $id was re-stemmed on one side only.',
                    $slug,
                    $stem,
                    $location,
                ));

                continue;
            }

            if (! $gate->authorizes($stem, [])) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    'POST /beam/intake/%s 403s on every submission: the stem [%s] resolves, but it is '.
                    'not on beam.core.intake.public_schemas. The door is deny-by-default, so a schema '.
                    'nobody marked publicly submittable is refused before its payload is validated.',
                    $slug,
                    $stem,
                ));

                continue;
            }

            $answered[] = $slug.' → '.$stem;
        }

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass(self::CHECK, sprintf(
            'The intake door is mounted at %s and answers every slug it declares (%s), each resolving '.
            'in the registry (%s) and allow-listed for public submission. Not covered here: whether a '.
            'stored record\'s schema_ref still resolves — that is a row-level miss, and it is silent.',
            self::ROUTE,
            implode(', ', $answered),
            $location,
        ))];
    }

    /** Whether the door's route is registered on this host — the whole population gate. */
    protected function mounted(): bool
    {
        try {
            $router = $this->app->make('router');

            return $router instanceof Router && $router->has(self::ROUTE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The slug → stem map the door reads, verbatim. The controller uses a configured value **as** the
     * stem without re-stemming it, so this must not stem it either or the check would resolve something
     * the door never asks for.
     *
     * @return array<string, string>
     */
    protected function slugs(): array
    {
        $slugs = [];

        foreach ((array) $this->config('beam.core.intake.slugs', []) as $slug => $stem) {
            if (is_string($slug) && is_string($stem) && $stem !== '') {
                $slugs[$slug] = $stem;
            }
        }

        return $slugs;
    }

    /** @return array<int|string, string> */
    protected function publicSchemas(): array
    {
        return array_filter(
            (array) $this->config('beam.core.intake.public_schemas', []),
            static fn ($value): bool => is_string($value) && $value !== '',
        );
    }

    protected function registry(): ?SchemaRegistry
    {
        try {
            $registry = $this->app->make(SchemaRegistry::class);
        } catch (\Throwable) {
            return null;
        }

        return $registry instanceof SchemaRegistry ? $registry : null;
    }

    protected function targets(): ?SchemaTargetResolver
    {
        try {
            $targets = $this->app->make(SchemaTargetResolver::class);
        } catch (\Throwable) {
            return null;
        }

        return $targets instanceof SchemaTargetResolver ? $targets : null;
    }

    /**
     * Every `$id` the registry can enumerate, or `[]` when it cannot answer at all.
     *
     * A composed {@see BeamSchemaRegistry} walks its tiers here, and a `db` tier over a table that does
     * not exist throws — which is itself a door that cannot answer, so the failure is folded into the
     * empty case rather than being allowed to take the doctor run down.
     *
     * @return array<int, string>
     */
    protected function ids(SchemaRegistry $registry): array
    {
        try {
            return $registry->ids();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Where the resolved registry actually reads from — the instance's own state, never the config key
     * that was meant to set it (see the class docblock: at five of six hosts those disagreed, silently).
     *
     * Read by reflection deliberately. The alternative is a public accessor on
     * {@see FilesystemSchemaRegistry}, which lives in `schemastud/laravel-data-schemas` — a second repo,
     * a second lock, and a widened surface on an immutable store, all to let one advisory check print a
     * path. Every read is guarded and falls back to the class name, so a shape change upstream costs
     * this check its detail line and nothing else.
     */
    protected function describeRegistry(SchemaRegistry $registry): string
    {
        if ($registry instanceof FilesystemSchemaRegistry) {
            $directory = $this->property($registry, 'directory');

            return is_string($directory)
                ? 'filesystem tier at '.$directory
                : FilesystemSchemaRegistry::class;
        }

        if ($registry instanceof BeamSchemaRegistry) {
            $sources = $this->property($registry, 'sources');
            $factories = $this->property($registry, 'factories');

            if (! is_array($sources)) {
                return BeamSchemaRegistry::class;
            }

            $tiers = [];

            foreach ($sources as $source) {
                $tiers[] = $source.': '.$this->describeTier($factories, (string) $source);
            }

            return 'composed registry, first-hit-wins — '.implode('; ', $tiers);
        }

        return $registry::class;
    }

    /** One composed tier, constructed through its own lazy factory so the description is the real object. */
    protected function describeTier(mixed $factories, string $source): string
    {
        if (! is_array($factories) || ! isset($factories[$source]) || ! is_callable($factories[$source])) {
            return 'no factory';
        }

        try {
            $tier = ($factories[$source])();
        } catch (\Throwable $e) {
            return 'unavailable ('.$e->getMessage().')';
        }

        return $tier instanceof SchemaRegistry ? $this->describeRegistry($tier) : 'not a SchemaRegistry';
    }

    protected function property(object $object, string $name): mixed
    {
        try {
            $property = new \ReflectionProperty($object, $name);
            $property->setAccessible(true);

            return $property->getValue($object);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        try {
            return $this->app->make('config')->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
