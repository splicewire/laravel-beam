<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Symfony\Component\Console\Input\InputOption;

/**
 * `splicewire:beam:make:particle-op` — mints a conformant `#[ParticleOp]` class with all three shape slots
 * declared, plus the input and output Data classes those slots name.
 *
 * ## The stub is KIND-CORRECT, and that is the load-bearing part
 * `output:` is kind-dependent: a Read/Write/Task resolves one payload, so a single class-string says
 * everything; a Stream emits discrete typed events under distinct wire names, so it takes
 * `[eventName => [DataClass, …]]`. {@see ParticleOperation} REJECTS the wrong form
 * in its constructor — a map on a non-stream kind, a bare class-string on a Stream — so a generator that
 * emitted one shape for all four kinds would mint code that fatals at registration. Hence one stub per kind
 * family (`particle-op`, `particle-op-task`, `particle-op-stream`) rather than one stub with a substituted
 * output line: the three differ in their handler SIGNATURE and BODY too, not just that one slot.
 *
 *   - Read/Write — `handle($model, $request, $actor)` returns the declared payload;
 *   - Task       — `handle()` returns a `ShouldQueue` JOB, so the stub also emits the `respond()` projector
 *                  without which the `output:` declaration would be untrue;
 *   - Stream     — `handle()` takes a 4th `Emitter $emit` and the output map is keyed by wire event name.
 *
 * ## `ability:` gets a kind-derived default rather than being left null
 * The slot exists so authorization is deny-default, and an emitted `ability: null` teaches the opposite of
 * what the slot is for. A read defaults to `view`, a mutation to `update` — both wrong often enough to be
 * noticed and edited, which is the point; silence would not be.
 *
 * ## `affordance:` is emitted explicitly, `false` unless `--affordance=action`
 * Leaving the slot off is the UNDECLARED state `particle.operation-affordance` counts on a Write, so the stub
 * always writes one. `false` (API-only) is the default because a button is a decision about a screen this
 * command cannot see; the explicit `false` is one word to flip.
 *
 * ## What it deliberately does NOT do
 * It does not mount a route and does not create the resource. An op hangs off an existing particle resource
 * key; if that key is not registered, the op's route resolves to nothing and that is a wiring error a
 * generator should surface rather than paper over by inventing a resource.
 */
class MakeParticleOpCommand extends ParticleGeneratorCommand
{
    protected $name = 'splicewire:beam:make:particle-op';

    protected $description = 'Scaffold a conformant #[ParticleOp] class with kind-correct input/output/ability slots and the Data classes they name.';

    protected $type = 'Particle operation';

    /**
     * The deny-default ability each kind starts from — a query gate for the two read-shaped kinds, a mutation
     * gate for the two that change or act on the record.
     *
     * @var array<string, string>
     */
    protected const DEFAULT_ABILITY = [
        'read' => 'view',
        'write' => 'update',
        'task' => 'update',
        'stream' => 'view',
    ];

    /** Resolved once in handle() so the stub tokens and both companion writes agree on every derived name. */
    protected OperationKind $resolvedKind = OperationKind::Write;

    protected string $resolvedModel = '';

    protected string $resolvedResource = '';

    protected string $resolvedOpName = '';

    protected string $resolvedAbility = '';

    protected string $resolvedEvent = '';

    protected string $resolvedAffordance = 'false';

    protected string $inputClass = '';

    protected string $outputClass = '';

    protected function getStub(): string
    {
        return $this->resolveStubPath(match ($this->resolvedKind) {
            OperationKind::Task => 'stubs/particle-op-task.stub',
            OperationKind::Stream => 'stubs/particle-op-stream.stub',
            default => 'stubs/particle-op.stub',
        });
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Particle\Operations';
    }

