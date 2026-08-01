<?php

namespace Splicewire\Beam\Data;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ResponseBody extends Data
{
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

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(
            $this->toResponseArray(),
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
