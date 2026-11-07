<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Splicewire\Beam\Surgeon\InertiaPropShapeAudit;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `splicewire:beam:make:page {realm}/{name}` — mints an Inertia page that starts DECLARED: the page's props Data class
 * (`#[TypeScript]`, the PRIMARY file), the invokable action that renders it with `#[ResponseFromData]` on the method,
 * the `.tsx` page typed against the generated props, and the route line the host pastes.
 *
 * ## Why this generator exists (make-surface ticket 03)
 * The particle doctrine names three legal declaration sites. `#[ParticleResource]` and `#[ParticleOp]` have
 * generators; the third — the `#[ResponseFromData]` / `#[RequestFromData]` / `#[StreamsFromData]` trio on a
 * controller method — had none, and an Inertia page is its commonest use. A hand-written page is three files and a
 * route, and the sampled example decides whether the next one declares anything.
 *
 * ## The render passes a Data OBJECT, never an array
 * {@see InertiaPropShapeAudit} counts a render whose props cross as an array (even an array of Data) as undeclared.
 * The emitted action passes `new <Name>PageData(...)`, and the declaration check below runs that audit over the
 * emitted action's own bytes, so a host-overridden stub that flattens the props fails the run.
 *
 * ## Names
 * The Inertia component is exactly `<realm>/<name>`: beam-inertia picks the layout from that prefix (`site/`,
 * `account/`, `operator/`, `auth/`, `settings/`; anything else gets the default app layout, which is reported, not
 * refused). The class base is the studly name after the realm, so `account/billing-summary` emits
 * `BillingSummaryPageData` and `Pages\BillingSummaryPageController`. A second realm reusing a name hits the primary's
 * exists-refusal rather than silently sharing another page's Data.
 *
 * ## Companions
 * The action and an optional `--request` input Data are PHP companions ({@see writeCompanion()}): kept when they
 * exist, and verified with the primary. The `.tsx` page is a companion too but not PHP, so it is written by
 * {@see writePageComponent()}, reported as kept when it exists, and never linted or loaded.
 *
 * ## What it deliberately does NOT do
 * It does not edit `routes/`, and it does not add the realm's middleware: the printed line is the declaration's
 * mount, and the host's realm group decides who reaches it. Inertia is not a beam-core dependency, so a host
 * without `inertiajs/inertia-laravel` gets a warning, not a refusal.
 */
class MakePageCommand extends ParticleGeneratorCommand
{
    protected $name = 'splicewire:beam:make:page';

    protected $description = 'Scaffold a declared Inertia page: a #[TypeScript] page Data class, an action carrying #[ResponseFromData], the typed .tsx page, and the route line.';

    protected $type = 'Page data';

    /** beam-inertia's layout prefixes (`beam-inertia/src/index.tsx`, the `layout:` switch). */
    public const KNOWN_REALMS = ['site', 'account', 'operator', 'auth', 'settings'];

    protected string $realm = '';

    /** The page path after the realm, e.g. `billing-summary` or `settings/billing`. */
    protected string $page = '';

    protected string $base = '';

    protected string $controllerClass = '';

    protected string $requestClass = '';

    protected string $uri = '';

