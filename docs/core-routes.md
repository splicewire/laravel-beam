# Core HTTP routes

`Splicewire\Beam\Routing\CoreRoutes::mount()` registers the core hooks and realms endpoints at `beam.core.api_root`, whose package default is `beam/core` (ADR-0243). Call it inside the host's authentication and tenant/realm boundary, before site or SPA catch-alls, without a versioned prefix around that boundary. No provider mounts this surface automatically.

The hook exposure retains index, show, update, delete, reset, discovery, the event catalog and deliveries. Creation remains the reveal-once Frame handler. The mount inherits host middleware and domains; it supplies no authentication or tenant selection of its own. New route names begin `beam.core.` so the old mounts can coexist.

During the expand stage, keep existing host mounts. Host adoption is a separate step; installing this package does not switch callers. Remove legacy mounts only at the owner-authorized contract stage.

The particle registry is the ownership source. A generic Beam controller can answer another package's resource, which does not make it a core endpoint. In particular, core's `schemas` declaration has a specialized stem/version HTTP surface implemented in Tower; it is not generic schema-row CRUD and is not mounted here. Frame-only resources do not gain REST endpoints merely by appearing in the registry.
