<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * `splicewire:beam:make:particle-resource` — mints a conformant `#[ParticleResource]` surface: the read
 * projection Data class carrying the attribute, plus the write DTO its `input:` slot names.
 *
 * ## Two files, because the resource has two shapes
 * `data:` and `input:` are the resource's read and write slots, and a generator that emitted only the read
 * class would leave `input:` either absent (the surface accepts an undeclared shape and falls back to a
 * snake-case map of whatever arrived) or dangling at a class that does not exist. Both slots point at real
 * emitted classes, so the declaration is true the moment it is written.
 *
 * ## The `Data` suffix is enforced, not suggested
 * `#[ParticleResource]` is placed on a Data class, and the estate names those `…Data`. Enforcing it here also
 * removes the collision that would otherwise be the generator's most likely crash: a read class named `Lyric`
 * in `App\Data` cannot import the model `App\Models\Lyric`. Appending the suffix makes that unreachable
 * rather than merely unlikely.
 *
 * ## What it deliberately does NOT do
 * It does not mount a route. Routing is a host concern by design (`Particle::mount($uri, $key)`) —
 * the attribute declares, the host routes — so the command prints the mount line and stops. A generator that
 * edited a route file would be guessing which route file, inside which middleware group.
 */
class MakeParticleResourceCommand extends ParticleGeneratorCommand
{
    protected $name = 'splicewire:beam:make:particle-resource';

    protected $description = 'Scaffold a conformant #[ParticleResource] read Data class and its write DTO, both shape slots declared.';

    protected $type = 'Particle resource';

    /** Resolved once in handle() so the stub tokens and the companion write agree on every derived name. */
    protected string $resolvedModel = '';

    protected string $resolvedKey = '';

    protected string $inputClass = '';

    protected function getStub(): string
    {
        return $this->resolveStubPath('stubs/particle-resource.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Data';
    }

    /**
     * Force the estate's `…Data` suffix on the read projection (see the class docblock — it is also what makes
     * the model-import collision unreachable).
     */
    protected function getNameInput(): string
    {
        $name = trim(parent::getNameInput(), '/\\');

        return str_ends_with($name, 'Data') ? $name : $name.'Data';
    }

    /**
     * Declares `int` where {@see GeneratorCommand::handle()} declares nothing and returns
     * `bool|null` — deliberately. `Command::execute()` casts the return, so the framework's `false` becomes exit
     * code 0 and a refusal to generate reports SUCCESS. A generator whose failures are invisible to CI (and to
     * an agent reading the exit code) is worse than no generator.
     */
    public function handle(): int
    {
        $base = Str::replaceLast('Data', '', class_basename($this->getNameInput()));

        $model = $this->option('model');
        $this->resolvedModel = $this->qualifyModel(is_string($model) && $model !== '' ? $model : $base);

        $key = $this->option('key');
        $this->resolvedKey = is_string($key) && $key !== '' ? $key : $this->keyFrom($base);

        $qualified = $this->qualifyClass($this->getNameInput());

        if ($this->modelNameCollides($qualified, $this->resolvedModel)) {
            return self::FAILURE;
        }

        $this->inputClass = $this->getNamespace($qualified).'\\'.$base.'InputData';

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->writeCompanion('stubs/particle-resource-input.stub', $this->inputClass, [
            'key' => $this->resolvedKey,
            'readClass' => class_basename($qualified),
        ]);

        $this->report(sprintf("Particle::mount('%s', '%s');", $this->resolvedKey, $this->resolvedKey));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function tokens(): array
    {
        return [
            'key' => $this->resolvedKey,
            'uri' => $this->resolvedKey,
            'model' => class_basename($this->resolvedModel),
            'namespacedModel' => $this->resolvedModel,
            'inputClass' => class_basename($this->inputClass),
        ];
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: int, 3: string}>
     */
    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The Eloquent model the resource resolves (default: App\Models\<Name>)'],
            ['key', 'k', InputOption::VALUE_REQUIRED, 'The registry key and data-filters resource key (default: the kebab-cased plural of <Name>)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the read Data class if it already exists'],
        ];
    }
}