    protected function getStub(): string
    {
        return $this->resolveStubPath('stubs/page-data.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Data\Pages';
    }

    /** The primary class name; the `{realm}/{name}` argument is parsed in {@see handle()} before this is read. */
    protected function getNameInput(): string
    {
        return $this->base.'PageData';
    }

    /** This run's exit code, set by {@see generate()} and returned by {@see execute()}. */
    private int $exitCode = self::SUCCESS;

    /**
     * The exit code is carried here rather than by an `int` {@see handle()}: `Command::execute()` casts `handle()`'s
     * return, so the framework's `false` would exit 0, and an `int` return type is incompatible with
     * {@see GeneratorCommand::handle()}'s `?bool`. Keeping the framework's signature and returning the code from here
     * fails a refused or unverified run without that incompatibility.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->exitCode = self::SUCCESS;

        parent::execute($input, $output);

        return $this->exitCode;
    }

    public function handle(): ?bool
    {
        $this->exitCode = $this->generate();

        return $this->exitCode === self::SUCCESS;
    }

    protected function generate(): int
    {
        $name = $this->argument('name');
        $raw = trim(str_replace('\\', '/', is_string($name) ? $name : ''), '/');
        $segments = explode('/', $raw);

        if (count($segments) < 2 || array_filter($segments, fn (string $s) => preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $s) !== 1) !== []) {
            $this->components->error("The page must be {realm}/{name} in lowercase kebab segments (e.g. account/billing-summary); got [{$raw}].");

            return self::FAILURE;
        }

        $this->realm = array_shift($segments);
        $this->page = implode('/', $segments);
        $this->base = Str::studly(implode('-', $segments));

        if (! in_array($this->realm, self::KNOWN_REALMS, true)) {
            $this->components->warn(sprintf(
                'Realm [%s] is not a beam-inertia layout prefix (%s); the page gets the default app layout.',
                $this->realm,
                implode(', ', self::KNOWN_REALMS),
            ));
        }

        if (! class_exists('Inertia\Inertia')) {
            $this->components->warn('inertiajs/inertia-laravel is not installed in this host; the emitted action renders through it.');
        }

        $uri = $this->option('uri');
        $this->uri = '/'.trim(is_string($uri) && $uri !== ''
            ? $uri
            : ($this->realm === 'site' ? $this->page : $this->realm.'/'.$this->page), '/');

        $this->controllerClass = $this->rootNamespace().'Http\Controllers\Pages\\'.$this->base.'PageController';

        $request = $this->option('request');
        $this->requestClass = is_string($request) && $request !== ''
            ? $this->qualifyData($request)
            : '';

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->writeCompanion('stubs/page-controller.stub', $this->controllerClass, [
            'purpose' => sprintf(
                'Renders the `%s` page. The `#[ResponseFromData]` on `__invoke()` is the declaration: it types the route '
                .'for the client generator and the undeclared-surface audit, and the render passes the Data object.',
                $this->component(),
            ),
        ] + $this->tokens());

        if ($this->requestClass !== '') {
            $this->writeCompanion('stubs/particle-data.stub', $this->requestClass, [
                'purpose' => sprintf(
                    'The declared `#[RequestFromData]` input of the `%s` page, validated by laravel-data before the action runs.',
                    $this->component(),
                ),
            ]);
        }

        $this->writePageComponent();

        $this->report(sprintf(
            "Route::get('%s', \\%s::class)->name('%s');",
            $this->uri,
            $this->controllerClass,
            str_replace('/', '.', $this->component()),
        ));
        // Measured at the laravel-beam-starter host (make-surface 03 proof): pasted after `Route::beamUxSite()` the
        // page 404s, because that macro registers a `{path}` catch-all and the first matching route wins.
        $this->line('    // Paste it ABOVE Route::beamUxSite(): that macro\'s {path} catch-all takes every route registered after it.');

        return $this->verified();
    }

    /**
     * The check the page tests assert, on THIS run's bytes: the action mounted as the printed line mounts it leaves no
     * undeclared HTTP surface, and its render site passes declared props.
     */
    protected function declarationFindings(): array
    {
        $controller = $this->loadEmitted()[$this->controllerClass];

        $findings = $this->undeclaredWhenMounted(fn () => Route::get(ltrim($this->uri, '/'), $controller));

        // The audit records only render sites that are undeclared (an array, even of Data) or propless; a render
        // that passes a Data object is skipped by design, so an empty result IS the pass. A propless render is a
        // complete contract by that audit's reading, but this generator promises declared props, so it counts here.
        $path = $this->emitted[$this->controllerClass];

        foreach ((new InertiaPropShapeAudit([]))->sitesInSource((string) $this->files->get($path), $path) as $site) {
            $findings[] = sprintf(
                '%s:%d renders %s with %s (%s)',
                $path,
                $site['line'],
                $site['page'] ?? '<dynamic>',
                $site['reason'] ?? 'no props at all',
                InertiaPropShapeAudit::CHECK,
            );
        }

        return $findings;
    }

    /** The `.tsx` companion: not PHP, so it is neither linted nor loaded, and never overwritten. */
    protected function writePageComponent(): void
    {
        $relative = 'resources/js/pages/'.$this->component().'.tsx';
        $path = $this->laravel->basePath($relative);

        if ($this->files->exists($path)) {
            $this->components->twoColumnDetail($relative, '<fg=yellow>exists, kept</>');

            return;
        }

        $pageData = $this->qualifyClass($this->getNameInput());
        $contents = (string) $this->files->get($this->resolveStubPath($this->realm === 'site' ? 'stubs/page-site.tsx.stub' : 'stubs/page.tsx.stub'));

        foreach ([
            'pageData' => class_basename($pageData),
            'namespacedPageData' => $pageData,
            'typesModule' => str_replace('\\', '/', Str::beforeLast($pageData, '\\')),
            'component' => $this->component(),
            'componentName' => $this->base.'Page',
            'realm' => $this->realm,
        ] as $token => $value) {
            $contents = str_replace('{{ '.$token.' }}', $value, $contents);
        }

        $this->makeDirectory($path);
        $this->files->put($path, $contents);

        $this->components->twoColumnDetail($relative, '<fg=green>created</>');
    }

    /** The Inertia component name, which is also the page file's path under `resources/js/pages`. */
    protected function component(): string
    {
        return $this->realm.'/'.$this->page;
    }

    /** A bare name is a Data class under the host's `Data` namespace; a namespaced one is kept as given. */
    protected function qualifyData(string $name): string
    {
        $name = str_replace('/', '\\', ltrim($name, '\\/'));

        return str_contains($name, '\\') ? $name : $this->rootNamespace().'Data\\'.$name;
    }

    /**
     * @return array<string, string>
     */
    protected function tokens(): array
    {
        $pageData = $this->qualifyClass($this->getNameInput());

        return [
            'purpose' => sprintf(
                'The props of the `%s` Inertia page, declared by `#[ResponseFromData]` on %s. `#[TypeScript]` '
                .'generates the type the page component imports.',
                $this->component(),
                class_basename($this->controllerClass).'::__invoke()',
            ),
            'component' => $this->component(),
            'title' => Str::headline(str_replace('/', ' ', $this->page)),
            'pageData' => class_basename($pageData),
            'namespacedPageData' => $pageData,
            'requestImports' => $this->requestClass === ''
                ? ''
                : "\nuse Rushing\\LaravelDataSchemasScribe\\Attributes\\RequestFromData;\nuse {$this->requestClass};",
            'requestAttribute' => $this->requestClass === ''
                ? ''
                : "\n    #[RequestFromData(".class_basename($this->requestClass).'::class)]',
            'requestParameter' => $this->requestClass === ''
                ? ''
                : class_basename($this->requestClass).' $input',
        ];
    }

    /**
     * @return list<array{0: string, 1: int, 2: string}>
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The page as {realm}/{name}, e.g. account/billing-summary (the Inertia component name)'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string|null, 2: int, 3: string, 4?: mixed}>
     */
    protected function getOptions(): array
    {
        return [
            ['request', null, InputOption::VALUE_REQUIRED, 'A Data class to declare as the action\'s #[RequestFromData] input (bare names land in App\Data; emitted when missing)'],
            ['uri', null, InputOption::VALUE_REQUIRED, 'The printed route URI (default: /{realm}/{name}, or /{name} for the site realm)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the page Data class if it already exists (companions are always kept)'],
        ];
    }
}
