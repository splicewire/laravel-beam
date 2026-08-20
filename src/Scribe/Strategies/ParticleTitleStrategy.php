<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Illuminate\Support\Str;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Give a DISSOLVED particle route its Scribe title + description DECLARATIVELY, off the particle
 * declaration itself — the sibling of beam-core's `GroupStrategy` (which groups the same routes off the
 * same stamp).
 *
 * A dissolved route's handler is the generic {@see ParticleController} / {@see ParticleOperationController},
 * so `GetFromDocBlocks` finds no summary and the docs sidebar shows the bare path (`/api/v1/agents/{id} POST`)
 * beside the titled hand-written endpoints. The declaration already knows what the endpoint IS — the
 * resource's registry key + optional nav `label`, the operation's `name` + {@see OperationKind} — so this
 * strategy derives:
 *
 *   - **CRUD verbs** — `index` → "List {Plural}", `show`/`store`/`update`/`destroy` → "{Verb} {Singular}",
 *     with the resource's declared `label` preferred over a {@see Str::headline} of its key. A relative
 *     mount (`/fragments/{fragment}/media`) appends "for a {Parent}".
 *   - **Operations** — a Read op titles noun-first ("{Singular} {Name}", e.g. "Media Download"); every
 *     other kind verb-first ("{Name} {Singular}", e.g. "Run Circuit"). The kind also drives the
 *     description (a Task documents its `?async` convention; a Stream its `text/event-stream` transport).
 *
 * **Ordering / precedence.** Registered AFTER Scribe's `GetFromDocBlocks` in `strategies.metadata`, so an
 * explicit docblock summary always WINS: this strategy returns `null` (defer) whenever a title is already
 * present, and for any non-particle route.
 */
class ParticleTitleStrategy extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        // An explicit docblock summary already won (DocBlocks ran ahead of us): don't clobber it.
        if (($endpointData->metadata->title ?? '') !== '') {
            return null;
        }

        $defaults = $endpointData->route?->defaults ?? [];

        if (isset($defaults[ParticleOperationController::RESOURCE], $defaults[ParticleOperationController::NAME])) {
            return $this->operationMetadata(
                $defaults[ParticleOperationController::RESOURCE],
                $defaults[ParticleOperationController::NAME],
            );
        }

        $key = $defaults[ParticleController::RESOURCE] ?? null;
        if ($key === null) {
            return null; // Not a particle route — defer to the docblock strategies.
        }

        return $this->resourceMetadata($endpointData, $key, $defaults[ParticleController::RELATIVE] ?? null);
    }

    /**
     * Title + description for a CRUD verb on a `Route::particleResource` mount, shaped by the route's
     * method name. An unknown method (a bespoke action on a ParticleController subclass that skipped its
     * docblock) still gets "{Headline(method)} {Singular}" rather than staying a bare path.
     */
    private function resourceMetadata(ExtractedEndpointData $endpointData, string $key, ?string $relative): array
    {
        [$singular, $plural] = $this->resourceNouns($key);

        $suffix = $relative === null ? '' : ' for a '.Str::headline($relative);
        $scoped = $relative === null ? '' : ' Scoped through the parent '.Str::headline($relative).' binding.';

        $method = $endpointData->method?->getName() ?? '';
        $one = $this->article($singular).' '.Str::lower($singular);

        [$title, $description] = match ($method) {
            'index' => ["List {$plural}", 'Paginated list of '.Str::lower($plural).'.'.$this->facetsNote($key)],
            'show' => ["Show {$singular}", 'A single '.Str::lower($singular).' by id.'],
            'store' => ["Create {$singular}", "Create {$one}."],
            'update' => ["Update {$singular}", "Update {$one}."],
            'destroy' => ["Delete {$singular}", "Delete {$one}."],
            default => [Str::headline($method).' '.$singular, ''],
        };

        return array_filter([
            'title' => $title.$suffix,
            'description' => trim($description.$scoped),
        ], fn ($value) => $value !== '');
    }

    /**
     * Title + description for a `Route::particleOp` mount, off the registered operation's declaration.
     * A mounted-but-unregistered op (a reportable absence, not a build failure — the response strategy
     * makes the same call) still titles off the route's name default.
     */
    private function operationMetadata(string $resource, string $name): array
    {
        [$singular] = $this->resourceNouns($resource);
        $action = Str::headline($name);

        $kind = app(ParticleOperationRegistry::class)->find($resource, $name)?->kind;

        // A Read op is noun-shaped ("Media Download", "Context Scope Embeddings"); the mutating kinds
        // read as verbs ("Run Circuit", "Ingest Media").
        $title = $kind === OperationKind::Read
            ? "{$singular} {$action}"
            : "{$action} {$singular}";

        $description = match ($kind) {
            OperationKind::Read => "Read operation `{$name}` on the `{$resource}` resource.",
            OperationKind::Write => "Write operation `{$name}` on the `{$resource}` resource.",
            OperationKind::Task => "Task operation `{$name}` on the `{$resource}` resource — honours the `?async` flag to run queued or inline.",
            OperationKind::Stream => "Streaming operation `{$name}` on the `{$resource}` resource — responds as `text/event-stream`.",
            null => "Operation `{$name}` on the `{$resource}` resource.",
        };

        return ['title' => $title, 'description' => $description];
    }

    /**
     * The [singular, plural] display nouns for a resource key: the declaration's nav `label` when one is
     * set (the resource named itself), a {@see Str::headline} of the registry key otherwise. A declared
     * `singularLabel` overrides the inflected singular — the seam for mass/irregular nouns the inflector
     * mangles (`media` singularizes to "Medium"; `singularLabel: 'Media'` keeps the op title "Media
     * Download"). The inflector cannot make this call itself: `Agents` → `Agent` and `Media` → `Medium`
     * are structurally identical round-trips, so only the declaration can say which noun is mass.
     */
    private function resourceNouns(string $key): array
    {
        $registry = app(ParticleResourceRegistry::class);

        $declared = $registry->has($key) ? $registry->get($key) : null;
        $noun = ($declared?->label ?? '') !== '' ? $declared->label : Str::headline($key);

        $singular = ($declared?->singularLabel ?? '') !== '' ? $declared->singularLabel : Str::singular($noun);

        return [$singular, Str::plural($noun)];
    }

    /** The indefinite article for a noun — "an agent", "a circuit". */
    private function article(string $noun): string
    {
        return preg_match('/^[aeiou]/i', $noun) ? 'an' : 'a';
    }

    /** The index facets note, only when the declaration actually rides the data-filters builder. */
    private function facetsNote(string $key): string
    {
        $registry = app(ParticleResourceRegistry::class);

        return $registry->has($key) && $registry->get($key)->filterable
            ? " Supports the resource's declared filter and sort facets."
            : '';
    }
}
