<?php

namespace Splicewire\Beam\Routing;

use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * The HTTP verb a particle operation is mounted under — the enum behind
 * {@see ParticleOperation::$method} and its {@see ParticleOp} twin
 * (particle-operation-surface tickets 11 and 14).
 *
 * ## This slot MOVES a capability, it does not invent one
 *
 * The verb was already choosable — at the mount, as `Particle::ops(…, ['method' => 'get'])`, read by
 * {@see ParticleMounter::op()}. What it was not was *declarable*: ten mount
 * sites across seven trees each restated a verb that belongs to the operation, and the same operation
 * (`beam-ux-entry.body`) had its verb written out five separate times because five hosts mount it.
 * Ticket 11 §A2 settled the direction: the operation knows its own verb, so it says it once.
 *
 * ## `null` means POST, and that is the whole migration story
 *
 * {@see ParticleOperation::$method} is `?HttpMethod`, and `null` short-circuits to exactly the branch
 * `$options['method'] ?? 'post'` already took. So every declaration that says nothing keeps today's
 * behaviour by construction rather than by discipline, and the slot lands inert across all 29
 * attributed declarations.
 *
 * ## String-backed, lower-case, because `Router` is called by name
 *
 * `ParticleMounter::op()` mounts through `$router->{$verb}(...)`, so the backing value has to be the
 * `Illuminate\Routing\Router` method name verbatim. Keeping the case in the enum rather than at the
 * call site is what lets the mounter drop its `strtolower()` on the declared path.
 *
 * ⚠️ **No `Options`/`Head` case, deliberately.** Laravel mounts `HEAD` alongside every `GET` itself and
 * answers `OPTIONS` from the route table; neither is a verb a handler is written for. Adding one would
 * declare a mount the controller cannot serve.
 */
enum HttpMethod: string
{
    case Get = 'get';
    case Post = 'post';
    case Put = 'put';
    case Patch = 'patch';
    case Delete = 'delete';
}
