<?php

namespace Splicewire\Beam\Rendering\Http;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Rendering\Data\RenderingDescriptorData;
use Splicewire\Beam\Rendering\Data\ResourceRenderingCatalogData;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;

/**
 * Publish what renderings a resource offers, and in what formats — the discovery half of
 * {@see Particle::renderings()} (api-surface-coherence ticket 09 §7, build 33).
 *
 * Before this, that question was answerable only by reading `beam.core.renderings` config or running
 * `splicewire:beam:manifests` — a shell, not a wire.
 *
 * **It follows the six-for-six pattern rather than inventing one.** `filter-schema/{resource}`,
 * `capabilities`, `stud/frame/manifest`, `beam/workflows/catalog`, `splice/composition-profiles` and
 * `fragments/ingest/options` all type-hint their concrete registry, call its own accessor, and wrap in
 * a `data` envelope. Not one goes through `ManifestIndex`, and 09 §7 closed that branch permanently.
 *
 * **What it reports is the MOUNTED surface, enriched with live declarations.** The per-rendering verb
 * grant rides the route's `defaults` — frozen at mount time by the certifier, the same array the read
 * routes freeze — while `formats()` and the delivery facts are re-read from the registry per request.
 * That split is the macro's own discipline: freeze only the verb, never the enumeration. A catalog that
 * re-certified at request time could advertise a `POST` that no route serves.
 *
 * @group Renderings
 */
class RenderingCatalogController extends Controller
{
    use AuthorizesRequests;

    /** The route default the macro stamps this route's per-resource config under. */
    public const CONFIG = '_renderings_catalog';

    public function __construct(private ResourceRenderingRegistry $registry) {}

    /**
     * What this resource can be rendered as
     *
     * Every rendering mounted for this resource, with the formats it accepts, the media types and
     * headers it delivers, and whether it carries a write verb. Nothing here loads a record — it is the
     * declaration, so it answers the same for every record of the resource.
     */
    #[ResponseFromData(ResourceRenderingCatalogData::class)]
    public function index(Request $request)
    {
        $config = $request->route()->defaults[self::CONFIG] ?? null;

        if (! is_array($config) || ! isset($config['resource'], $config['renderings'])) {
            throw new RuntimeException(
                'Rendering catalog route is missing its _renderings_catalog config. '
                .'Register it via Particle::renderings().'
            );
        }

        $this->authorizeCatalog($config);

        $resource = (string) $config['resource'];
        $descriptors = [];

        foreach ((array) $config['renderings'] as $name => $grant) {
            $rendering = $this->registry->find($resource, (string) $name);

            // Mounted, then de-registered. The read route is equally stale; reporting a rendering whose
            // declarations can no longer be read would mean inventing them.
            if ($rendering === null) {
                continue;
            }

            $descriptors[] = RenderingDescriptorData::fromRendering(
                $rendering,
                (string) ($grant['fidelity'] ?? ''),
                (bool) ($grant['writable'] ?? false),
            );
        }

        return ResponseBody::from(['data' => new ResourceRenderingCatalogData(
            resource: $resource,
            renderings: $descriptors,
        )]);
    }

    /**
     * Gate the catalog on the CLASS-level ability the resource's own index requires.
     *
     * There is no record here, so the per-record `view` the read routes authorize has nothing to
     * authorize against; `viewAny` is the ability that means "may see that these exist". Deriving it
     * from the ability map rather than adding a slot keeps the macro's existing contract intact —
     * including the part that matters most: an EMPTY map stays an explicit, deliberate "gated elsewhere"
     * (09 §1), not a silence this method gets to reinterpret.
     *
     * The gate is here at all because of what ticket 10 §5 found one surface over: a flat
     * `filter-options/{key}` had no resource to check against and so enumerated every silo, tag and
     * agent name to any authenticated user. A discovery route that knows its resource has no excuse.
     *
     * @param  array<string, mixed>  $config
     */
    private function authorizeCatalog(array $config): void
    {
        $abilities = (array) ($config['abilities'] ?? []);

        if ($abilities === []) {
            return;
        }

        $this->authorize('viewAny', (string) $config['subject']);
    }
}
