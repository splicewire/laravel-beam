<?php

namespace Splicewire\Beam\Data;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Write\Dedupe\DuplicateRejected;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one JSON envelope every beam-hosted endpoint returns.
 *
 * ## The role rule — static PRODUCES, fluent ADJUSTS
 *
 * Every member on this class is one of two things, and which one it is decides whether it is
 * `static` or `$this`-returning:
 *
 * - **Static — produces an envelope from source material.** {@see from} (spatie's),
 *   {@see success}, {@see paginated}, {@see exception}. There is no envelope yet; the argument is
 *   the material it is built out of.
 * - **Fluent — adjusts an envelope that already exists.** {@see created}, {@see accepted},
 *   {@see updated}, {@see deleted}, {@see invalid}, {@see badRequest}, {@see failure},
 *   {@see notFound}, {@see forbidden}, {@see conflict}, {@see unauthorized}, {@see withData},
 *   {@see withMessage}, {@see withMeta}, {@see withDebug}. The envelope is the receiver; these move one facet of it.
 *
 * The rule is not cosmetic — it is the thing whose absence made this class copyable. Nine hosts
 * carried their own `App\Data\ResponseBody` in which `created($data)` / `invalid($errors)` /
 * `failure($message)` were STATIC constructors, conflating the two roles. A host that then tried
 * to extend this class got two independently fatal errors — `Cannot make non static method
 * ::created() static` and an incompatible `toResponseArray()` signature — so the copy was the only
 * way forward, and the drift compounded. Stating the rule here is what makes the next divergence
 * mechanically checkable rather than re-discoverable (api-surface-coherence 110, 130, 131).
 *
 * A member added below must be placeable under one of the two headings. If it cannot be, the
 * question is which role it actually has — not which keyword is convenient at its call site.
 */
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

    /**
     * 422, not 400. It was `HTTP_BAD_REQUEST` until api-surface-coherence 130 measured the estate:
     * all eight non-`thingsontv` beam hosts answered validation failures 422 in their own copy of
     * this class, against five in-package sites reading it as 400 — and 422 is what Laravel's own
     * `ValidationException` returns, so a host that mixes framework validation with this envelope
     * was emitting two different codes for one condition. The five in-package sites are all
     * "a required parameter is missing", which is 400-shaped; they were re-pointed at
     * {@see badRequest()} rather than dragged to 422.
     */
    public const HTTP_VALIDATION_ERROR = Response::HTTP_UNPROCESSABLE_ENTITY;

    public const HTTP_BAD_REQUEST = Response::HTTP_BAD_REQUEST;

    public const HTTP_FORBIDDEN = Response::HTTP_FORBIDDEN;

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

    /**
     * Fluent — ADJUSTS. 400 — the request itself is malformed: a required parameter is absent or
     * unparseable, so there was never a value to validate. Distinct from {@see invalid()}, which is
     * 422: the request was well-formed and its VALUES were refused.
     */
    public function badRequest(): static
    {
        $this->success = false;
        $this->statusCode = static::HTTP_BAD_REQUEST;

        return $this;
    }

    /**
     * Fluent — ADJUSTS. 403 — the caller was identified and is still refused. Distinct from
     * {@see unauthorized()} (401), which says the caller was never identified at all.
     */
    public function forbidden(): static
    {
        $this->success = false;
        $this->statusCode = static::HTTP_FORBIDDEN;

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

    /**
     * Fluent — ADJUSTS. Replaces the payload.
     */
    public function withData(mixed $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Fluent — ADJUSTS. Replaces the message.
     */
    public function withMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Fluent — ADJUSTS. MERGES into `meta` rather than replacing it, so two callers each adding
     * their own key down one chain do not silently erase each other.
     *
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): static
    {
        $this->meta = [...$this->meta, ...$meta];

        return $this;
    }

    /**
     * Fluent — ADJUSTS. Merges into `debug`, which the projection publishes only when
     * `config('app.debug')` is on.
     *
     * @param  array<string, mixed>  $debug
     */
    public function withDebug(array $debug): static
    {
        $this->debug = [...$this->debug, ...$debug];

        return $this;
    }

    /**
     * Static — PRODUCES. The ordinary 200 envelope, built from the payload it carries.
     */
    public static function success(mixed $data = null, ?string $message = null): static
    {
        return new static(data: $data, message: $message);
    }

    /**
     * Static — PRODUCES. The error envelope, built from the throwable it reports.
     *
     * The class detail and file:line ride in `errors` and only when `config('app.debug')` is on —
     * the diagnostic slot a client is allowed to see. The stack trace deliberately does NOT come
     * along: it is what carries unencodable ARGUMENTS, and an envelope built here still renders
     * through {@see RendersJsonSafely}, but not carrying the trace is cheaper than surviving it.
     * A caller that wants the trace puts it in `debug` explicitly, where the projection gates it.
     */
    public static function exception(Throwable $e): static
    {
        $status = $e instanceof HttpExceptionInterface
            ? $e->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        return new static(
            success: false,
            statusCode: $status,
            message: $e->getMessage() ?: 'Server error.',
            errors: config('app.debug')
                ? ['exception' => $e::class, 'file' => $e->getFile().':'.$e->getLine()]
                : [],
        );
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
