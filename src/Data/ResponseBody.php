<?php

namespace Splicewire\Beam\Data;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Write\Dedupe\DuplicateRejected;

#[TypeScript]
class ResponseBody extends Data
{
    // Aliased because this class OVERRIDES `recoveredState()` and still needs the trait's version to
    // build on. A class method silently wins over a trait method of the same name, and `parent::`
    // reaches spatie's Data, not the trait — so without the alias the override has no way back in.
    use RendersJsonSafely {
        recoveredState as private publicStateOnly;
    }

    public const HTTP_SUCCESS = Response::HTTP_OK;

    public const HTTP_CREATED = Response::HTTP_CREATED;

    public const HTTP_ACCEPTED = Response::HTTP_ACCEPTED;

    public const HTTP_VALIDATION_ERROR = Response::HTTP_BAD_REQUEST;

    public const HTTP_SERVER_ERROR = Response::HTTP_INTERNAL_SERVER_ERROR;

    public const HTTP_UNAUTHORIZED = Response::HTTP_UNAUTHORIZED;

    public function __construct(
        public bool $success = true,
        public int $statusCode = Response::HTTP_OK,
        public ?string $message = null,
        public ?int $limit = null,
        public ?int $offset = null,
        public ?int $total = null,
        public mixed $data = null,
        /** @var array<int|string, mixed> */
        public array $errors = [],
        /** @var array<string, mixed> */
        public array $meta = [],
        /** @var array<string, mixed> */
        public array $debug = [],
    ) {}

    /**
     * The recovery body when {@see toResponseArray()} itself threw.
     *
     * This class's projection is also a FILTER — it drops `statusCode`, empty `errors`/`meta`, and
     * `debug` unless `config('app.debug')`. Visibility alone does not express that: every one of
     * those is a public property, so the trait's default read would publish `debug` — the stack
     * trace and SQL slot — in production, on the error path. So the strip is repeated here against
     * raw state rather than inherited.
     *
     * Deliberately does NOT call `toResponseArray()`: it just threw, and calling it again is how a
     * recovery becomes a second failure.
     *
     * @return array<array-key, mixed>
     */
    protected function recoveredState(): array
    {
        $state = $this->publicStateOnly();

        unset($state['statusCode']);
        if (empty($state['errors'])) {
            unset($state['errors']);
        }
        if (empty($state['meta'])) {
            unset($state['meta']);
        }
        if (! config('app.debug') || empty($this->debug)) {
            unset($state['debug']);
        }

        return $state;
    }

    public function toResponseArray(?string $route = null): array
    {
        $arr = $this->toArray();
        unset($arr['statusCode']);
        if (empty($arr['errors'])) {
            unset($arr['errors']);
        }
        if (empty($arr['meta'])) {
            unset($arr['meta']);
        }
        if (config('app.debug') && ! empty($this->debug)) {
            $arr['debug'] = $this->debug;
        } else {
            unset($arr['debug']);
        }

        return $arr;
    }

    /**
     * The error envelope is the last thing standing between a failure and whoever has to read it,
     * so its own serialization must never be able to replace the failure. See
     * {@see RendersJsonSafely} for the measurement that forced this (api-surface-coherence 109).
     */
    public function toResponse($request): JsonResponse
    {
        return $this->jsonResponseThatCannotThrow(
            fn () => $this->toResponseArray(),
            $this->statusCode
        );
    }

    public function created(): static
    {
        $this->statusCode = static::HTTP_CREATED;

        return $this;
    }

    public function accepted(): static
    {
        $this->statusCode = static::HTTP_ACCEPTED;

        return $this;
    }

    public function updated(): static
    {
        $this->statusCode = static::HTTP_SUCCESS;

        return $this;
    }

    public function deleted(): static
    {
        $this->statusCode = static::HTTP_SUCCESS;

        return $this;
    }

    public function invalid(): static
    {
        $this->success = false;
        $this->statusCode = static::HTTP_VALIDATION_ERROR;

        return $this;
    }

    public function failure(): static
    {
        $this->success = false;
        $this->statusCode = static::HTTP_SERVER_ERROR;

        return $this;
    }

    public function notFound(): static
    {
        $this->success = false;
        $this->statusCode = Response::HTTP_NOT_FOUND;

        return $this;
    }

    /**
     * 409 — the request was well-formed and authorized, and the STORE's state refuses it. Beam's
     * one raiser today is `x-beam-dedupe`'s `reject` mode (beam-facade ticket 50 §5); a caller
     * letting {@see DuplicateRejected} bubble gets the same status
     * with no wiring, and reaches for this only when assembling its own body.
     */
    public function conflict(): static
    {
        $this->success = false;
        $this->statusCode = Response::HTTP_CONFLICT;

        return $this;
    }

    public function unauthorized(): static
    {
        $this->success = false;
        $this->statusCode = static::HTTP_UNAUTHORIZED;

        return $this;
    }

    public static function paginated(LengthAwarePaginator $paginator): static
    {
        return static::from([
            'data' => $paginator->items(),
            'limit' => $paginator->perPage(),
            'offset' => ($paginator->currentPage() - 1) * $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }
}
