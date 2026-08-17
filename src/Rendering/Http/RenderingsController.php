<?php

namespace Splicewire\Beam\Rendering\Http;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Splicewire\Beam\Rendering\RenderedDocument;
use Splicewire\Beam\Rendering\ResourceRendering;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;
use Splicewire\Beam\Rendering\ReversibleRendering;
use Splicewire\Beam\Rendering\Subjects\ResolvesRenderingSubject;

/**
 * The one generic controller behind {@see Route::resourceRenderings()} — read a resource's rendering,
 * and (only where fidelity was certified) fold an edited one back.
 *
 * Per-route config rides the route's `defaults` as a plain serializable array, so the macro survives
 * `route:cache`. What rides there is only what must be decided at MOUNT time: the resource token, the
 * rendering name, the subject class, the ability map, and the certified fidelity + its verb grant. The
 * rendering itself is looked up from the registry at REQUEST time, so its live format enumeration is
 * never frozen into the route table.
 *
 * Two deliberate non-behaviours, because these endpoints were migrated onto the macro under a
 * no-change-to-output bar:
 *
 *  - the controller does not validate `?format=`. It forwards it (or null) and lets the rendering reject
 *    what it cannot emit, in whatever shape that surface already rejected it.
 *  - the controller does not substitute a default format. `null` means "the rendering's own default".
 *
 * @group Renderings
 */
class RenderingsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private ResourceRenderingRegistry $registry,
        private ResolvesRenderingSubject $subjects,
    ) {}

    /** Read a rendering of the subject. Always mounted. */
    public function show(Request $request, string $id)
    {
        [$config, $rendering, $subject] = $this->resolve($request, $id);

        $this->authorizeAbility($config, 'view', $subject);

        return $this->respond($rendering->render($subject, $this->format($request)));
    }

    /**
     * Fold an edited rendering back onto the subject.
     *
     * Reaching this method at all means the certifier granted the verb at mount time — a lossy rendering
     * has NO write route. The guard below is defence in depth against a stale cached route table, not the
     * mechanism: the mechanism is the absent route.
     */
    public function store(Request $request, string $id)
    {
        [$config, $rendering, $subject] = $this->resolve($request, $id);

        if (($config['writable'] ?? false) !== true || ! $rendering instanceof ReversibleRendering) {
            throw new RuntimeException(sprintf(
                'Rendering [%s] of [%s] is not certified lossless; it has no write verb. '
                .'Clear the route cache if this route was mounted under an earlier certification.',
                (string) ($config['rendering'] ?? '?'),
                (string) ($config['resource'] ?? '?'),
            ));
        }

        $this->authorizeAbility($config, 'mutate', $subject);

        return $this->respond($rendering->ingest($subject, $this->format($request), $request->all()));
    }

    /**
     * Pull the mount-time config off the route defaults, find the rendering in the registry, and resolve
     * the subject through the port.
     *
     * @return array{0: array<string, mixed>, 1: ResourceRendering, 2: object}
     */
    private function resolve(Request $request, string $id): array
    {
        $config = $request->route()->defaults['_renderings'] ?? null;

        if (! is_array($config) || ! isset($config['resource'], $config['rendering'], $config['subject'])) {
            throw new RuntimeException(
                'Rendering route is missing its _renderings config. Register it via Route::resourceRenderings().'
            );
        }

        $rendering = $this->registry->find((string) $config['resource'], (string) $config['rendering']);

        if ($rendering === null) {
            throw new RuntimeException(sprintf(
                'No rendering named [%s] is registered for resource [%s]. It was mounted from the registry, '
                .'so the registration was removed after the route table was built.',
                (string) $config['rendering'],
                (string) $config['resource'],
            ));
        }

        /** @var class-string $subjectClass */
        $subjectClass = $config['subject'];

        $subject = $this->subjects->resolve($subjectClass, $id, (array) ($config['with'] ?? []));

        return [$config, $rendering, $subject];
    }

    /** The requested format, or null for "the rendering's own default". Never defaulted here. */
    private function format(Request $request): ?string
    {
        $format = $request->input('format');

        return $format === null ? null : (string) $format;
    }

    /**
     * Authorize against the subject through the ability map. An EMPTY map is an explicit, deliberate
     * "ungated at this layer" — a resource whose gate already lives in route middleware. A missing key
     * inside a non-empty map is likewise a skip, so a read-only rendering need not name a mutate ability.
     *
     * @param  array<string, mixed>  $config
     */
    private function authorizeAbility(array $config, string $slot, object $subject): void
    {
        $abilities = (array) ($config['abilities'] ?? []);
        $ability = $abilities[$slot] ?? null;

        if (is_string($ability) && $ability !== '') {
            $this->authorize($ability, $subject);
        }
    }

    /** Transport: a raw body carries its own content type; a payload goes to the framework untouched. */
    private function respond(RenderedDocument $document)
    {
        if (! $document->raw) {
            return $document->value;
        }

        return new Response($document->body, $document->status, array_merge(
            ['Content-Type' => (string) $document->contentType],
            $document->headers,
        ));
    }
}
