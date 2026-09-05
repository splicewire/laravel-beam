<?php

namespace Splicewire\Beam\Codegen;

use InvalidArgumentException;
use Nette\PhpGenerator\PhpFile;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/** Emits transport adapters; the spine owns the fields and their domain normalization. */
class SdkDataAdapterGenerator
{
    /**
     * Namespace is the SDK root; adapters are emitted in its Data namespace.
     * Required keys are nonempty strings, checked after selecting the JSON path.
     * Constructor hydration supports builtin scalar/array/mixed parameters only; richer values
     * belong to the spine's named factory. Defaults come from the constructor, never an SDK field list.
     *
     * @param  array{mode?: 'alias'|'body'|'json', path?: string|null, factory?: string, required?: list<string>}  $options
     */
    public function generate(string $namespace, string $dtoName, string $spineFqn, array $options = []): PhpFile
    {
        $mode = $options['mode'] ?? 'json';
        if (! in_array($mode, ['alias', 'body', 'json'], true)) {
            throw new InvalidArgumentException("Unknown SDK adapter mode: {$mode}");
        }

        $file = new PhpFile;
        $file->setStrictTypes(false);
        $ns = $file->addNamespace($namespace.'\\Data');
        if ($mode !== 'alias') {
            $ns->addUse('Saloon\\Http\\Response');
        }
        $spineShort = substr(strrchr('\\'.$spineFqn, '\\'), 1);
        $alias = $spineShort === $dtoName || ($mode !== 'alias' && $spineShort === 'Response')
            ? 'Spine'.$spineShort : null;
        $ns->addUse($spineFqn, $alias);
        $class = $ns->addClass($dtoName)->setExtends($spineFqn);
        if ($mode === 'alias') {
            return $file;
        }

        $body = "\$response->throw();\n\n";
        if ($mode === 'body') {
            $body .= "return new static(\n    body: \$response->body(),\n    contentType: \$response->header('Content-Type') ?? 'text/plain',\n);";
        } else {
            $path = array_key_exists('path', $options) ? $options['path'] : 'data';
            if ($path !== null && ! is_string($path)) {
                throw new InvalidArgumentException('SDK adapter path must be a string or null (whole JSON body).');
            }
            $factory = $options['factory'] ?? 'fromArray';
            if (! is_string($factory) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $factory)) {
                throw new InvalidArgumentException('SDK adapter factory must be a PHP method name.');
            }
            $selected = '$response->json('.($path === null ? '' : var_export($path, true)).') ?? []';
            $required = $options['required'] ?? [];
            if (! is_array($required) || ! array_is_list($required)) {
                throw new InvalidArgumentException('SDK adapter required must be a list of nonempty string keys.');
            }
            if ($factory === 'constructor' || $required !== []) {
                $body .= "\$data = {$selected};\n\n";
                foreach ($required as $key) {
                    if (! is_string($key) || $key === '') {
                        throw new InvalidArgumentException('SDK adapter required must contain nonempty string keys.');
                    }
                    $access = '$data['.var_export($key, true).']';
                    $message = var_export("SDK adapter {$dtoName}: response requires a nonempty string at {$key}.", true);
                    $body .= "if (! is_string({$access} ?? null) || {$access} === '') {\n    throw new \\UnexpectedValueException({$message});\n}\n\n";
                }
                $body .= $factory === 'constructor'
                    ? $this->constructorBody($spineFqn)
                    : "return static::{$factory}(\$data);";
            } else {
                $body .= "return static::{$factory}({$selected});";
            }
        }

        $class->addMethod('fromResponse')->setStatic()->setReturnType('self')
            ->setBody($body)->addParameter('response')->setType('Saloon\\Http\\Response');

        return $file;
    }

    private function constructorBody(string $spineFqn): string
    {
        $constructor = (new ReflectionClass($spineFqn))->getConstructor();
        if ($constructor === null || ! $constructor->isPublic()) {
            throw new InvalidArgumentException("SDK constructor adapter requires a public constructor: {$spineFqn}");
        }
        $arguments = [];
        $guards = "if (! is_array(\$data)) {\n    throw new \\UnexpectedValueException('SDK constructor adapter requires a JSON object.');\n}\n\n";
        foreach ($constructor->getParameters() as $parameter) {
            if (! $parameter->isDefaultValueAvailable()) {
                $key = var_export($parameter->getName(), true);
                $message = var_export('SDK constructor adapter: missing '.$parameter->getName().'.', true);
                $guards .= "if (! array_key_exists({$key}, \$data)) {\n    throw new \\UnexpectedValueException({$message});\n}\n\n";
            }
            $arguments[] = '    '.$parameter->getName().': '.$this->argument($parameter).',';
        }

        return $guards."return new static(\n".implode("\n", $arguments)."\n);";
    }

    private function argument(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        if ($parameter->isVariadic() || $parameter->isPassedByReference()
            || ($type !== null && (! $type instanceof ReflectionNamedType || ! $type->isBuiltin()))
            || ($type !== null && ! in_array($type->getName(), ['string', 'int', 'float', 'bool', 'array', 'mixed'], true))) {
            throw new InvalidArgumentException('Unsupported SDK constructor parameter: '.$parameter->getName().'; use a spine factory.');
        }
        $access = '$data['.var_export($parameter->getName(), true).']';
        $name = $type?->getName();
        $value = in_array($name, ['string', 'int', 'float', 'bool'], true)
            ? "({$name}) {$access}" : $access;
        if ($type?->allowsNull()) {
            $value = "({$access} === null ? null : {$value})";
        }
        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();
            if (is_object($default) || is_resource($default)) {
                throw new InvalidArgumentException('Unsupported SDK constructor default: '.$parameter->getName().'; use a spine factory.');
            }
            // Array defaults also cover malformed/non-array values (the Analysis summary idiom).
            if ($name === 'array' && is_array($default)) {
                return "(is_array({$access} ?? null) ? {$access} : ".var_export($default, true).')';
            }

            return '(array_key_exists('.var_export($parameter->getName(), true).", \$data) ? {$value} : ".var_export($default, true).')';
        }

        return $value;
    }
}
