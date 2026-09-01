<?php

namespace Splicewire\Beam\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Splicewire\Beam\Data\RendersJsonSafely;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Symfony\Component\HttpFoundation\Response;

/**
 * The neutral, host-free {@see ResponseEnvelope} beam-core ships as the default value of
 * `beam.core.http.envelope`: a plain `{ data: … }` (or `{ data, limit, offset, total }` for a page)
 * `JsonResponse`. A headless beam app gets working particle responses out of the box with no host wiring
 * at all, which is the whole argument for this being the default and for it STAYING the default — a
 * richer host (or a package composing beam, as `splicewire/tower` does) points that key at
 * {@see ResponseBodyEnvelope}, or binds an adapter of its own over its response DTO.
 *
 * The shape mirrors the common `{ data: … }` list/detail envelope so a host that adopts the default and a
 * host that binds its own DTO agree on the wire contract for the fields beam-core itself sets.
 */
class ArrayResponseEnvelope implements ResponseEnvelope
{
    public function item(mixed $data): Responsable
    {
        return $this->json(['data' => $this->normalize($data)], Response::HTTP_OK);
    }

    public function created(mixed $data): Responsable
    {
        return $this->json(['data' => $this->normalize($data)], Response::HTTP_CREATED);
    }

    public function paginated(LengthAwarePaginator $paginator): Responsable
    {
        return $this->json([
            'data' => array_map($this->normalize(...), $paginator->items()),
            'limit' => $paginator->perPage(),
            'offset' => ($paginator->currentPage() - 1) * $paginator->perPage(),
            'total' => $paginator->total(),
        ], Response::HTTP_OK);
    }

    /** Coerce a projected record (a spatie Data / Arrayable) to a JSON-serializable array; pass scalars through. */
    private function normalize(mixed $data): mixed
    {
        return $data instanceof Arrayable ? $data->toArray() : $data;
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body, int $status): Responsable
    {
        return new class($body, $status) implements Responsable
        {
            // The default envelope must be no less safe than the opt-in one. `normalize()` calls
            // `->toArray()` on a projected record, so this body is DB columns — a bytea, a latin-1
            // text predating a charset migration, JSON written by a non-PHP writer. Its richer
            // sibling ResponseBodyEnvelope goes through ResponseBody and inherits this defence for
            // free; a host taking beam's BOUND DEFAULT was getting a bare `new JsonResponse`.
            use RendersJsonSafely;

            /** @param  array<string, mixed>  $body */
            public function __construct(private array $body, private int $status) {}

            public function toResponse($request): JsonResponse
            {
                return $this->jsonResponseThatCannotThrow($this->body, $this->status);
            }
        };
    }
}
