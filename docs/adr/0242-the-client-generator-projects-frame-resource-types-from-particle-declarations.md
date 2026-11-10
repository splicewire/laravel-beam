# ADR-0242 — The client generator projects frame resource types from particle declarations

**Status:** accepted — frame-foundation ruling 03, 2026-11-10, owner-overrulable.

The generated DTO tree names classes but does not associate a resource key with its rows, form or action inputs.
Beam's existing `splicewire:beam:generate:client` therefore writes `frame-resources.ts` beside its other files;
`FrameResourceTypesGenerator` projects the particle registries without filesystem or config reads. No additional
command or transformer pipeline is introduced.

`FrameResources` exports every addressable key, sorted, with four slots: `row`, `edit`, `createResult`, `actions`.
Population uses the unfiltered particle registry and `hasAddressableResource()`, including unlabelled typed
resources and excluding `frame: false` and subjects with no read Data class. Edit and creation result slots use
the base definition's classes, falling back to its read class. Action inputs come from operations declaring an
affordance, before mount or realm filtering; a confirm-only action is `null`. The row intersects the declared read
class with contribution slices, retaining their declared nullability, and unions a distinct declared `publicRead`
class. Contributions are inlined because the ambient contributed namespace does not exist under a module writer.

References use the host's own writer through `ClientTypeReferences::resolveClasses()`: qualified ambient names or
inline module imports. A missing class becomes `Record<string, unknown>` in its position and emits one warning per
resource and class. A host with no transformer can generate the map with those warnings. No generated type is
silently guessed from a PHP class name.

The file imports nothing from frame. A host registers it by extending frame's empty `FrameResourceMap` in a `.ts`
module, so TypeScript checks the registration even with `skipLibCheck`. Until registration, frame keeps its existing
untyped behavior. Save payloads remain untyped: `edit` describes form data, not necessarily the write DTO.

The map describes declarations, not arbitrary handler output. A host serving variable Collection-selected rows,
a projection closure returning another shape or a custom handler widens that key in its registration with
`Omit<FrameResources, 'key'> & { key: FrameResourceEntry }`. A second frame producer's keys must also be added there.
Per-realm `editData` overrides are not projected. These limits keep the type map a projection of declarations
rather than another runtime producer or a second resource registry.
