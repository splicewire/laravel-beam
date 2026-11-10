<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Schemastud\Frame\Registry\ContextManifest;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Surgeon\UnseatedNavSectionAudit;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * `splicewire:beam:make:particle-resource` — mints a conformant `#[ParticleResource]` surface: the read
 * projection Data class carrying the attribute, plus the write DTO its `input:` slot names.
 * The read projection presents a label derived from its key and an id list column. `--label=` opts out of
 * presentation explicitly; verification reads the emitted class and frame's context block for both declarations.
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
 * ## `--summary` emits a third file, and prints the line it will not write
 * A summary provider is a class the attribute NAMES (`summaryProvider:`), not one the generator can place: the
 * attribute sits on the read class, which the same run may or may not have just written and the author may have
 * since edited. So the provider is emitted and the `summaryProvider:` line is printed, exactly as the mount line
 * is. Absent the flag, the resource keeps beam's default provider (a scoped `total` count) with no file at all.
 *
 * ## `--filters`, `--cascade-policy` and `--section` (make-surface ticket 06)
 * - `--filters` emits `Data\Filters\<Name>FilterData` from the model's ACTUAL table — the columns come from the
 *   schema (`Schema::getColumns()`/`getIndexes()`, the introspection `model:show` uses), never from a guess, and a
 *   table that does not resolve refuses the whole run before anything is written. The column → `#[Filterable]`/
 *   `#[Sortable]` mapping follows the estate's existing filter classes (measured, make-surface 06 PROOF). The class
 *   names the resource by its key (`#[ResourceFilter]`); the one host step discovery needs is printed.
 * - `--cascade-policy` prints the `#[UseCascadePolicy]` attribute and import for the model, and the registrar call the
 *   attribute needs to fire. This command emits no model, so no model is ever its to edit: authored model code is
 *   never touched.
 * - `--section` declares the resource's `section:`, and when no `NavSection` seats it here — declared through
 *   {@see \Splicewire\Beam\Nav\NavSectionRegistry} or authored by hand, the same reading
 *   {@see UnseatedNavSectionAudit} uses — it warns and prints a registration line. When a hand-authored navigation
 *   cannot be read here, it says "unknown" instead. Seating is the host's decision.
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

    protected string $summaryClass = '';

    protected string $filterClass = '';

    /** @var array{table: string, properties: string, operators: list<string>, sortable: bool}|null */
    protected ?array $filterVocabulary = null;

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
        // The console kernel keeps ONE instance of this command for every call, and the shared execute() resets only
        // its own output bookkeeping. Every field below is set per invocation, so each starts empty here: a value a
        // previous call left would otherwise steer this one (its filter companion, its printed lines).
        $this->resolvedModel = $this->resolvedKey = $this->inputClass = $this->summaryClass = $this->filterClass = '';
        $this->filterVocabulary = null;

        $base = Str::replaceLast('Data', '', class_basename($this->getNameInput()));

        $model = $this->option('model');
        $this->resolvedModel = $this->qualifyModel(is_string($model) && $model !== '' ? $model : $base);

        $key = $this->option('key');
        $this->resolvedKey = is_string($key) && $key !== '' ? $key : $this->keyFrom($base);

        $qualified = $this->qualifyClass($this->getNameInput());

        if ($this->modelNameCollides($qualified, $this->resolvedModel)) {
            return self::FAILURE;
        }

        // tutorial-dx 06: `--read-only` declares no write shape (`input: false`), so there is no write DTO to emit and none
        // for the next step to delete.
        if (! $this->option('read-only')) {
            $this->inputClass = $this->getNamespace($qualified).'\\'.$base.'InputData';
        }

        // Resolved BEFORE anything is written: a filter vocabulary is read from the model's table, and a run that
        // cannot read it refuses whole rather than leaving a resource without the filters it was asked for.
        if ($this->option('filters')) {
            $this->filterVocabulary = $this->filterVocabulary();

            if ($this->filterVocabulary === null) {
                return self::FAILURE;
            }

            $this->filterClass = $this->rootNamespace().'Data\\Filters\\'.$base.'FilterData';
        }

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        if ($this->inputClass !== '') {
            $this->writeCompanion('stubs/particle-resource-input.stub', $this->inputClass, [
                'key' => $this->resolvedKey,
                'readClass' => class_basename($qualified),
            ]);
        }

        if ($this->option('summary')) {
            $this->summaryClass = $this->rootNamespace().'Particle\\Summaries\\'.$base.'SummaryProvider';
            $this->writeCompanion('stubs/particle-summary-provider.stub', $this->summaryClass, [
                'key' => $this->resolvedKey,
                'model' => class_basename($this->resolvedModel),
                'readClass' => class_basename($qualified),
            ]);
        }

        if ($this->filterVocabulary !== null) {
            $this->writeCompanion('stubs/particle-resource-filter.stub', $this->filterClass, [
                'key' => $this->resolvedKey,
                'table' => $this->filterVocabulary['table'],
                'imports' => $this->filterImports($this->filterVocabulary),
                'properties' => $this->filterVocabulary['properties'],
            ]);
        }

        // A bare mount mounts the verbs the declaration allows (tutorial-dx 01), so the bare line IS the matching one; the
        // comment names those verbs so the reader sees what it mounts.
        $this->report(sprintf(
            "Particle::mount('%s', '%s'); // %s",
            $this->resolvedKey,
            $this->resolvedKey,
            $this->option('read-only') ? 'index, show' : 'index, show, store, update, destroy',
        ));

        if ($this->filterClass !== '') {
            $this->printFilterDiscovery();
        }

        if ($this->option('cascade-policy')) {
            $this->printCascadePolicy();
        }

        $section = $this->option('section');

        if (is_string($section) && $section !== '') {
            $this->warnWhenUnseated($section);
        }

        if ($this->summaryClass !== '') {
            // Printed, not edited: the generator never rewrites a declaration it did not just write, for the
            // same reason it never edits a route file — it would be guessing at a file the author owns.
            $this->newLine();
            $this->components->info('Declare it — add to the #[ParticleResource] attribute on '.class_basename($qualified).':');
            $this->line(sprintf('    summaryProvider: \\%s::class,', $this->summaryClass));
        }

        return $this->verified();
    }

    /**
     * The check the resource tests assert: the read class, discovered and mounted as the printed line mounts it,
     * leaves no undeclared surface.
     */
    protected function declarationFindings(): array
    {
        $read = $this->loadEmitted()[array_key_first($this->emitted)];
        $this->laravel->make(AttributedParticleDiscovery::class)->discover(classes: [$read]);

        $findings = $this->undeclaredWhenMounted(fn () => Particle::mount($this->resolvedKey, $this->resolvedKey));
        $resource = $this->laravel->make(\Splicewire\Beam\Particle\ParticleResourceRegistry::class)->find($this->resolvedKey);
        if ($resource?->label !== $this->resourceLabel()) {
            $findings[] = 'The emitted resource must declare its label (use --label= for an addressable resource that is not presented).';
        }
        $block = (new ContextManifest)->forResource($read);
        $hasColumn = false;
        foreach ($block['byNode'] as $contexts) {
            $hasColumn = $hasColumn || array_key_exists('list-column', $contexts);
        }
        if (! $hasColumn) {
            $findings[] = 'The emitted resource must declare at least one list-column participant (add #[Column] to a property).';
        }

        return $findings;
    }

    protected function resourceLabel(): string
    {
        $label = $this->option('label');

        return is_string($label) ? $label : ucfirst(str_replace(['-', '_'], ' ', $this->resolvedKey));
    }

    /**
     * @return array<string, string>
     */
    protected function tokens(): array
    {
        $readClass = class_basename($this->qualifyClass($this->getNameInput()));
        $readOnly = (bool) $this->option('read-only');

        return [
            'key' => $this->resolvedKey,
            'uri' => $this->resolvedKey,
            'label' => var_export($this->resourceLabel(), true),
            'labelComment' => $this->resourceLabel() === '' ? "    // This resource is addressable and not presented.\n" : '',
            'model' => class_basename($this->resolvedModel),
            'namespacedModel' => $this->resolvedModel,
            'inputClass' => class_basename($this->inputClass),
            // tutorial-dx 06: every decision slot written out, an omission and a decision not spelled alike.
            'inputSlot' => $readOnly ? 'false' : class_basename($this->inputClass).'::class',
            'inputNote' => $readOnly ? '`false`: this resource accepts no writes' : 'the write DTO '.class_basename($this->inputClass),
            'readOnly' => $readOnly ? 'true' : 'false',
            'publicRead' => $this->option('public') ? $readClass.'::class' : 'null',
            'sectionLine' => is_string($section = $this->option('section')) && $section !== ''
                ? sprintf("    section: '%s',\n", $section)
                : '',
        ];
    }

    /**
     * The filter vocabulary of the model's table, or null after reporting why it cannot be read.
     *
     * @return array{table: string, properties: string, operators: list<string>, sortable: bool}|null
     */
    protected function filterVocabulary(): ?array
    {
        if (! class_exists($this->resolvedModel) || ! is_subclass_of($this->resolvedModel, Model::class)) {
            $this->components->error(sprintf('--filters reads the model\'s table, and [%s] is not an Eloquent model.', $this->resolvedModel));

            return null;
        }

        /** @var Model $model */
        $model = new ($this->resolvedModel);
        $table = $model->getTable();

        try {
            $schema = Schema::connection($model->getConnectionName());
            $columns = $schema->getColumns($table);
            $indexes = $schema->getIndexes($table);
        } catch (Throwable $e) {
            $columns = [];
            $indexes = [];
        }

        if ($columns === []) {
            $this->components->error(sprintf(
                '--filters reads the columns of [%s]\'s table `%s`, and it does not resolve on the [%s] connection. '
                    .'Migrate it first; the generator never invents columns.',
                $this->resolvedModel,
                $table,
                $model->getConnectionName() ?? config('database.default'),
            ));

            return null;
        }

        return ['table' => $table] + $this->filterProperties($columns, $indexes, $model->getKeyName());
    }

    /**
     * The column → filter vocabulary mapping. Each rule is the estate's measured majority (make-surface 06 PROOF,
     * the 21 existing `*FilterData` classes): dates sort and never filter; names search and sort; titles match
     * partially; a unique key filters exactly and sorts; a nullable plain column is `NullableExact` (so the empty
     * value is askable); counts sort; everything else filters exactly. The key, long text, json and binary columns
     * are not facets.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $indexes
     * @return array{properties: string, operators: list<string>, sortable: bool}
     */
    protected function filterProperties(array $columns, array $indexes, string $keyName): array
    {
        $unique = [];

        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) && ! ($index['primary'] ?? false) && count($index['columns'] ?? []) === 1) {
                $unique[] = $index['columns'][0];
            }
        }

        $lines = [];
        $operators = [];
        $sortable = false;

        foreach ($columns as $column) {
            $name = (string) $column['name'];
            $kind = $this->columnKind($column);

            if ($name === $keyName || $kind === 'skip') {
                continue;
            }

            $property = Str::camel($name);
            $sort = $property === $name ? '#[Sortable]' : sprintf("#[Sortable(column: '%s')]", $name);
            $type = 'string|Optional|null';
            $attributes = [];

            if ($kind === 'date') {
                $attributes = [$sort];
            } elseif ($kind === 'number' && ! str_ends_with($name, '_id')) {
                $attributes = [$sort];
                $type = 'string|Optional|int|null';
            } elseif ($kind === 'string' && in_array($name, ['name', 'label', 'display_name'], true)) {
                $attributes = ["#[Filterable(IlikeMatch::class, mode: 'contains')]", $sort];
                $operators[] = 'IlikeMatch';
            } elseif ($kind === 'string' && $name === 'title') {
                $attributes = ['#[Filterable(Partial::class)]', $sort];
                $operators[] = 'Partial';
            } elseif ($kind === 'string' && in_array($name, $unique, true)) {
                $attributes = ['#[Filterable(Exact::class)]', $sort];
                $operators[] = 'Exact';
            } elseif ($kind === 'string' && ($column['nullable'] ?? false) && ! str_ends_with($name, '_id')) {
                $attributes = ['#[Filterable(NullableExact::class)]'];
                $operators[] = 'NullableExact';
            } else {
                $attributes = ['#[Filterable(Exact::class)]'];
                $operators[] = 'Exact';
            }

            $sortable = $sortable || in_array($sort, $attributes, true);
            $lines[] = '        '.implode("\n        ", $attributes)."\n        public {$type} \${$property} = null,";
        }

        return ['properties' => implode("\n\n", $lines), 'operators' => array_values(array_unique($operators)), 'sortable' => $sortable];
    }

    /**
     * date | number | boolean | string | skip, from the schema's type name — engine-neutral on purpose (sqlite
     * reports a json column as text and a boolean as `tinyint(1)`; postgres reports `int4`, `timestamp(0)`).
     *
     * @param  array<string, mixed>  $column
     */
    protected function columnKind(array $column): string
    {
        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        $type = strtolower((string) ($column['type'] ?? $typeName));

        return match (true) {
            in_array($typeName, ['bool', 'boolean'], true) || str_starts_with($type, 'tinyint(1)') => 'boolean',
            preg_match('/text|json|blob|binary|bytea|geometry|point|xml/', $typeName) === 1 => 'skip',
            preg_match('/date|time|year/', $typeName) === 1 => 'date',
            preg_match('/int|decimal|numeric|float|double|real|money|serial/', $typeName) === 1 => 'number',
            default => 'string',
        };
    }

    /** @param array{operators: list<string>, sortable: bool} $vocabulary */
    protected function filterImports(array $vocabulary): string
    {
        $uses = [
            'Rushing\\DataFilters\\Attributes\\ResourceFilter',
            'Spatie\\LaravelData\\Data',
            'Spatie\\LaravelData\\Optional',
            'Splicewire\\Beam\\Filters\\DeclaredResourceQuery',
        ];

        if ($vocabulary['operators'] !== []) {
            $uses[] = 'Rushing\\DataFilters\\Attributes\\Filterable';
        }

        if ($vocabulary['sortable']) {
            $uses[] = 'Rushing\\DataFilters\\Attributes\\Sortable';
        }

        foreach ($vocabulary['operators'] as $operator) {
            $uses[] = 'Rushing\\DataFilters\\Operators\\'.$operator;
        }

        sort($uses);

        return implode("\n", array_map(fn (string $use) => "use {$use};", $uses));
    }

    /** The one host step a generated filter class needs: discovery has to know the class. Printed, never edited. */
    protected function printFilterDiscovery(): void
    {
        $this->newLine();
        $this->components->info('List the filter class for discovery — in config/data-filters.php (or register it from a provider):');
        $this->line(sprintf("    'discover' => ['classes' => [\\%s::class]],", $this->filterClass));
    }

    /**
     * `#[UseCascadePolicy]` belongs on the MODEL, which this command never emits — so it is printed, not written.
     * The attribute also never fires on its own; the registrar call is printed with it.
     */
    protected function printCascadePolicy(): void
    {
        $model = class_basename($this->resolvedModel);

        $this->newLine();
        $this->components->info(sprintf('Declare the cascade policy — add to %s (an authored model; the generator never edits it):', $this->resolvedModel));
        $this->line('    use Rushing\\PermissionCascade\\Attributes\\UseCascadePolicy;');
        $this->line(sprintf('    #[UseCascadePolicy]  // on class %s', $model));
        $this->components->info('The attribute is read, not self-registering — bind it from a service provider\'s boot():');
        $this->line(sprintf('    \\Rushing\\PermissionCascade\\Support\\CascadePolicyRegistrar::register(\\%s::class);', $this->resolvedModel));
    }

    /**
     * Warns when no `NavSection` seats `$section` here, reading both seat forms through the same audit the doctor
     * runs. Three answers: a seat positively found is silence; absence is warned only when every navigation was
     * read; a navigation that could not be read (its factory threw) makes the answer "unknown", said so, never
     * claimed unseated.
     */
    protected function warnWhenUnseated(string $section): void
    {
        try {
            $reading = $this->laravel->make(UnseatedNavSectionAudit::class)->seatReading();
        } catch (Throwable) {
            $this->components->warn(sprintf('Could not read this host\'s navigation seats, so whether `%s` is seated is unknown.', $section));

            return;
        }

        if (in_array($section, $reading['keys'], true)) {
            return;
        }

        if ($reading['unread'] !== []) {
            $this->newLine();
            $this->components->warn(sprintf(
                'No declared NavSection seats `%s`, but this host\'s hand-authored navigation for %s could not be read '
                    .'here (its factory threw, wanting context this console run has not got), so whether `%s` is seated '
                    .'is unknown.',
                $section,
                implode(', ', array_map(fn (string $realm): string => "`{$realm}`", $reading['unread'])),
                $section,
            ));

            return;
        }

        $this->newLine();
        $this->components->warn(sprintf(
            'No NavSection seats `%s` here, so this resource attaches to nothing in a projected navigation. '
                .'A `section:` says what a resource sits UNDER; it cannot create the section. If this host should '
                .'show it, seat it from a service provider\'s boot() (realm, label, icon, href and order are yours to choose):',
            $section,
        ));
        $this->line(sprintf(
            "    \$this->app->make(\\Splicewire\\Beam\\Nav\\NavSectionRegistry::class)->register(new \\Splicewire\\Beam\\Nav\\NavSection(key: '%s', realm: 'tenant', label: '%s', icon: 'Folder', href: '/%s', order: 100, entitlement: null, permission: null, audience: \\Splicewire\\Beam\\Nav\\NavAudience::Product), by: __CLASS__);",
            $section,
            Str::headline($section),
            $section,
        ));
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: int, 3: string}>
     */
    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The Eloquent model the resource resolves — a bare name resolves under App\Models\, a namespaced one is used as given (default: App\Models\<Name>)'],
            ['key', 'k', InputOption::VALUE_REQUIRED, 'The registry key and data-filters resource key (default: the kebab-cased plural of <Name>)'],
            ['label', null, InputOption::VALUE_REQUIRED, 'Display label derived from the key by default; --label= keeps the resource addressable and not presented'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the read Data class if it already exists'],
            ['summary', 's', InputOption::VALUE_NONE, 'Also emit a summary provider (App\\Particle\\Summaries\\<Name>SummaryProvider) implementing ResourceSummaryProvider, and print the summaryProvider: line to add to the attribute'],
            ['filters', null, InputOption::VALUE_NONE, 'Also emit App\\Data\\Filters\\<Name>FilterData from the model\'s actual table columns (#[Filterable]/#[Sortable]), naming the resource by its key'],
            ['cascade-policy', null, InputOption::VALUE_NONE, 'Print the #[UseCascadePolicy] attribute and its registrar call for the model (the generator never edits an authored model)'],
            ['section', null, InputOption::VALUE_REQUIRED, 'Declare the resource\'s section:, and warn (with a registration line) when no NavSection seats it here'],
            ['read-only', null, InputOption::VALUE_NONE, 'Declare a read-only resource (readOnly: true, input: false) and emit no write DTO; a bare mount then mounts index and show'],
            ['public', null, InputOption::VALUE_NONE, 'Declare the read class as the Public Read (publicRead: <Name>Data::class): a guest may read every field it projects'],
        ];
    }
}