    /**
     * Declares `int` where {@see GeneratorCommand::handle()} declares nothing and returns
     * `bool|null` — deliberately. `Command::execute()` casts the return, so the framework's `false` becomes
     * exit code 0 and a refusal to generate reports SUCCESS. A generator whose failures are invisible to CI
     * (and to an agent reading the exit code) is worse than no generator, so the bool is mapped to a real code
     * here rather than passed through.
     */
    public function handle(): int
    {
        $kind = OperationKind::tryFrom(strtolower((string) $this->option('kind')));

        if ($kind === null) {
            $this->components->error(sprintf(
                'Unknown --kind [%s]. One of: %s.',
                (string) $this->option('kind'),
                implode(', ', array_column(OperationKind::cases(), 'value')),
            ));

            return self::FAILURE;
        }

        $this->resolvedKind = $kind;

        $base = Str::replaceLast('Op', '', class_basename($this->getNameInput()));

        $resource = $this->option('resource');
        $this->resolvedResource = is_string($resource) && $resource !== ''
            ? $resource
            : $this->keyFrom($base);

        $op = $this->option('op');
        $this->resolvedOpName = is_string($op) && $op !== '' ? $op : Str::kebab($base);

        $model = $this->option('model');
        $this->resolvedModel = $this->qualifyModel(
            is_string($model) && $model !== ''
                ? $model
                : Str::studly(Str::singular(str_replace('-', '_', $this->resolvedResource)))
        );

        // tutorial-dx 06: refuse, before anything is written, what would emit an unchecked or contradictory declaration.
        if (($refusal = $this->intentRefusal($kind, is_string($model) && $model !== '')) !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        $ability = $this->option('ability');
        $this->resolvedAbility = is_string($ability) && $ability !== ''
            ? $ability
            : self::DEFAULT_ABILITY[$kind->value];

        // `affordance:` is emitted EXPLICITLY on every kind (particle-operation-surface 21): an omitted slot
        // is the undeclared state `particle.operation-affordance` counts, so the generator never writes one.
        // `false` (API-only) is the default because drawing a button is a presentation decision about a
        // screen this command cannot see; `--affordance=action` draws it.
        $affordance = strtolower((string) $this->option('affordance'));

        if (! in_array($affordance, ['action', 'false'], true)) {
            $this->components->error("Unknown --affordance [{$affordance}]. One of: action, false.");

            return self::FAILURE;
        }

        $this->resolvedAffordance = $affordance === 'action' ? "'action'" : 'false';

        $event = $this->option('event');
        $this->resolvedEvent = is_string($event) && $event !== ''
            ? $event
            : Str::snake($base).'_status';

        $qualified = $this->qualifyClass($this->getNameInput());

        if ($this->modelNameCollides($qualified, $this->resolvedModel)) {
            return self::FAILURE;
        }

        $this->inputClass = $this->dataNamespace().'\\'.$base.'InputData';
        // `--output` names a class that already exists, so no output class is emitted for the next step to delete.
        $named = $this->namedOutput();
        $this->outputClass = $named ?? $this->dataNamespace().'\\'.$base.'OutputData';

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->writeCompanion('stubs/particle-data.stub', $this->inputClass, [
            'purpose' => sprintf(
                'The declared `input:` shape of the `%s` operation on `%s` — validated by '
                .'ParticleOperationController before the handler runs.',
                $this->resolvedOpName,
                $this->resolvedResource,
            ),
        ]);

        if ($named === null) {
            $this->writeOutputCompanion($kind);
        }

        $this->report(sprintf(
            "Particle::ops('%s', '%s', [%s::class]);",
            $this->resolvedResource,
            $this->resolvedResource,
            class_basename($qualified),
        ));

        return $this->verified();
    }

    /**
     * tutorial-dx 06: why this run must not write anything, or null. An omitted `--model` is derived from the resource
     * key, and a derived model that does not exist would be written as an unchecked `use App\Models\…;` (the tutorial's
     * `PurchasePlan` on `plans`, which has no `Plan`); an explicit `--model` is the author's decision and is kept as
     * given. `--no-subject` and `--output` fit the read/write stub only.
     */
    protected function intentRefusal(OperationKind $kind, bool $modelGiven): ?string
    {
        $singleOutput = in_array($kind, [OperationKind::Read, OperationKind::Write], true);

        if ($this->option('no-subject') && ! $singleOutput) {
            return sprintf('--no-subject applies to read and write operations; a %s operation\'s handler works on its subject.', $kind->value);
        }

        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            if (! $singleOutput) {
                return sprintf('--output names one Data class, which a read or write operation declares; a %s operation declares its own output shape.', $kind->value);
            }
            if (! $this->classExistsInHost($this->qualifyOutput($output))) {
                return sprintf('--output names [%s], which does not exist. Name an existing Data class, or omit --output to emit one.', $this->qualifyOutput($output));
            }
        }

        if (! $modelGiven && ! $this->option('no-subject') && ! $this->classExistsInHost($this->resolvedModel)) {
            return sprintf(
                'No --model was given, and the model derived from the resource key, [%s], does not exist. Name the model '
                .'with --model, or declare the operation subject-free with --no-subject.',
                $this->resolvedModel,
            );
        }

        return null;
    }

    /** The `--output` class, qualified, or null to emit one. */
    protected function namedOutput(): ?string
    {
        $output = $this->option('output');

        return is_string($output) && $output !== '' ? $this->qualifyOutput($output) : null;
    }

    protected function qualifyOutput(string $output): string
    {
        $output = ltrim($output, '\\');

        return str_contains($output, '\\') ? $output : $this->dataNamespace().'\\'.$output;
    }

    /** Loaded or autoloadable, or a file at the path the host's root namespace maps it to. */
    protected function classExistsInHost(string $class): bool
    {
        return class_exists($class) || $this->files->exists($this->getPath($class));
    }

