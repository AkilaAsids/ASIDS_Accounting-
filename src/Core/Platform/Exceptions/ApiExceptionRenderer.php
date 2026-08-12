<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Exceptions;

use Asids\Core\Platform\Domain\Contracts\ProblemDetails;
use Asids\Core\Platform\Support\RequestContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API failure as an RFC 9457 problem document.
 *
 * Two rules govern this class:
 *
 *   1. A client always receives a machine-readable `type`, so integrations can
 *      branch on the failure without string matching a message that may be
 *      translated or reworded.
 *
 *   2. Nothing internal ever crosses the boundary. Messages, SQL, file paths and
 *      stack traces are logged with the request id and replaced in the response
 *      by a generic sentence, unless the exception explicitly declares itself
 *      client-facing by implementing ProblemDetails.
 */
final class ApiExceptionRenderer
{
    private const string DOCS_BASE = 'https://docs.asidstech.com/errors/';

    public static function register(Exceptions $exceptions): void
    {
        // Report-time enrichment: every log line for an exception carries the
        // same correlation identifiers the client was given.
        $exceptions->context(static fn (): array => app(RequestContext::class)->toArray());

        $exceptions->render(static function (Throwable $e, Request $request): ?JsonResponse {
            if (! self::wantsProblemDocument($request)) {
                return null;
            }

            return self::toProblemResponse($e, $request);
        });
    }

    private static function wantsProblemDocument(Request $request): bool
    {
        return $request->expectsJson()
            || $request->is('api/*')
            || $request->wantsJson();
    }

    private static function toProblemResponse(Throwable $e, Request $request): JsonResponse
    {
        $context = app(RequestContext::class);

        [$status, $type, $title, $detail, $extensions] = match (true) {
            $e instanceof ValidationException => [
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'validation-failed',
                'The submitted data is invalid.',
                'One or more fields failed validation. See `errors` for details.',
                ['errors' => $e->errors()],
            ],

            $e instanceof ProblemDetails => [
                $e->problemStatus(),
                $e->problemType(),
                $e->problemTitle(),
                $e->problemDetail(),
                $e->problemExtensions(),
            ],

            $e instanceof AuthenticationException => [
                Response::HTTP_UNAUTHORIZED,
                'unauthenticated',
                'Authentication required.',
                'This endpoint requires an authenticated session or a valid access token.',
                [],
            ],

            $e instanceof AuthorizationException => [
                Response::HTTP_FORBIDDEN,
                'forbidden',
                'Permission denied.',
                // Deliberately not echoing which permission was missing: that
                // enumerates the permission catalogue to an attacker.
                'Your account does not have permission to perform this action.',
                [],
            ],

            // `$this->authorize()` throws AuthorizationException, but it never reaches here as one:
            // Laravel's `Handler::prepareException()` converts every status-less AuthorizationException
            // into a Symfony AccessDeniedHttpException (keeping the original as `getPrevious()`) before
            // the renderer runs. Left unmatched, this fell through to the generic HttpExceptionInterface
            // arm below and every framework/policy-thrown 403 rendered as `http-403` instead of
            // `forbidden` — this arm must stay ahead of that one. Same shape as the explicit
            // AuthorizationException arm above, and for the same reason: the missing permission is
            // never echoed.
            $e instanceof AccessDeniedHttpException => [
                Response::HTTP_FORBIDDEN,
                'forbidden',
                'Permission denied.',
                'Your account does not have permission to perform this action.',
                [],
            ],

            // A missing model must not reveal *which* model, since that confirms
            // the existence of an id belonging to another tenant.
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => [
                Response::HTTP_NOT_FOUND,
                'not-found',
                'Resource not found.',
                'The requested resource does not exist, or you do not have access to it.',
                [],
            ],

            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                'http-'.$e->getStatusCode(),
                self::titleForStatus($e->getStatusCode()),
                self::safeHttpDetail($e),
                self::retryAfterExtension($e),
            ],

            default => [
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'internal-error',
                'Something went wrong.',
                'The request could not be completed. Quote the request id when contacting support.',
                [],
            ],
        };

        if ($status >= 500) {
            Log::error('Unhandled exception rendered as 500.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                ...$context->toArray(),
            ]);
        }

        $document = [
            'type' => Str::startsWith($type, 'https://') ? $type : self::DOCS_BASE.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->getRequestUri(),
            'request_id' => $context->requestId(),
            ...$extensions,
        ];

        // Only in local development, and only for genuine faults, is the
        // underlying message attached — never in staging or production.
        if ($status >= 500 && config('app.debug') === true) {
            $document['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return new JsonResponse(
            data: $document,
            status: $status,
            headers: [
                'Content-Type' => 'application/problem+json',
                'X-Request-Id' => $context->requestId(),
            ],
        );
    }

    /**
     * HTTP exceptions raised by the framework (405, 429, 413 …) carry messages
     * that are safe to echo; those raised by `abort()` in application code may
     * not be, so anything unexpected falls back to the status phrase.
     */
    private static function safeHttpDetail(HttpExceptionInterface $e): string
    {
        $message = trim($e->getMessage());

        if ($message === '' || Str::contains($message, ['/var/www', '\\', '::'])) {
            return self::titleForStatus($e->getStatusCode());
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private static function retryAfterExtension(HttpExceptionInterface $e): array
    {
        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

        return $retryAfter === null ? [] : ['retry_after_seconds' => (int) $retryAfter];
    }

    private static function titleForStatus(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Authentication required.',
            402 => 'Payment required.',
            403 => 'Permission denied.',
            404 => 'Resource not found.',
            405 => 'Method not allowed.',
            409 => 'Conflict.',
            413 => 'Payload too large.',
            419 => 'Session expired.',
            422 => 'The submitted data is invalid.',
            423 => 'Resource locked.',
            429 => 'Too many requests.',
            503 => 'Service temporarily unavailable.',
            default => 'Request failed.',
        };
    }
}
