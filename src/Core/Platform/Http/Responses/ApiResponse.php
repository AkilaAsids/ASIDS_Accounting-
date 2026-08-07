<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Http\Responses;

use Asids\Core\Platform\Support\RequestContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single success envelope for the whole API.
 *
 * Every successful response is `{ "data": ..., "meta": { ... } }`. Consistency
 * here is worth more than terseness: a client written against one endpoint can
 * parse every endpoint, and adding `meta` fields later never breaks a consumer.
 *
 * Failures are the mirror image and are produced by ApiExceptionRenderer as
 * RFC 9457 problem documents.
 */
final class ApiResponse
{
    /**
     * @param  JsonResource|array<array-key, mixed>|null  $data
     * @param  array<string, mixed>  $meta
     */
    public static function item(
        JsonResource|array|null $data,
        int $status = Response::HTTP_OK,
        array $meta = [],
    ): JsonResponse {
        return self::respond(['data' => $data], $status, $meta);
    }

    /**
     * @param  JsonResource|array<array-key, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function created(JsonResource|array $data, array $meta = []): JsonResponse
    {
        return self::respond(['data' => $data], Response::HTTP_CREATED, $meta);
    }

    /**
     * Paginated collections expose their cursor in `meta.pagination` rather than
     * at the top level, so the envelope shape never changes between a paginated
     * and an unpaginated endpoint.
     *
     * @param  ResourceCollection|LengthAwarePaginator<int, mixed>|CursorPaginator<int, mixed>  $collection
     * @param  array<string, mixed>  $meta
     */
    public static function collection(
        ResourceCollection|LengthAwarePaginator|CursorPaginator $collection,
        array $meta = [],
    ): JsonResponse {
        $paginator = match (true) {
            $collection instanceof ResourceCollection => $collection->resource,
            default => $collection,
        };

        $pagination = match (true) {
            $paginator instanceof LengthAwarePaginator => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            $paginator instanceof CursorPaginator => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'previous_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
            default => null,
        };

        $items = $collection instanceof ResourceCollection
            ? $collection->collection
            : $collection->items();

        return self::respond(
            payload: ['data' => $items],
            status: Response::HTTP_OK,
            meta: $pagination === null ? $meta : ['pagination' => $pagination, ...$meta],
        );
    }

    /**
     * For accepted-but-not-yet-done work: a queued export, a long report.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function accepted(string $message, array $meta = []): JsonResponse
    {
        return self::respond(
            payload: ['data' => ['message' => $message]],
            status: Response::HTTP_ACCEPTED,
            meta: $meta,
        );
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     */
    private static function respond(array $payload, int $status, array $meta): JsonResponse
    {
        $context = app(RequestContext::class);

        return new JsonResponse(
            data: [
                ...$payload,
                'meta' => [
                    'request_id' => $context->requestId(),
                    'api_version' => config('asids.api.version'),
                    ...$meta,
                ],
            ],
            status: $status,
            headers: ['X-Request-Id' => $context->requestId()],
        );
    }
}
