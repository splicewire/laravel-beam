<?php

namespace Splicewire\Beam\Console;

use Illuminate\Support\Str;

/**
 * `splicewire:beam:make:workflow` — mints a `laravel-beam-workflows` definition set whose four
 * declaration sites share ONE constant spelling (scaffold-family ticket 02, B5-4: a workflow was
 * four hand-tied sites with zero shipped commands).
 *
 * Emits the workflow class (constants + `blueprint()`, the ListingReviewWorkflow shape) and its two
 * handler companions (guard + effect, faithful invokable signatures), then PRINTS the three wiring
 * snippets that belong in files this generator must not edit — the provider's catalog
 * registrations, the registrar's type→lineage binding, and the model's `WorkflowManaged` wiring —
 * each spelled off the emitted constants, so the key never exists twice.
 *
 * Homed in beam-core with the rest of the make-surface (architecture-pass C5's charter: non-core
 * scaffolds belong at beam tier; beam-dev is family-blind). Emission is TEXT, so this command does
 * not require beam-workflows at runtime — it warns when the target package is absent instead of
 * failing, because scaffolding ahead of `composer require` is a legitimate order of operations.
 */
class MakeWorkflowCommand extends ParticleGeneratorCommand
{
    protected $name = 'splicewire:beam:make:workflow';

    protected $description = 'Scaffold a beam-workflows definition: the key-constant class, blueprint(), guard + effect handlers, and the three wiring snippets.';

    protected $type = 'Workflow definition';

    protected string $resolvedKey = '';

    protected string $resolvedInitial = '';

    protected string $gateClass = '';

    protected string $effectClass = '';

    protected function getStub(): string
    {
        return $this->resolveStubPath('stubs/workflow.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Workflows';
    }

    public function handle(): ?bool
    {
        if (! class_exists(\Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint::class)) {
            $this->components->warn(
                'splicewire/laravel-beam-workflows is not installed here — emitting anyway (text), '
                .'but the generated class only compiles once it is required.',
            );
        }

        $base = Str::replaceLast('Workflow', '', class_basename($this->getNameInput()));

        $key = $this->option('key');
        $this->resolvedKey = is_string($key) && $key !== '' ? $key : Str::kebab($base);

        $initial = $this->option('initial');
        $this->resolvedInitial = is_string($initial) && $initial !== '' ? $initial : 'draft';

        $namespace = $this->getDefaultNamespace($this->rootNamespace()).'\\'.$base;
        $this->gateClass = $namespace.'\\'.$base.'Gate';
        $this->effectClass = $namespace.'\\'.$base.'Recorder';

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->writeCompanion('stubs/workflow-guard.stub', $this->gateClass, [
            'purpose' => sprintf(
                'The `%s` guard (`%s::GATE_GUARD`) — whether `approve` is enabled for a subject.',
                $this->resolvedKey.'.gate',
                $base.'Workflow',
            ),
        ]);

        $this->writeCompanion('stubs/workflow-effect.stub', $this->effectClass, [
            'purpose' => sprintf(
                'The `%s` effect (`%s::RECORD_EFFECT`) — the durable consequence of approve/reject.',
                $this->resolvedKey.'.record',
                $base.'Workflow',
            ),
        ]);

        $workflow = class_basename($this->qualifyClass($this->getNameInput()));

        $this->components->info('Three wiring snippets, each spelled off the emitted constants:');
        $this->line(<<<SNIPPETS

        // 1. Provider — the guard/effect CATALOG entries (in-memory, boot-time; the
        //    registerListingReviewSeam() shape):
            \$this->app->make(\Splicewire\Beam\Workflows\Control\GuardRegistry::class)->register(
                {$workflow}::GATE_GUARD,
                \$this->app->make(\\{$this->gateClass}::class),
                label: '{$base} gate',
            );
            \$this->app->make(\Splicewire\Beam\Workflows\Control\TransitionEffectRegistry::class)->register(
                {$workflow}::RECORD_EFFECT,
                \$this->app->make(\\{$this->effectClass}::class),
                label: 'Record {$this->resolvedKey} outcome',
                paramsSchema: ['type' => 'object', 'properties' => ['approved' => ['type' => 'boolean']]],
            );

        // 2. Registrar — the DB-backed lineage + type→lineage binding (the
        //    ListingReviewRegistrar::ensureRegistered() shape):
            \$this->bindings->bind({$workflow}::TYPE_KEY, {$workflow}::LINEAGE_KEY);

        // 3. The managed model — identity is declared, never defaulted:
            use \Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged;

            public function workflowType(): string
            {
                return \\{$this->getDefaultNamespace($this->rootNamespace())}\\{$workflow}::TYPE_KEY;
            }
        SNIPPETS);

        return self::SUCCESS;
    }

    protected function tokens(): array
    {
        return [
            'key' => $this->resolvedKey,
            'initial' => $this->resolvedInitial,
            'gateClass' => class_basename($this->gateClass),
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['key', null, 4, 'The lineage/type key (default: kebab of the class basename minus "Workflow")'],
            ['initial', null, 4, 'The initial place name (default: draft)'],
        ];
    }
}
