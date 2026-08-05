<?php

namespace Splicewire\Beam\Codegen;

use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Operation;
use Rushing\Codegen\Model\Type;
use Splicewire\Beam\Source\RouteManifestSource;

/**
 * Bridges beam's route manifest (codegen-substrate #05) into the canonical {@see CodegenModel} the
 * codegen seam consumes. Each route becomes an {@see Operation} carrying its
 * path, HTTP verbs, and — when the route surfaced one — its return DTO as an EXTERNAL type ref (the
 * `App.Data.X` type is emitted by the separate spatie typescript-transform, not defined in this
 * model). The realm rides in `meta['realm']` so a driver can pick the right client (`api`/`adminApi`).
 *
 * Order is preserved (tenant entries then admin entries, each in manifest order) so a generated
 * artifact stays byte-stable across runs — the whole point of committing the output.
 *
 * Beam's own command wires this directly; a host that prefers the generic `codegen:generate` path can
 * adapt it to `rushing/laravel-codegen`'s `ModelSource` (beam does not depend on that binding, only on
 * the framework-free `rushing/codegen` core).
 */
class RouteManifestModelSource
{
    public function __construct(
        private ?RouteManifestSource $tenant = null,
        private ?RouteManifestSource $admin = null,
    ) {}

    public function model(): CodegenModel
    {
        $model = new CodegenModel;

        $this->addRealm($model, $this->tenant?->toArray() ?? [], 'tenant');
        $this->addRealm($model, $this->admin?->toArray() ?? [], 'admin');

        return $model;
    }

    /**
     * @param  array<string, array{path: string, methods: list<string>, returns?: string, returnsMany?: bool}>  $manifest
     */
    private function addRealm(CodegenModel $model, array $manifest, string $realm): void
    {
        foreach ($manifest as $name => $entry) {
            $methods = $entry['methods'] ?? [];

            $model->operation(
                name: $name,
                method: $methods[0] ?? 'GET',
                path: $entry['path'],
                returns: isset($entry['returns']) ? Type::external($entry['returns']) : null,
                returnsMany: (bool) ($entry['returnsMany'] ?? false),
                methods: $methods,
                meta: ['realm' => $realm],
            );
        }
    }
}
