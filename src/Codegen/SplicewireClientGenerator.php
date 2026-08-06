<?php

namespace Splicewire\Beam\Codegen;

use Illuminate\Support\Str;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Rushing\Codegen\Contracts\Generator;
use Rushing\Codegen\Model\Type;
use Rushing\Popcorn\Binding;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Emit the domain-grouped `Splicewire\Client\*` Saloon SDK from the OpenAPI-fed CodegenModel
 * (client-sdk-regen #05). A SEPARATE driver from {@see SaloonConnectorGenerator} — that one emits a flat,
 * untyped self-client (`App\Generated\Saloon`); this one reproduces the hand-curated, domain-namespaced,
 * per-field-typed, prose-documented published package.
 *
 * Naming is CONVENTION-FIRST with a per-route hint escape hatch (the locked decision):
 *  - domain = the `@group` tag (`meta['tags'][0]`) → `Requests/<Domain>/…`, class `Resource\<Domain>`;
 *  - request class = derived from path+verb (`POST …/ideas` → `CreateIdea`, a trailing action segment
 *    like `generate-composition` → `GenerateComposition`);
 *  - constructor = path params then body fields as typed per-field `protected` params (`idea_id` → `$ideaId`),
 *    with `defaultBody()` mapping each back to its wire key; `@param` prose comes from the spec (the #03
 *    `@bodyParam` migration), so the doc rides for free.
 * A per-route hint (`options['requests']["{VERB} {path}"]`) overrides the miss cases — a bespoke class name,
 * a body collapsed into one opaque array param, or a renamed path param — WITHOUT re-encoding the whole SDK.
 *
 * The SYNTACTIC builder (nette/php-generator) lives HERE in the driver, never in the shared codegen core.
 */
class SplicewireClientGenerator implements Generator
{
    /** @var array<string, mixed> */
    private array $options = [];

    public function name(): string
    {
        return 'splicewire-client';
    }

    public function stack(): string
    {
        return 'splicewire-client';
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(array $input): array
    {
        $this->options = $input['options'] ?? [];
        $namespace = (string) ($this->options['namespace'] ?? 'Splicewire\\Client');
        $hints = (array) ($this->options['requests'] ?? []);

        $only = (array) ($this->options['domains'] ?? []);

        // #06 spine-DTO mapping — OpenAPI response component (short-name) → the client DTO's spine-wire
        // base FQN. A component present here gets a generated `Data/<X>.php` thin adapter, and a resource
        // method whose op returns it gets a typed dual method alongside the raw one.
        $dataMap = (array) ($this->options['data'] ?? []);

        // Group the client-relevant operations by domain (skip the untagged / out-of-scope).
        $byDomain = [];
        foreach ($input['model']['operations'] ?? [] as $op) {
            $domain = $this->domainOf($op);
            if ($domain === null || ($only !== [] && ! in_array($domain, $only, true))) {
                continue;
            }
            $hint = $hints["{$op['method']} {$op['path']}"] ?? [];
            $hinted = isset($hint['class']);
            $class = (string) ($hint['class'] ?? $this->classNameFor($op));

            // A convention-named op must never clobber a class an explicit hint already claimed — two ops
            // can convention-collide on one name within a domain (the #05 "explained superset"), so an
            // un-hinted late arrival would otherwise overwrite a hinted golden request. Hinted wins.
            if (isset($byDomain[$domain][$class]) && ! $hinted && ($byDomain[$domain][$class]['hinted'] ?? false)) {
                continue;
            }
            $byDomain[$domain][$class] = ['op' => $op, 'hint' => $hint, 'hinted' => $hinted];
        }

        $printer = new PsrPrinter;
        $files = [];

        // The mapped components an in-scope typed op actually returns — only these get a `Data/*` adapter.
        $referencedComponents = [];

        foreach ($byDomain as $domain => $requests) {
            foreach ($requests as $class => $spec) {
                $files["Requests/{$domain}/{$class}.php"] =
                    $printer->printFile($this->buildRequest($namespace, $domain, $class, $spec['op'], $spec['hint']));

                $component = $this->returnComponent($spec['op']);
                if ($component !== null && isset($dataMap[$component])) {
                    $referencedComponents[$component] = $dataMap[$component];
                }
            }
            $files["Resource/{$domain}.php"] =
                $printer->printFile($this->buildResource($namespace, $domain, $requests, $dataMap));
        }

        foreach ($referencedComponents as $component => $spineFqn) {
            $dtoName = $this->dtoShortName($component, (string) $spineFqn);
            $files["Data/{$dtoName}.php"] =
                $printer->printFile($this->buildDataAdapter($namespace, $dtoName, (string) $spineFqn));
        }

        $files['GeneratedConnector.php'] = $printer->printFile($this->buildConnector($namespace, $byDomain));

        return ['files' => $this->applyDenyList($files)];
    }

    /**
     * Drop any emitted path matching a `options['deny']` glob — the #09 residue seam. Regeneration must
     * NEVER stomp a hand-written file with no spec home (the hand connector subclass, the webhook verifier,
     * the non-trivial DTO adapters). The generated base (`GeneratedConnector`) is emitted; its hand subclass
     * (`SplicewireConnector`) is denied. This runs in the GENERATOR (not the writer) so a denied path never
     * even reaches disk, and the FileWriter stays SDK-agnostic.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function applyDenyList(array $files): array
    {
        $deny = (array) ($this->options['deny'] ?? []);
        if ($deny === []) {
            return $files;
        }

        return array_filter(
            $files,
            fn (string $path) => ! $this->isDenied($path, $deny),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<int, string>  $deny
     */
    private function isDenied(string $path, array $deny): bool
    {
        foreach ($deny as $glob) {
            if (Str::is($glob, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $hint
     */
    private function buildRequest(string $namespace, string $domain, string $class, array $op, array $hint): PhpFile
    {
        $isWrite = ($op['body'] ?? null) !== null;

        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($namespace.'\\Requests\\'.$domain);
        $ns->addUse(Method::class);
        $ns->addUse(Request::class);

        $classType = $ns->addClass($class)->setExtends(Request::class);
        if ($op['doc'] ?? null) {
            $classType->addComment((string) $op['doc']);
        }

        if ($isWrite) {
            $ns->addUse(HasBody::class);
            $ns->addUse(HasJsonBody::class);
            $classType->addImplement(HasBody::class);
            $classType->addTrait(HasJsonBody::class);
        }

        $verb = strtoupper((string) $op['method']);
        $classType->addProperty('method')
            ->setProtected()
            ->setType(Method::class)
            ->setValue(new Literal("Method::{$verb}"));

        // Resolve the constructor parameters: path params (possibly renamed) then the body.
        [$ctorParams, $bodyMap] = $this->constructorPlan($op, $hint);

        $ctor = $classType->addMethod('__construct');
        foreach ($ctorParams as $p) {
            $param = $ctor->addPromotedParameter($p['name'])->setType($p['php'])->setProtected();
            if (array_key_exists('default', $p)) {
                $param->setDefaultValue($p['default']);
            }
            $ctor->addComment(trim("@param  {$p['docType']}  \${$p['name']}  ".($p['doc'] ?? '')));
        }

        $classType->addMethod('resolveEndpoint')
            ->setReturnType('string')
            ->setBody('return '.$this->endpointExpression($op['path'], $ctorParams).';');

        if ($isWrite) {
            $classType->addMethod('defaultBody')
                ->setProtected()
                ->setReturnType('array')
                ->setBody($this->defaultBodyExpression($bodyMap));
        }

        return $file;
    }

    /**
     * The ordered constructor params + the body-key → accessor map that `defaultBody()` emits.
     *
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $hint
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>|string|null}
     */
    private function constructorPlan(array $op, array $hint): array
    {
        $params = [];
        $renames = (array) ($hint['pathParams'] ?? []);

        foreach ($op['params'] ?? [] as $param) {
            $name = $renames[$param['name']] ?? Str::camel($param['name']);
            $params[] = [
                'name' => $name,
                'wire' => $param['name'],
                'php' => $this->phpType($param['type']),
                'docType' => $this->docType($param['type']),
                'doc' => $param['doc'] ?? null,
            ];
        }

        // A hint may collapse the whole body into one opaque array param (e.g. `$options`).
        if (isset($hint['collapseBody']) && ($op['body'] ?? null) !== null) {
            $name = (string) $hint['collapseBody'];
            $params[] = ['name' => $name, 'php' => 'array', 'docType' => 'array<string, mixed>', 'default' => [], 'doc' => null];

            return [$params, $name];
        }

        $bodyMap = [];
        foreach ($op['body']['fields'] ?? [] as $field) {
            $name = Str::camel($field['name']);
            $param = [
                'name' => $name,
                'wire' => $field['name'],
                'php' => $this->phpType($field['type']),
                'docType' => $this->docType($field['type']),
                'doc' => $field['doc'] ?? null,
            ];
            if ($this->isOptional($field['type'])) {
                $param['default'] = $this->phpType($field['type']) === 'array' ? [] : null;
            }
            $params[] = $param;
            $bodyMap[$field['name']] = $name;
        }

        return [$params, $bodyMap === [] ? null : $bodyMap];
    }

    /**
     * @param  array<string, string>|string|null  $bodyMap
     */
    private function defaultBodyExpression(array|string|null $bodyMap): string
    {
        if (is_string($bodyMap)) {
            return "return \$this->{$bodyMap};";
        }
        if ($bodyMap === null) {
            return 'return [];';
        }

        $lines = [];
        foreach ($bodyMap as $wire => $accessor) {
            $lines[] = "    '{$wire}' => \$this->{$accessor},";
        }

        return "return [\n".implode("\n", $lines)."\n];";
    }

    /**
     * @param  array<int, array<string, mixed>>  $ctorParams
     */
    private function endpointExpression(string $path, array $ctorParams): string
    {
        $accessorFor = [];
        foreach ($ctorParams as $p) {
            if (isset($p['wire'])) {
                $accessorFor[$p['wire']] = $p['name'];
            }
        }

        if (! str_contains($path, '{')) {
            return "'{$path}'";
        }

        $interpolated = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($accessorFor) {
            $accessor = $accessorFor[$m[1]] ?? Str::camel($m[1]);

            return '{$this->'.$accessor.'}';
        }, $path);

        return '"'.$interpolated.'"';
    }

    /**
     * @param  array<string, array<string, mixed>>  $requests  class => ['op'=>…, 'hint'=>…]
     * @param  array<string, string>  $dataMap  component short-name → spine-wire FQN (#06)
     */
    private function buildResource(string $namespace, string $domain, array $requests, array $dataMap): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($namespace.'\\Resource');
        $ns->addUse('Saloon\\Http\\Response');
        $ns->addUse($namespace.'\\Resource');

        $class = $ns->addClass($domain)->setExtends($namespace.'\\Resource');

        foreach ($requests as $className => $spec) {
            $ns->addUse($namespace.'\\Requests\\'.$domain.'\\'.$className);
            [$ctorParams] = $this->constructorPlan($spec['op'], $spec['hint']);

            // The RAW method — the untyped `Response` send. Its name is the request class camelCased.
            $rawName = Str::camel($className);
            $rawMethod = $class->addMethod($rawName);
            $args = [];
            foreach ($ctorParams as $p) {
                $mp = $rawMethod->addParameter($p['name'])->setType($p['php']);
                if (array_key_exists('default', $p)) {
                    $mp->setDefaultValue($p['default']);
                }
                $args[] = '$'.$p['name'];
            }
            $rawMethod->setReturnType('Saloon\\Http\\Response')
                ->setBody("return \$this->connector->send(new {$className}(".implode(', ', $args).'));');

            // The TYPED dual method (#06): present only when the op returns a MAPPED component. It unwraps
            // the raw `Response` through the spine-DTO adapter's `fromResponse`.
            $component = $this->returnComponent($spec['op']);
            if ($component === null || ! isset($dataMap[$component])) {
                continue;
            }

            $dtoName = $this->dtoShortName($component, (string) $dataMap[$component]);
            $ns->addUse($namespace.'\\Data\\'.$dtoName);

            $typedName = $this->typedMethodName($rawName, $domain);
            $typedMethod = $class->addMethod($typedName)->addComment('Typed view over the `{data: {...}}` envelope.');
            $callArgs = [];
            foreach ($ctorParams as $p) {
                $mp = $typedMethod->addParameter($p['name'])->setType($p['php']);
                if (array_key_exists('default', $p)) {
                    $mp->setDefaultValue($p['default']);
                }
                $callArgs[] = '$'.$p['name'];
            }
            $typedMethod->setReturnType($namespace.'\\Data\\'.$dtoName)
                ->setBody("return {$dtoName}::fromResponse(\$this->{$rawName}(".implode(', ', $callArgs).'));');
        }

        return $file;
    }

    /**
     * The typed dual-method name: the raw name with a trailing domain-singular suffix stripped
     * (`getComposition` → `get` in the `Compositions` domain), else the raw name with a `Typed` suffix to
     * avoid colliding with the raw method.
     */
    private function typedMethodName(string $rawName, string $domain): string
    {
        $suffix = Str::studly(Str::singular($domain));
        if ($suffix !== '' && str_ends_with($rawName, $suffix) && $rawName !== Str::camel($suffix)) {
            $trimmed = Str::camel(substr($rawName, 0, -strlen($suffix)));
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $rawName.'Typed';
    }

    /**
     * The client `Data\*` adapter (#06): a THIN subclass of the spine-wire DTO adding only the Saloon
     * `Response` → DTO unwrap (`static::fromArray($response->json('data') ?? [])`). No second field copy —
     * the wire vocabulary + `fromArray` live in the licensed spine tier (ADR-0093).
     */
    private function buildDataAdapter(string $namespace, string $dtoName, string $spineFqn): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($namespace.'\\Data');
        $ns->addUse('Saloon\\Http\\Response');

        // Alias the spine base to `Spine<Name>` (the golden import convention) when the short-names collide.
        $spineShort = $this->shortName($spineFqn);
        $alias = $spineShort === $dtoName ? 'Spine'.$dtoName : null;
        $ns->addUse($spineFqn, $alias);
        $baseRef = $alias ?? $spineShort;

        $class = $ns->addClass($dtoName)->setExtends($spineFqn);
        $class->addComment(
            "Connector-side Saloon adapter over the shared spine {@see {$baseRef}} (ADR-0093): the wire\n".
            "vocabulary + its `fromArray` live in the licensed spine tier; this thin subclass adds ONLY the\n".
            'Saloon Response->DTO unwrap, so there is no drift-prone second field copy here.'
        );

        $method = $class->addMethod('fromResponse')
            ->setStatic()
            ->setReturnType('self')
            ->setBody("return static::fromArray(\$response->json('data') ?? []);");
        $method->addParameter('response')->setType('Saloon\\Http\\Response');

        return $file;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byDomain
     */
    private function buildConnector(string $namespace, array $byDomain): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($namespace);
        $ns->addUse('Saloon\\Http\\Connector');

        $connector = $ns->addClass('GeneratedConnector')->setExtends('Saloon\\Http\\Connector');
        $connector->addMethod('resolveBaseUrl')
            ->setReturnType('string')
            ->setBody("return '".(string) ($this->options['base_url'] ?? '/')."';");

        foreach (array_keys($byDomain) as $domain) {
            $ns->addUse($namespace.'\\Resource\\'.$domain);
            $connector->addMethod(Str::camel($domain))
                ->setReturnType($namespace.'\\Resource\\'.$domain)
                ->setBody("return new {$domain}(\$this);");
        }

        return $file;
    }

    // ── naming + type helpers ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $op
     */
    private function domainOf(array $op): ?string
    {
        $tag = $op['meta']['tags'][0] ?? null;

        return is_string($tag) && $tag !== '' ? Str::studly($tag) : null;
    }

    /**
     * The response component short-name an op returns (a `ref` Type), or null when the op is untyped.
     *
     * @param  array<string, mixed>  $op
     */
    private function returnComponent(array $op): ?string
    {
        $returns = $op['returns'] ?? null;

        return is_array($returns) && ($returns['kind'] ?? null) === 'ref'
            ? (string) $returns['ref']
            : null;
    }

    /**
     * The client DTO short-name for a mapped component — the spine base's short-name (`CompositionData` →
     * `Composition`, matching the hand package), so `Data\Composition extends …\Wire\Composition`.
     */
    private function dtoShortName(string $component, string $spineFqn): string
    {
        return $this->shortName($spineFqn);
    }

    private function shortName(string $fqn): string
    {
        return (string) Str::afterLast($fqn, '\\');
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function classNameFor(array $op): string
    {
        $segments = array_values(array_filter(
            explode('/', trim((string) $op['path'], '/')),
            fn ($s) => $s !== '' && $s !== 'api' && ! preg_match('/^v\d+$/', $s),
        ));
        $last = end($segments) ?: 'resource';

        // A trailing action segment (a hyphenated verb like `generate-composition`) names itself.
        if (str_contains($last, '-')) {
            return Str::studly($last);
        }

        $verb = strtoupper((string) $op['method']);
        $isItem = str_contains((string) $op['path'], '{');
        $resource = $isItem
            ? ($segments[count($segments) - 2] ?? $last)
            : $last;
        $noun = Str::studly(Str::singular(str_replace(['{', '}'], '', $resource)));

        $prefix = match (true) {
            $verb === 'POST' => 'Create',
            $verb === 'GET' && $isItem => 'Get',
            $verb === 'GET' => 'List',
            $verb === 'PUT', $verb === 'PATCH' => 'Update',
            $verb === 'DELETE' => 'Delete',
            default => Str::studly(strtolower($verb)),
        };

        return $prefix.$noun;
    }

    /**
     * @param  array<string, mixed>  $type  a serialized {@see Type}
     */
    private function phpType(array $type): string
    {
        $inner = $type['kind'] === 'optional' ? $type['inner'] : $type;

        return match ($inner['kind']) {
            'primitive' => match ($inner['primitive']) {
                'string', 'uuid' => 'string',
                'int' => 'int',
                'float' => 'float',
                'bool' => 'bool',
                default => 'array',
            },
            'list', 'map', 'ref', 'record' => 'array',
            default => 'array',
        };
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function docType(array $type): string
    {
        $php = $this->phpType($type);

        return $php === 'array' ? 'array<string, mixed>' : $php;
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function isOptional(array $type): bool
    {
        return ($type['kind'] ?? null) === 'optional';
    }
}
