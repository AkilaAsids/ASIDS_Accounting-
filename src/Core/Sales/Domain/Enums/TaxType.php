<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Domain\Enums;

/**
 * The regimes a tax code can belong to.
 *
 * Four cases, and the distinctions are not cosmetic — a tax authority treats them differently and a
 * return has to report them separately. `Exempt` and `ZeroRated` both charge nothing and are not the
 * same thing: a zero-rated supply is taxable at 0% and stays inside the VAT system, an exempt supply
 * is outside it. Reporting one as the other misstates a return even though both add nothing to an
 * invoice.
 *
 * The machine names double as compliance-pack regime identifiers, so a jurisdiction that does not
 * recognise a regime can decline it through `CompliancePackContract::supportedTaxRegimes()` rather
 * than this enum needing a country dimension.
 *
 * SVAT is recognised as a legitimate type and resolves at whatever rate is configured for it —
 * ordinarily zero. Its suspended-payment mechanics are deliberately absent: SVAT's accounting differs
 * materially from VAT and implementing it properly needs invoice rules that do not exist yet.
 * Recognising the type now means a company registered for SVAT can classify its codes correctly, and
 * the later phase adds behaviour rather than migrating data.
 */
enum TaxType: string
{
    case Vat = 'vat';
    case Svat = 'svat';
    case Exempt = 'exempt';
    case ZeroRated = 'zero_rated';

    public function label(): string
    {
        return match ($this) {
            self::Vat => 'VAT',
            self::Svat => 'SVAT (suspended VAT)',
            self::Exempt => 'Exempt',
            self::ZeroRated => 'Zero rated',
        };
    }

    /**
     * Whether this regime may carry a rate above zero.
     *
     * Exempt and zero-rated may not, by definition, and the database says so too. SVAT may in
     * principle — the rate a company configures for it is its business, and in Sri Lanka today that is
     * zero — so this returns true rather than encoding a rate as a fact about the regime.
     */
    public function allowsNonZeroRate(): bool
    {
        return match ($this) {
            self::Vat, self::Svat => true,
            self::Exempt, self::ZeroRated => false,
        };
    }

    /**
     * Whether a supply under this regime belongs inside the VAT system for reporting.
     *
     * Zero-rated is taxable at 0% and reportable; exempt is outside. The distinction exists here
     * because it is the one a return depends on and the one most easily lost.
     */
    public function isWithinVatSystem(): bool
    {
        return match ($this) {
            self::Vat, self::Svat, self::ZeroRated => true,
            self::Exempt => false,
        };
    }

    /**
     * The regime identifier a compliance pack declares support for.
     *
     * Identical to the stored value today. Kept as a method rather than used directly so that a
     * jurisdiction naming a regime differently does not force a change to the column's values.
     */
    public function regime(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function regimes(): array
    {
        return array_map(static fn (self $type): string => $type->regime(), self::cases());
    }

    /**
     * The machine names, for a `Rule::in()` on the HTTP boundary.
     *
     * Mirrors `AccountType::values()` (`AccountType.php:103-106`): the enum stays the source of
     * truth, and a request rule never hand-lists the cases somewhere they can drift from it.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
