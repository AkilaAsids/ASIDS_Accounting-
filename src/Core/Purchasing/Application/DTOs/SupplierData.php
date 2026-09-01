<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Application\DTOs;

/**
 * A supplier, as submitted.
 *
 * The payable-side mirror of `CustomerData`, less the two deferred fields (`creditLimit` and the
 * AP/receivable account), which have no defined payable-side meaning until bills exist in Wave 7.
 *
 * `code` is nullable because the service generates one when it is left out — most users never think
 * about supplier codes, and making them invent one is friction for no benefit.
 *
 * `status` is absent on purpose. Creating a supplier always produces an active one, and moving between
 * states goes through named service methods (`deactivate`, `reactivate`, `archive`) rather than a
 * settable field. A status you can assign is a status whose transition rules nobody enforces.
 */
final readonly class SupplierData
{
    public function __construct(
        public string $name,
        public ?string $code = null,
        public ?string $legalName = null,
        public ?string $taxIdentificationNumber = null,
        public ?string $vatRegistrationNumber = null,
        public bool $isVatRegistered = false,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $district = null,
        public ?string $postalCode = null,
        public ?string $countryCode = null,
        public int $paymentTermsDays = 30,
        public ?string $branchId = null,
        public ?string $notes = null,
    ) {}

    /**
     * Built from a validated HTTP payload.
     *
     * The request layer arrives in a later slice; this exists now so the service has one construction
     * path rather than two, and so the field mapping lives with the DTO rather than in a controller.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            name: (string) $attributes['name'],
            code: self::optionalString($attributes, 'code'),
            legalName: self::optionalString($attributes, 'legal_name'),
            taxIdentificationNumber: self::optionalString($attributes, 'tax_identification_number'),
            vatRegistrationNumber: self::optionalString($attributes, 'vat_registration_number'),
            isVatRegistered: (bool) ($attributes['is_vat_registered'] ?? false),
            email: self::optionalString($attributes, 'email'),
            phone: self::optionalString($attributes, 'phone'),
            website: self::optionalString($attributes, 'website'),
            addressLine1: self::optionalString($attributes, 'address_line_1'),
            addressLine2: self::optionalString($attributes, 'address_line_2'),
            city: self::optionalString($attributes, 'city'),
            district: self::optionalString($attributes, 'district'),
            postalCode: self::optionalString($attributes, 'postal_code'),
            countryCode: self::optionalString($attributes, 'country_code'),
            paymentTermsDays: (int) ($attributes['payment_terms_days'] ?? 30),
            branchId: self::optionalString($attributes, 'branch_id'),
            notes: self::optionalString($attributes, 'notes'),
        );
    }

    /**
     * An absent key and an empty string both mean "not given".
     *
     * A form that submits every field posts empty strings for the ones left blank, and storing those
     * would make `WHERE email IS NULL` miss suppliers who have no e-mail address.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function optionalString(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
