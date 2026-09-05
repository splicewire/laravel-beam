<?php

namespace Splicewire\Beam\Tests\Fixtures\SdkAdapters;

class Record
{
    public function __construct(public array $values) {}

    public static function fromArray(array $values): static
    {
        return new static($values);
    }

    public static function hydrate(array $values): static
    {
        return new static(['custom' => $values]);
    }
}

class Document
{
    public function __construct(public string $contentType, public string $body) {}
}

class Measurement
{
    public function __construct(
        public string $artifactRef,
        public float $seconds,
        public array $summary = [],
        public ?float $wall = null,
        public int $count = 7,
        public bool $complete = false,
        public string $label = 'default',
    ) {}
}

class EvolvedMeasurement extends Measurement
{
    public function __construct(public string $newRequired, public int $revision = 9) {}
}

class ComplexRecord
{
    public function __construct(public \DateTimeImmutable $date) {}
}

/** Aliased to Saloon's response only in the isolated emitted-code process; beam does not require Saloon. */
class RuntimeResponse
{
    public function __construct(
        private mixed $jsonBody,
        private string $rawBody = '',
        private ?string $contentType = null,
        private bool $failed = false,
    ) {}

    public function throw(): self
    {
        if ($this->failed) {
            throw new \RuntimeException('HTTP refused');
        }

        return $this;
    }

    public function json(?string $path = null): mixed
    {
        $value = $this->jsonBody;
        foreach ($path === null ? [] : explode('.', $path) as $part) {
            $value = is_array($value) ? ($value[$part] ?? null) : null;
        }

        return $value;
    }

    public function body(): string
    {
        return $this->rawBody;
    }

    public function header(string $name): ?string
    {
        return $name === 'Content-Type' ? $this->contentType : null;
    }
}
