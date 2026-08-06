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

        // Group the client-relevant operations by domain (skip the untagged / out-of-scope).
        $byDomain = [];
        foreach ($input['model']['operations'] ?? [] as $op) {
            $domain = $this->domainOf($op);
            if ($domain === null || ($only !== [] && ! in_array($domain, $only, true))) {
                continue;
            }
            $hint = $hints["{$op['method']} {$op['path']}"] ?? [];
            $class = (string) ($hint['class'] ?? $this->classNameFor($op));
            $byDomain[$domain][$class] = ['op' => $op, 'hint' => $hint];
        }

        $printer = new PsrPrinter;
        $files = [];

        foreach ($byDomain as $domain => $requests) {
            foreach ($requests as $class => $spec) {
                $files["Requests/{$domain}/{$class}.php"] =
                    $printer->printFile($this->buildRequest($namespace, $domain, $class, $spec['op'], $spec['hint']));
            }
            $files["Resource/{$domain}.php"] =
                $printer->printFile($this->buildResource($namespace, $domain, $requests));
        }

        $files['GeneratedConnector.php'] = $printer->printFile($this->buildConnector($namespace, $byDomain));

        return ['files' => $files];
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
     */
    private function buildResource(string $namespace, string $domain, array $requests): PhpFile
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

            $method = $class->addMethod(Str::camel($className));
            $args = [];
            foreach ($ctorParams as $p) {
                $mp = $method->addParameter($p['name'])->setType($p['php']);
                if (array_key_exists('default', $p)) {
                    $mp->setDefaultValue($p['default']);
                }
                $args[] = '$'.$p['name'];
            }
            $method->setReturnType('Saloon\\Http\\Response')
                ->setBody("return \$this->connector->send(new {$className}(".implode(', ', $args).'));');
        }

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
