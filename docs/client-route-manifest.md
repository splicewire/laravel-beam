# Routes published to the generated client

The default `ParticleRouteManifestSource` reads mounted routes from the host's router. Particle CRUD
and operation routes retain their existing projection. A controller route enters the generated client
when all four conditions hold:

- It has a non-empty route name.
- Its declared visibility is not `Deprecated`.
- It carries a non-empty, explicit `beam.operationId`.
- Its controller method declares a successful `ResponseFromData` body or `StreamsFromData` events.

This is the owner’s node-actions-and-takes #14 ruling of 2026-11-15. It uses the particle doctrine's
third declaration site, without another marker. An operationId alone, an input declaration alone,
an error response alone, or bare return metadata does not publish a controller route. Plain web
routes and unnamed routes stay outside this client source. No controller class, path prefix or
route-name suffix implies inclusion.

`RouteReturnType` projects the response using the existing declaration precedence. A
`ResponseFromData` class describes the complete HTTP body, so the entry carries `returnsBody: true`.
An index method whose body contains a list still returns one body; it is not a list of bodies.
Streams preserve each event name and its payload variants. `RouteRequestType` reads the method's
`RequestFromData` class into the input slot. Those request classes also join `DeclaredRouteTypes`,
so the host's configured transformer can emit the inputs its client references.
The trio determines response versus stream delivery: a stream-only declaration stays a stream even
when its route belongs to a resource or carries return metadata. Within that delivery kind, existing
metadata precedence applies. The input slot follows its declaration regardless of HTTP verb; recording
it does not change the route's verb or make the runtime send a GET body.

`ClientTypeReferences` remains strict: a referenced input, response or stream DTO that the host
does not emit stops generation. The manifest does not invent a payload type or widen it to make
generation pass. The route's own URI, HTTP methods and parameter coordinates remain authoritative.
This projection changes neither route registration nor its middleware, policy or runtime authorization.

## Development fixture

`splicewire/laravel-beam-versioning` is a **require-dev dependency only**, with no production edge.
`tests/Source/DeclaredControllerManifestTest.php` mounts its real `recordVersions` macro inside a
route group and checks the three routes, body projections, inputs and publication exclusions.
The peer's `README.md`, `src/BeamVersioningServiceProvider.php` and `src/Http/VersionsController.php`
are the reference for that fixture, under `vendor/splicewire/laravel-beam-versioning/` after a dev install.
Its versioning mechanism remains in `rushing/laravel-versioning`; beam does not duplicate the macro.
