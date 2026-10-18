<?php

namespace Splicewire\Beam\Console;

use Illuminate\Support\Str;

/**
 * `splicewire:beam:make:particle-relative` — mints a conformant `#[ParticleRelative]` edge class
 * (scaffold-family ticket 01; the 15 existing declarations spread by copy, which is the exact
 * failure the chassis docblock names).
 *
 * The emitted attribute carries `child:` / `of:` / `model:` / `via:` explicitly. `via:` in
 * particular is derived here (camel plural of the child key) but EMITTED, because the relationship
 * method on the parent model is a decision whose silent drift this slot exists to make visible.
 * The docblock the stub mints carries both mount spellings (the parent-mount convenience and
 * `ParticleMountManager::relatives()`, the general form).
 */
class MakeParticleRelativeCommand extends ParticleGeneratorCommand
{
    protected $name = 'splicewire:beam:make:particle-relative';

    protected $description = 'Scaffold a conformant #[ParticleRelative] edge class with child/of/model/via declared explicitly.';

    protected $type = 'Particle relative';

    protected string $resolvedChild = '';

    protected string $resolvedOf = '';

    protected string $resolvedModel = '';

    protected string $resolvedVia = '';

    protected function getStub(): string
    {
        return $this->resolveStubPath('stubs/particle-relative.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Particles\Relatives';
    }

    public function handle(): ?bool
    {
        $base = Str::replaceLast('Relative', '', class_basename($this->getNameInput()));

        $child = $this->option('child');
        $this->resolvedChild = is_string($child) && $child !== '' ? $child : $this->keyFrom($base);

        $of = $this->option('of');

        if (! is_string($of) || $of === '') {
            $this->components->error('--of=<parent-resource-key> is required: a relative is a fact about its parent.');

            return self::FAILURE;
        }

        $this->resolvedOf = $of;

        $model = $this->option('model');
        $this->resolvedModel = $this->qualifyModel(
            is_string($model) && $model !== ''
                ? $model
                : Str::studly(Str::singular(str_replace('-', '_', $this->resolvedChild)))
        );

        $via = $this->option('via');
        $this->resolvedVia = is_string($via) && $via !== ''
            ? $via
            : Str::camel(str_replace('-', '_', $this->resolvedChild));

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Serve it on the parent mount: Particle::mount(\'%s\', \'%s\')->relatives([%s::class]);',
            Str::plural($this->resolvedOf),
            $this->resolvedOf,
            class_basename($this->qualifyClass($this->getNameInput())),
        ));

        return self::SUCCESS;
    }

    protected function tokens(): array
    {
        return [
            'child' => $this->resolvedChild,
            'of' => $this->resolvedOf,
            'namespacedModel' => $this->resolvedModel,
            'model' => class_basename($this->resolvedModel),
            'via' => $this->resolvedVia,
            'parentUri' => Str::plural($this->resolvedOf),
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['child', null, 4, 'The child resource key (default: kebab of the class basename minus "Relative")'],
            ['of', null, 4, 'The PARENT resource key this edge hangs off (required)'],
            ['model', null, 4, 'The child rows\' Eloquent model (default: studly singular of the child key)'],
            ['via', null, 4, 'The relationship method on the parent model (default: camel of the child key)'],
        ];
    }
}
