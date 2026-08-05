<?php

namespace Splicewire\Beam\Codegen;

use Illuminate\Support\Str;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Rushing\Codegen\Contracts\Generator;
use Rushing\Popcorn\Binding;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Generates a PHP Saloon connector + one request class per operation from the SAME canonical model the
 * TS client consumes (codegen-substrate #06) — the "second, genuinely different driver" that makes the
 * seam honest (grill Q1): server-side PHP emission vs. the client-side TS one, off one shared model.
 *
 * The SYNTACTIC code builder (nette/php-generator) lives HERE, inside the driver — never in the shared
 * semantic model (grill Q3). The model declares intent (operations, methods, paths, return refs); this
 * driver realizes it as idiomatic Saloon PHP. A Rust or TS driver would use its own emission tool
 * against the same model.
 *
 * Emits toward the `rushing/laravel-saloon-kernel` / saloonphp v3 shape: a {@see Connector} with a
 * `resolveBaseUrl()` and a typed method per operation, and per-operation {@see Request} classes with
 * the verb, endpoint (params interpolated), and a JSON body for writes.
 */
class SaloonConnectorGenerator implements Generator
{
    /** @var array<string, mixed> */
    private array $options = [];

    public function name(): string
    {
        return 'php-saloon';
    }

    public function stack(): string
    {
        return 'php-saloon';
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
        $operations = $input['model']['operations'] ?? [];
        $namespace = (string) ($this->options['namespace'] ?? 'App\\Generated\\Saloon');
        $baseUrl = (string) ($this->options['base_url'] ?? '/');

        $files = [];
        $printer = new PsrPrinter;

        $requestClasses = [];
        foreach ($operations as $op) {
            $class = $this->requestClassName($op['name']);
            $requestClasses[$op['name']] = $class;
            $files["Requests/{$class}.php"] = $printer->printFile($this->buildRequest($namespace, $class, $op));
        }

        $files['ApiConnector.php'] = $printer->printFile(
            $this->buildConnector($namespace, $baseUrl, $operations, $requestClasses),
        );

        return ['files' => $files];
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function buildRequest(string $namespace, string $class, array $op): PhpFile
    {
        $methods = $op['methods'] ?? [$op['method'] ?? 'GET'];
        $isWrite = (bool) array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
        $verb = strtoupper((string) ($op['method'] ?? $methods[0] ?? 'GET'));

        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($namespace.'\\Requests');
        $ns->addUse(Method::class);
        $ns->addUse(Request::class);

        $classType = $ns->addClass($class)->setExtends(Request::class);

        if ($op['doc'] ?? null) {
            $classType->addComment((string) $op['doc']);
        }
        $classType->addComment("Route: {$op['name']}");

        if ($isWrite) {
            $ns->addUse(HasBody::class);
            $ns->addUse(HasJsonBody::class);
            $classType->addImplement(HasBody::class);
            $classType->addTrait(HasJsonBody::class);
        }

        $classType->addProperty('method')
            ->setProtected()
            ->setType(Method::class)
            ->setValue(new Literal("Method::{$verb}"));

        // Constructor: params for URL interpolation, and a body for writes.
        $ctor = $classType->addMethod('__construct');
        $ctor->addPromotedParameter('params')->setType('array')->setDefaultValue([])->setPublic();
        if ($isWrite) {
            // NOT `body`: Saloon's HasJsonBody trait already declares a `$body` property (a body
            // repository), so a promoted `public array $body` collides ("same property defined
            // incompatibly" — a class-load fatal). Name it `payload` and feed it through defaultBody().
            $ctor->addPromotedParameter('payload')->setType('array')->setDefaultValue([])->setPublic();
        }

        $endpoint = $this->endpointExpression($op['path']);
        $classType->addMethod('resolveEndpoint')
            ->setReturnType('string')
            ->setBody("return {$endpoint};");

        if ($isWrite) {
            $classType->addMethod('defaultBody')
                ->setReturnType('array')
                ->setBody('return $this->payload;');
        }

        return $file;
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @param  array<string, string>  $requestClasses
     */
    private function buildConnector(string $namespace, string $baseUrl, array $operations, array $requestClasses): PhpFile
    {
        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($namespace);
        $ns->addUse(Connector::class);
        $ns->addUse(Response::class);

        $class = $ns->addClass('ApiConnector')->setExtends(Connector::class);
        $class->addComment('AUTO-GENERATED Saloon connector (codegen-substrate #06). DO NOT EDIT BY HAND.');

        $class->addMethod('resolveBaseUrl')
            ->setReturnType('string')
            ->setBody('return '.var_export($baseUrl, true).';');

        foreach ($operations as $op) {
            $requestFqn = $namespace.'\\Requests\\'.$requestClasses[$op['name']];
            $ns->addUse($requestFqn);
            $methods = $op['methods'] ?? [$op['method'] ?? 'GET'];
            $isWrite = (bool) array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);

            $method = $class->addMethod($this->connectorMethodName($op['name']))
                ->setReturnType(Response::class);
            $method->addParameter('params')->setType('array')->setDefaultValue([]);

            if ($isWrite) {
                $method->addParameter('body')->setType('array')->setDefaultValue([]);
                $method->setBody("return \$this->send(new {$requestClasses[$op['name']]}(\$params, \$body));");
            } else {
                $method->setBody("return \$this->send(new {$requestClasses[$op['name']]}(\$params));");
            }
        }

        return $file;
    }

    /** `resources/library-lyrics/{id}` → `'resources/library-lyrics/'.$this->params['id']`. */
    private function endpointExpression(string $path): string
    {
        $expr = preg_replace_callback(
            '/\{(\w+)\}/',
            fn ($m) => "'.\$this->params['{$m[1]}'].'",
            $path,
        );

        return "'".ltrim($expr, '/')."'";
    }

    private function requestClassName(string $routeName): string
    {
        return Str::studly(str_replace(['.', '-'], ' ', $routeName)).'Request';
    }

    private function connectorMethodName(string $routeName): string
    {
        return Str::camel(str_replace(['.', '-'], ' ', $routeName));
    }
}
