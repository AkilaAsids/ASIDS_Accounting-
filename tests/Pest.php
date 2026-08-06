<?php

declare(strict_types=1);

use Asids\Core\Platform\Exceptions\PlatformException;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
*/

uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Domain expectations
|--------------------------------------------------------------------------
|
| These exist so an assertion reads as the property being asserted. `expect($response)
| ->toBeProblem('cross-tenant-write')` states the intent; asserting a status code and then
| digging into a JSON path states the mechanism, and the two drift apart when the mechanism
| changes.
|
*/

/**
 * Asserts an RFC 9457 problem document with the given stable code.
 */
expect()->extend('toBeProblem', function (string $code, ?int $status = null) {
    /** @var TestResponse $response */
    $response = $this->value;

    $body = $response->json();

    expect($body)->toHaveKeys(['type', 'title', 'status', 'detail']);
    expect($body['type'])->toEndWith('/'.$code);

    if ($status !== null) {
        expect($response->getStatusCode())->toBe($status);
    }

    // Every problem carries a correlation id, because "quote the request id" is useless
    // advice if the document does not contain one.
    expect($body)->toHaveKey('request_id');

    return $this;
});

/**
 * Asserts the standard success envelope.
 */
expect()->extend('toBeEnvelope', function (?int $status = 200) {
    /** @var TestResponse $response */
    $response = $this->value;

    $response->assertStatus($status);

    expect($response->json())->toHaveKeys(['data', 'meta']);
    expect($response->json('meta'))->toHaveKeys(['request_id', 'api_version']);

    return $this;
});

/**
 * Asserts that a value never appears in a response — used for credential leak checks.
 */
expect()->extend('toNotLeak', function (string ...$needles) {
    /** @var TestResponse $response */
    $response = $this->value;
    $body = $response->getContent();

    foreach ($needles as $needle) {
        expect($body)->not->toContain($needle);
    }

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Runs a closure and returns the PlatformException it raised, failing if it raised none.
 *
 * Preferred over `expectException` when the test needs to assert on the problem code and the
 * context, which is most of the time in this codebase.
 */
function catchPlatformException(Closure $callback): PlatformException
{
    try {
        $callback();
    } catch (PlatformException $exception) {
        return $exception;
    }

    test()->fail('Expected a PlatformException, but none was thrown.');
}
