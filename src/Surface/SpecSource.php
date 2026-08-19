<?php

namespace Splicewire\Beam\Surface;

use Splicewire\Beam\Surface\Data\ResourceSeamData;
use Splicewire\Beam\Surface\Data\ResourceSeamInventoryData;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses ONE OpenAPI document into a {@see ResourceSeamInventoryData} — paths, operations, declared
 * security schemes, declared request/response shapes.
 *
 * **Deliberately vocabulary-free.** It names no Laravel class, no Splicewire concept, and no compliance
 * term, and it boots no application. That is not incidental tidiness: this is the half of the mechanism
 * that has to work against a *foreign* spec, and every host concept that leaks in here is a document we
 * can no longer read. The test suite pins this by exercising it against a fixture with no framework in
 * scope.
 *
 * The runtime half — is this operation actually authenticated? — lives in {@see RuntimeCorroborator},
 * on the other side of a seam, because it can only ever answer for an application we can boot.
 */
class SpecSource
{
    /**
     * The operation-bearing keys of an OpenAPI Path Item Object. Everything else under a path
     * (`parameters`, `servers`, `summary`, `$ref`, …) describes the path, not an operation.
     *
     * @var list<string>
     */
    public const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @param array<string, mixed> $document */
    public function __construct(private readonly array $document) {}

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        return new self($document);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new MalformedSpecException('Spec is not valid JSON: '.json_last_error_msg());
        }

        return new self($decoded);
    }

    public static function fromYaml(string $yaml): self
    {
        if (! class_exists(Yaml::class)) {
            throw new MalformedSpecException('Reading a YAML spec requires symfony/yaml.');
        }

        try {
            $decoded = Yaml::parse($yaml);
        } catch (\Throwable $e) {
            throw new MalformedSpecException('Spec is not valid YAML: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new MalformedSpecException('Spec is not valid YAML: the document is not a mapping.');
        }

        return new self($decoded);
    }

    /**
     * Reads a document whose serialization we were not told — a spec pasted into a form, or stored
     * inline on a row. JSON is a subset of YAML, so the sniff is only ever an optimization and never a
     * correctness question: a leading `{` takes the strict parser (better error messages) and
     * everything else takes the permissive one, which would have handled both.
     */
    public static function fromString(string $contents): self
    {
        return str_starts_with(ltrim($contents), '{')
            ? self::fromJson($contents)
            : self::fromYaml($contents);
    }

    /** Reads a `.json` / `.yaml` / `.yml` document off disk, dispatching on extension. */
    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new MalformedSpecException("Spec file is not readable: {$path}");
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json' => self::fromJson($contents),
            'yaml', 'yml' => self::fromYaml($contents),
            default => throw new MalformedSpecException("Unrecognized spec extension for {$path}; expected .json, .yaml or .yml."),
        };
    }

    public function inventory(): ResourceSeamInventoryData
    {
        $this->assertReadable();

        $info = $this->arrayAt(['info']);
        $defaultSecurity = $this->securityFrom($this->document);

        return new ResourceSeamInventoryData(
            title: $this->stringOrNull($info['title'] ?? null),
            version: $this->stringOrNull($info['version'] ?? null),
            securitySchemes: array_map('strval', array_keys($this->arrayAt(['components', 'securitySchemes']))),
            defaultSecurity: $defaultSecurity['schemes'],
            seams: $this->seams($defaultSecurity),
        );
    }

    /**
     * A document with no `paths` map is not an API description, and neither is one that never says which
     * OpenAPI/Swagger version it speaks. Both are rejected rather than yielding an empty inventory — see
     * {@see MalformedSpecException} for why an empty inventory is the dangerous degradation.
     */
    private function assertReadable(): void
    {
        if (! isset($this->document['openapi']) && ! isset($this->document['swagger'])) {
            throw new MalformedSpecException('Document declares neither an `openapi` nor a `swagger` version.');
        }

        if (! isset($this->document['paths']) || ! is_array($this->document['paths'])) {
            throw new MalformedSpecException('Document has no `paths` map.');
        }
    }

    /**
     * @param  array{schemes: list<string>|null, optional: bool}  $default
     * @return list<ResourceSeamData>
     */
    private function seams(array $default): array
    {
        $seams = [];

        foreach ($this->document['paths'] as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach (self::METHODS as $method) {
                $operation = $pathItem[$method] ?? null;

                if (! is_array($operation)) {
                    continue;
                }

                // An operation that declares its own `security` overrides the document default entirely
                // (OpenAPI 3.1 §4.8.2) — including `security: []`, which is how a spec says "this one is
                // public" inside an otherwise-authenticated API.
                $security = array_key_exists('security', $operation)
                    ? $this->securityFrom($operation)
                    : $default;

                $seams[] = new ResourceSeamData(
                    path: (string) $path,
                    method: strtoupper($method),
                    operationId: $this->stringOrNull($operation['operationId'] ?? null),
                    security: $security['schemes'],
                    securityOptional: $security['optional'],
                    requestShape: $this->shapeOf($operation['requestBody'] ?? null),
                    responseShapes: $this->responseShapes($operation['responses'] ?? null),
                    tags: array_values(array_map('strval', array_filter(
                        is_array($operation['tags'] ?? null) ? $operation['tags'] : [],
                        'is_scalar',
                    ))),
                );
            }
        }

        // Sorted so a re-parse of an unchanged document produces a byte-identical inventory; a spec's own
        // key order is not stable enough to diff against.
        usort($seams, fn (ResourceSeamData $a, ResourceSeamData $b) => $a->signature() <=> $b->signature());

        return $seams;
    }

    /**
     * A Security Requirement Object list is `[{schemeName: [scopes]}, …]`. An **empty object** inside it
     * is the spec's way of saying "authentication is optional here", which is materially different from
     * requiring the scheme — so it is reported alongside the names rather than folded into them.
     *
     * @param  array<string, mixed>  $node
     * @return array{schemes: list<string>|null, optional: bool}
     */
    private function securityFrom(array $node): array
    {
        if (! array_key_exists('security', $node) || ! is_array($node['security'])) {
            return ['schemes' => null, 'optional' => false];
        }

        $schemes = [];
        $optional = false;

        foreach ($node['security'] as $requirement) {
            if (! is_array($requirement) || $requirement === []) {
                $optional = true;

                continue;
            }

            foreach (array_keys($requirement) as $scheme) {
                $schemes[] = (string) $scheme;
            }
        }

        return ['schemes' => array_values(array_unique($schemes)), 'optional' => $optional];
    }

    /**
     * @return array<string, string|null>
     */
    private function responseShapes(mixed $responses): array
    {
        if (! is_array($responses)) {
            return [];
        }

        $shapes = [];

        foreach ($responses as $status => $response) {
            $shapes[(string) $status] = $this->shapeOf($response);
        }

        ksort($shapes);

        return $shapes;
    }

    /**
     * The declared schema behind a requestBody / response: the `$ref`'s last segment when it names a
     * component, else the inline `type`. Null when the node declares no content at all.
     */
    private function shapeOf(mixed $node): ?string
    {
        if (! is_array($node) || ! is_array($node['content'] ?? null)) {
            return null;
        }

        foreach ($node['content'] as $media) {
            if (! is_array($media) || ! is_array($media['schema'] ?? null)) {
                continue;
            }

            $schema = $media['schema'];

            if (is_string($schema['$ref'] ?? null)) {
                $segments = explode('/', $schema['$ref']);

                return (string) end($segments);
            }

            // An array of components is described by its item, not by the word "array".
            if (($schema['type'] ?? null) === 'array' && is_string($schema['items']['$ref'] ?? null)) {
                $segments = explode('/', $schema['items']['$ref']);

                return end($segments).'[]';
            }

            if (is_string($schema['type'] ?? null)) {
                return $schema['type'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    private function arrayAt(array $path): array
    {
        $node = $this->document;

        foreach ($path as $segment) {
            if (! is_array($node) || ! isset($node[$segment])) {
                return [];
            }

            $node = $node[$segment];
        }

        return is_array($node) ? $node : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
