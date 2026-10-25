<?php

namespace Splicewire\Beam\Console;

/**
 * `splicewire:beam:make:particle-backing` — mints a `ResourceBacking` with exactly the capability
 * sub-interfaces asked for (scaffold-family ticket 01). The port is a method-less MARKER by design
 * (its own docblock owns the why), so this generator's whole job is assembling capabilities:
 * `QueriesRecords` by default (`--stream` swaps it for `StreamsRecords`), `--writes`, `--resolves`,
 * and `--model=` which adds `BacksModel` per the tier rule ("every record one Eloquent model MUST
 * declare it").
 */
class MakeParticleBackingCommand extends ParticleGeneratorCommand
{
    protected $name = 'splicewire:beam:make:particle-backing';

    protected $description = 'Scaffold a ResourceBacking with the requested capability sub-interfaces stubbed.';

    protected $type = 'Particle backing';

    /** @var array<string, array{import: string, methods: string}> capability name => emission */
    protected array $capabilities = [];

    protected string $resolvedModel = '';

    protected function getStub(): string
    {
        return $this->resolveStubPath('stubs/particle-backing.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Particles\Backings';
    }

    public function handle(): ?bool
    {
        $this->capabilities = [];

        if ($this->option('stream')) {
            $this->capability('StreamsRecords', <<<'PHP'
                public function records(array $filters, ?string $cursor, int $perPage): \Illuminate\Contracts\Pagination\CursorPaginator
                {
                    // The stream IS the read path on this capability: filters in, one page out.
                    throw new \LogicException('records() not implemented yet.');
                }
            PHP);
        } else {
            $this->capability('QueriesRecords', <<<'PHP'
                public function query(array $filters): \Illuminate\Database\Eloquent\Builder
                {
                    // The declared read path: every index/show funnels through this builder.
                    throw new \LogicException('query() not implemented yet.');
                }
            PHP);
        }

        if ($this->option('writes')) {
            $this->capability('WritesRecords', <<<'PHP'
                public function resolveForWrite(string $id, array $filters): ?\Splicewire\Beam\Particle\Backing\WritableRecord
                {
                    throw new \LogicException('resolveForWrite() not implemented yet.');
                }

                public function newRecord(): \Splicewire\Beam\Particle\Backing\WritableRecord
                {
                    throw new \LogicException('newRecord() not implemented yet.');
                }
            PHP);
        }

        if ($this->option('resolves')) {
            $this->capability('ResolvesRecord', <<<'PHP'
                public function resolve(string $id, array $filters): ?\Splicewire\Beam\Particle\Backing\ResolvedRecord
                {
                    throw new \LogicException('resolve() not implemented yet.');
                }
            PHP);
        }

        $model = $this->option('model');

        if (is_string($model) && $model !== '') {
            $this->resolvedModel = $this->qualifyModel($model);
            $this->capability('BacksModel', sprintf(<<<'PHP'
                public function modelClass(): string
                {
                    return \%s::class;
                }
            PHP, $this->resolvedModel));
        }

        return parent::handle() === false ? self::FAILURE : self::SUCCESS;
    }

    protected function capability(string $name, string $methods): void
    {
        $this->capabilities[$name] = [
            'import' => 'use Splicewire\\Beam\\Particle\\Backing\\'.$name.';',
            'methods' => $methods,
        ];
    }

    protected function tokens(): array
    {
        $imports = array_map(fn ($c) => $c['import'], $this->capabilities);
        $imports[] = 'use Splicewire\\Beam\\Particle\\Backing\\ResourceBacking;';
        sort($imports);

        $interfaces = ['ResourceBacking', ...array_keys($this->capabilities)];
        sort($interfaces);

        $methods = implode("\n\n", array_map(
            fn ($c) => '    '.str_replace("\n", "\n    ", trim($c['methods'])),
            array_values($this->capabilities),
        ));

        return [
            'capabilityImports' => implode("\n", $imports),
            'capabilityInterfaces' => implode(', ', $interfaces),
            'capabilityMethods' => $methods,
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['model', null, 4, 'Eloquent model every record is an instance of — adds BacksModel (the tier rule)'],
            ['stream', null, 1, 'Cursor-stream read capability (StreamsRecords) instead of QueriesRecords'],
            ['writes', null, 1, 'Add WritesRecords (resolveForWrite/newRecord)'],
            ['resolves', null, 1, 'Add ResolvesRecord (single-record resolve)'],
        ];
    }
}