    protected function writeOutputCompanion(OperationKind $kind): void
    {
        $this->writeCompanion('stubs/particle-data.stub', $this->outputClass, [
            'purpose' => $kind === OperationKind::Stream
                ? sprintf(
                    'One frame of the `%s` stream, emitted under the `%s` wire event name.',
                    $this->resolvedOpName,
                    $this->resolvedEvent,
                )
                : sprintf(
                    'The declared `output:` shape of the `%s` operation on `%s`.',
                    $this->resolvedOpName,
                    $this->resolvedResource,
                ),
        ]);
    }

    /**
     * The check the op tests assert: the op, registered through real attribute discovery (which enforces
     * `handle()` and the kind guard) and mounted as the printed line mounts it, leaves no undeclared surface.
     */
    protected function declarationFindings(): array
    {
        $op = $this->loadEmitted()[array_key_first($this->emitted)];
        $this->laravel->make(AttributedParticleDiscovery::class)->discover(classes: [$op]);

        return $this->undeclaredWhenMounted(
            fn () => Particle::ops($this->resolvedResource, $this->resolvedResource, [$op]),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function tokens(): array
    {
        return [
            'resource' => $this->resolvedResource,
            'uri' => $this->resolvedResource,
            'opName' => $this->resolvedOpName,
            'kind' => Str::studly($this->resolvedKind->value),
            'kindLabel' => $this->resolvedKind->value,
            'ability' => $this->resolvedAbility,
            'affordance' => $this->resolvedAffordance,
            'event' => $this->resolvedEvent,
            'model' => class_basename($this->resolvedModel),
            'namespacedModel' => $this->resolvedModel,
            'inputClass' => class_basename($this->inputClass),
            'namespacedInputClass' => $this->inputClass,
            'outputClass' => class_basename($this->outputClass),
            'namespacedOutputClass' => $this->outputClass,
            // tutorial-dx 06 (the read/write stub): a subject-free op declares it, imports no model and takes `mixed $subject`
            // (null at run time); an `--output` class is built from what the op has, not from a placeholder it lacks.
            ...$this->subjectTokens(),
        ];
    }

    /** @return array<string, string> */
    protected function subjectTokens(): array
    {
        $noSubject = (bool) $this->option('no-subject');
        $output = class_basename($this->outputClass);

        return [
            'modelImport' => $noSubject ? '' : 'use '.$this->resolvedModel.";\n",
            'noSubjectImport' => $noSubject ? "use Splicewire\\Beam\\Particle\\Subject\\NoSubject;\n" : '',
            'subjectSlots' => $noSubject ? "    subject: NoSubject::class,\n    abilityModel: false,\n" : '',
            'subjectParam' => $noSubject ? 'mixed $subject' : class_basename($this->resolvedModel).' $model',
            'outputReturn' => match (true) {
                $this->namedOutput() === null => "new {$output}(placeholder: \$input->placeholder)",
                $noSubject => "{$output}::from(\$input)",
                default => "{$output}::from(\$model)",
            },
        ];
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: int, 3: string, 4?: mixed}>
     */
    protected function getOptions(): array
    {
        return [
            ['kind', null, InputOption::VALUE_REQUIRED, 'read | write | task | stream — decides the handler signature AND the shape of the output slot', OperationKind::Write->value],
            ['resource', 'r', InputOption::VALUE_REQUIRED, 'The particle resource key this op hangs off (default: the kebab-cased plural of <Name>)'],
            ['op', 'o', InputOption::VALUE_REQUIRED, 'The operation slug in the URL, …/{id}/{name} (default: the kebab-cased <Name>)'],
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The Eloquent model to TYPE-HINT `handle()` with (default: derived from the resource key). It no longer emits a `model:` slot — particle-operation-surface 18 retired that, and the subject is resolved from the resource'],
            ['ability', 'a', InputOption::VALUE_REQUIRED, 'The ability checked before the op runs (default: view for read/stream, update for write/task)'],
            ['affordance', null, InputOption::VALUE_REQUIRED, "action | false — whether frame draws the op as an action on its resource's framed screen (a form from `input:`), or keeps it API-only. Emitted explicitly either way", 'false'],
            ['event', 'e', InputOption::VALUE_REQUIRED, 'Stream only: the wire event name the output map is keyed by (default: <name>_status)'],
            ['data-namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the emitted input/output Data classes (default: App\Data)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the operation class if it already exists'],
            ['no-subject', null, InputOption::VALUE_NONE, 'Read/write only: a collection-level op (subject: NoSubject::class, abilityModel: false); no model is imported and handle() takes mixed $subject'],
            ['output', null, InputOption::VALUE_REQUIRED, 'Read/write only: name an EXISTING Data class as output: instead of emitting <Name>OutputData (a bare name resolves under the data namespace)'],
        ];
    }
}
