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
 * Asserts that secret *values* never appear anywhere in a response body.
 *
 * Pass the actual credential — the bcrypt hash, the decrypted TOTP secret, the remember token —
 * not the name of the field holding it. Searching for the word "password" matches the legitimate
 * key `requires.password_change` and reports a leak that is not one; searching for the hash
 * matches only an actual disclosure. Use `toNotExposeFields()` for the field-name half.
 */
expect()->extend('toNotLeak', function (string ...$secrets) {
    /** @var TestResponse $response */
    $response = $this->value;
    $body = $response->getContent();

    expect($body)->toBeString();

    foreach ($secrets as $secret) {
        // Every string contains the empty string, so an empty needle is a test that can never
        // pass. It means the caller read an attribute that was null — a bug in the test, and one
        // that would otherwise present as a mysterious leak.
        expect($secret)->not->toBe('', 'toNotLeak() was given an empty secret to search for.');

        expect($body)->not->toContain($secret);
    }

    return $this;
});

/**
 * Asserts that no key anywhere in a response's JSON carries a sensitive name.
 *
 * The structural counterpart to `toNotLeak()`: it catches a field that has been serialised as
 * `null` or empty today but would carry a credential the moment the column is populated, which a
 * value search cannot see.
 */
expect()->extend('toNotExposeFields', function (string ...$fields) {
    /** @var TestResponse $response */
    $response = $this->value;

    /** @var list<string> $exposed */
    $exposed = [];

    $walk = function (mixed $node, string $path) use (&$walk, $fields, &$exposed): void {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $here = is_string($key) ? ltrim($path.'.'.$key, '.') : $path.'['.$key.']';

            if (is_string($key) && in_array($key, $fields, true)) {
                $exposed[] = $here;
            }

            $walk($value, $here);
        }
    };

    $walk($response->json(), '');

    // Collected and then asserted, rather than failing on the first hit: this registers an
    // assertion even when it passes — a leak check that counts as "no assertions performed" is
    // reported as risky and is one refactor away from being silently vacuous — and a failure names
    // every exposed path at once instead of one per run.
    expect($exposed)->toBe([], 'Response exposes credential fields: '.implode(', ', $exposed));

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
