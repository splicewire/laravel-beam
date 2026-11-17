<?php

namespace Splicewire\Beam\Codegen;

use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\TypeScriptTransformer\Support\Extensions\TypeScriptTransformerExtension;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;

/**
 * The shared part of every host's `TypeScriptTransformerServiceProvider`. A host calls it first, after only an
 * optional, commented `LaravelDataTypeScriptTransformerExtension(…arguments…)`, then states its writer slot
 * (and output directory when it is not Spatie's default) and any commented deviations.
 *
 * The fixed parts, in the starters' call order so a starter's configuration stays equivalent call for call:
 * the declared-particle provider (seeded by particle declarations and by routes' declared response and stream
 * DTOs, {@see DeclaredRouteTypes}), laravel-data's extension (which brings `DataClassTransformer`, the two
 * Laravel providers and `CarbonInterface` → `string`), `EnumTransformer`, `app_path()` plus every root an
 * installed package declares ({@see PackageTypeRoots}), `DateTimeInterface` → `string`, and `UploadedFile` → `File`.
 *
 * It never calls `configPath()`: the factory replaces that list, and the base provider has already set it to
 * the host's provider file, the path watch mode restarts on.
 *
 * Requires `spatie/laravel-typescript-transformer`, which every host calling this already installs because
 * its base provider ships there; Beam only suggests it.
 *
 * @api
 */
final class BeamTypeScriptTransformerExtension implements TypeScriptTransformerExtension
{
    public function __construct(
        private ?DeclaredParticleTypes $declared = null,
        private ?PackageTypeRoots $roots = null,
        private ?DeclaredRouteTypes $routes = null,
    ) {}

    public function enrich(TypeScriptTransformerConfigFactory $factory): void
    {
        $factory
            ->provider(new DeclaredParticleTransformedProvider(
                $this->declared ?? app(DeclaredParticleTypes::class),
                $this->routes ?? app(DeclaredRouteTypes::class),
            ))
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(app_path(), ...($this->roots ?? PackageTypeRoots::forHost(base_path()))->directories())
            ->replaceType(\DateTimeInterface::class, 'string')
            // An upload in a request DTO (`#[RequestFromData]` inputs are seeded since node-actions-and-takes 14) is the
            // DOM `File` a form's file input yields, the type the family's TS uses for uploads; unmapped, every host
            // carrying such a DTO (tower's fragment intake) logged "Tried replacing reference to ... UploadedFile".
            // A RAW node: Spatie resolves a string replacement through class_exists(), and on a Laravel host 'File' IS a
            // class (the global `File` facade alias), so the string 'File' emits `undefined`; the alias varies by host.
            ->replaceType(\Illuminate\Http\UploadedFile::class, new TypeScriptRaw('File'));
    }
}
