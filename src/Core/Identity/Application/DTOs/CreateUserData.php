<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Application\DTOs;

/**
 * Input for creating a user, from an invitation or from provisioning.
 *
 * @property-read list<string> $roleIds
 * @property-read list<string> $companyIds
 */
final readonly class CreateUserData
{
    /**
     * @param  list<string>  $roleIds
     * @param  list<string>  $companyIds
     */
    public function __construct(
        public string $firstName,
        public ?string $lastName,
        public string $email,
        public ?string $password = null,
        public array $roleIds = [],
        public array $companyIds = [],
        public ?string $phone = null,
        public ?string $jobTitle = null,
        public ?string $employeeNumber = null,
        public ?string $timezone = null,
        public ?string $locale = null,
        /**
         * True only for the workspace owner during provisioning. Everyone else is
         * created PendingInvitation and activates by following the invitation link,
         * which is what proves control of the address.
         */
        public bool $activateImmediately = false,
        public bool $mustChangePassword = false,
        public ?string $defaultCompanyId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        /** @var list<string> $roleIds */
        $roleIds = array_values(array_filter((array) ($input['role_ids'] ?? []), 'is_string'));
        /** @var list<string> $companyIds */
        $companyIds = array_values(array_filter((array) ($input['company_ids'] ?? []), 'is_string'));

        return new self(
            firstName: trim((string) ($input['first_name'] ?? '')),
            lastName: self::nullableString($input['last_name'] ?? null),
            email: strtolower(trim((string) ($input['email'] ?? ''))),
            password: self::nullableString($input['password'] ?? null),
            roleIds: $roleIds,
            companyIds: $companyIds,
            phone: self::nullableString($input['phone'] ?? null),
            jobTitle: self::nullableString($input['job_title'] ?? null),
            employeeNumber: self::nullableString($input['employee_number'] ?? null),
            timezone: self::nullableString($input['timezone'] ?? null),
            locale: self::nullableString($input['locale'] ?? null),
            defaultCompanyId: self::nullableString($input['default_company_id'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
