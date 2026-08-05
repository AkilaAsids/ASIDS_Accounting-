<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Presentation\Http\Controllers;

use Asids\Core\Platform\Http\Responses\ApiResponse;
use Asids\Core\Tenancy\Application\DTOs\ProvisionTenantData;
use Asids\Core\Tenancy\Application\Services\TenantProvisioningService;
use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Asids\Core\Tenancy\Presentation\Http\Requests\RegisterTenantRequest;
use Asids\Core\Tenancy\Presentation\Http\Resources\TenantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Public sign-up. Served on the central domain only.
 *
 * Extends the framework controller rather than ApiController because there is by
 * definition no authenticated user here.
 */
final class TenantRegistrationController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioning,
        private readonly TenantRepositoryContract $tenants,
    ) {}

    public function store(RegisterTenantRequest $request): JsonResponse
    {
        $result = $this->provisioning->provision(
            ProvisionTenantData::fromArray($request->validated())
        );

        return ApiResponse::created(
            data: new TenantResource($result['tenant']->load('domains')),
            meta: [
                'owner_email' => $result['owner']->email,
                // Present only when the caller did not choose a password, which is
                // the back-office path rather than public sign-up.
                'temporary_password' => $result['temporary_password'],
            ],
        );
    }

    /**
     * Live availability check for the sign-up form.
     *
     * Returns a suggestion rather than only a yes/no, because "taken" without an
     * alternative is where sign-up funnels lose people. Rate limited alongside the
     * other credential endpoints so it cannot be used to enumerate customers at
     * speed — though note that a workspace address is inherently public, since it
     * appears in the hostname.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $slug = strtolower(trim((string) $request->query('slug', '')));

        $valid = preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $slug) === 1;

        /** @var list<string> $reserved */
        $reserved = config('asids.tenancy.reserved_slugs', []);

        $available = $valid
            && ! in_array($slug, $reserved, true)
            && ! $this->tenants->slugExists($slug);

        return ApiResponse::item([
            'slug' => $slug,
            'valid' => $valid,
            'available' => $available,
            'suggestions' => $available || ! $valid ? [] : $this->suggest($slug),
        ]);
    }

    /**
     * @return list<string>
     */
    private function suggest(string $slug): array
    {
        $candidates = [];

        foreach ([Str::random(3), 'lk', (string) now()->year, 'erp'] as $suffix) {
            $candidate = mb_substr($slug, 0, 58).'-'.strtolower($suffix);

            if (! $this->tenants->slugExists($candidate)) {
                $candidates[] = $candidate;
            }

            if (count($candidates) === 3) {
                break;
            }
        }

        return $candidates;
    }
}
