<?php

namespace Splicewire\Beam\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Splicewire\Beam\OpenApi\OpenApiSpecSource;
use Splicewire\Beam\OpenApi\SpecFormat;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the OpenAPI artifact at the two fixed, package-owned URLs beam mounts (ADR-0211 §1/§2):
 * `GET beam/openapi.yaml` and `GET beam/openapi.json`, one controller, one
 * {@see OpenApiSpecSource}.
 *
 * The artifact is deliberately NOT a `BeamUxEntry`. It is a build artifact, not authored content, and
 * making it an entry would leave a HEADLESS beam host — one with no `laravel-beam-ux` installed — unable
 * to serve its own spec. The docs *page* is the entry (ADR-0210 §9); it embeds
 * `<ApiReference specUrl={route('beam.openapi.yaml')} />` and points here.
 *
 * ADR-0116's concern is a package claiming UNMATCHED urls — which is why the entry renderer is
 * host-mounted (ADR-0209) — and two fixed paths are not that.
 *
 * Nothing here generates. Generation happens at install and at deploy (ADR-0211 §4): extraction reflects
 * over every route in the application, and a public `GET` must not write to storage. With no artifact,
 * both URLs 404 cleanly — which is the honest answer for a host that has not run the generator yet.
 */
class OpenApiSpecController
{
    public function __construct(private OpenApiSpecSource $source) {}

    public function yaml(Request $request): Response
    {
        return $this->serve($request, SpecFormat::Yaml);
    }

    public function json(Request $request): Response
    {
        return $this->serve($request, SpecFormat::Json);
    }

    private function serve(Request $request, SpecFormat $format): Response
    {
        $spec = $this->source->spec($format, $request);

        if ($spec === null) {
            throw new NotFoundHttpException(
                'No OpenAPI artifact has been generated for this host. Run `php artisan scribe:generate`.',
            );
        }

        $headers = ['Content-Type' => $format->contentType()];

        // A meaningful mtime becomes a real `Last-Modified`, so a CDN or a conditional request has
        // something to work with. A source with no timestamp (in-memory, remote) simply omits it rather
        // than inventing "now", which would defeat caching for every caller.
        if ($spec->lastModified > 0) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $spec->lastModified).' GMT';
        }

        return response($spec->body, 200, $headers);
    }
}
