<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Support;

use Illuminate\Support\Str;

/**
 * Ambient information about the current request, resolved once and shared.
 *
 * Three subsystems need the same answers — the audit trail, the structured
 * logger and the error renderer — and they must agree. Resolving each of them
 * independently from the request object produced, in an earlier iteration, audit
 * rows whose request id did not match the log lines for the same failure, which
 * made incident timelines useless.
 *
 * Registered as a scoped singleton, so a queue worker handling many jobs gets a
 * fresh instance per job rather than leaking the first job's identifiers into
 * every subsequent one.
 */
final class RequestContext
{
    private ?string $requestId = null;

    private ?string $tenantId = null;

    private ?string $companyId = null;

    private ?string $actorId = null;

    private ?string $impersonatorId = null;

    private ?string $accessTokenId = null;

    private string $channel = 'web';

    /** @var array<string, scalar|null> */
    private array $extra = [];

    /**
     * A UUID v7 so identifiers sort chronologically in a log aggregator.
     */
    public function requestId(): string
    {
        return $this->requestId ??= (string) Str::uuid7();
    }

    public function adoptRequestId(?string $requestId): void
    {
        // Only accept a caller-supplied correlation id when it is a well formed
        // UUID; otherwise an attacker could inject formatting into log lines.
        $this->requestId = ($requestId !== null && Str::isUuid($requestId))
            ? $requestId
            : (string) Str::uuid7();
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function companyId(): ?string
    {
        return $this->companyId;
    }

    public function setCompanyId(?string $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function setActorId(?string $actorId): void
    {
        $this->actorId = $actorId;
    }

    public function impersonatorId(): ?string
    {
        return $this->impersonatorId;
    }

    public function setImpersonatorId(?string $impersonatorId): void
    {
        $this->impersonatorId = $impersonatorId;
    }

    public function accessTokenId(): ?string
    {
        return $this->accessTokenId;
    }

    public function setAccessTokenId(?string $accessTokenId): void
    {
        $this->accessTokenId = $accessTokenId;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): void
    {
        $this->channel = $channel;
    }

    public function put(string $key, string|int|float|bool|null $value): void
    {
        $this->extra[$key] = $value;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return array_filter([
            'request_id' => $this->requestId(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'actor_id' => $this->actorId,
            'impersonator_id' => $this->impersonatorId,
            'access_token_id' => $this->accessTokenId,
            'channel' => $this->channel,
            ...$this->extra,
        ], static fn (string|int|float|bool|null $value): bool => $value !== null);
    }
}
