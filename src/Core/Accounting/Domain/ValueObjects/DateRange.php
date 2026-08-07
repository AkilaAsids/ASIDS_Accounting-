<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Domain\ValueObjects;

use Asids\Core\Accounting\Domain\Exceptions\InvalidDateRange;
use Carbon\CarbonImmutable;
use Stringable;

/**
 * An inclusive range of dates.
 *
 * Inclusive at both ends, and that is stated here because it is the single most common source of
 * off-by-one errors in financial reporting. A fiscal period running "1 April to 30 April" contains
 * transactions dated 30 April; a half-open range silently drops a day's trading from the month it
 * belongs to, and the error only surfaces when someone reconciles a bank statement.
 *
 * Dates, never datetimes. A transaction is posted on a date — the moment within that date is not a
 * property of the ledger, and comparing a timestamp against a period boundary is how the last few
 * hours of a month end up in the next one.
 *
 * @immutable
 */
final readonly class DateRange implements Stringable
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public function __toString(): string
    {
        return $this->start->toDateString().' to '.$this->end->toDateString();
    }

    public static function between(CarbonImmutable $start, CarbonImmutable $end): self
    {
        // Normalised to midnight so a range built from a timestamp behaves the same as one built
        // from a date string. Without this, `contains()` on the range's own end date can be false.
        $start = $start->startOfDay();
        $end = $end->startOfDay();

        if ($end->lessThan($start)) {
            throw InvalidDateRange::endBeforeStart($start->toDateString(), $end->toDateString());
        }

        return new self($start, $end);
    }

    public static function fromStrings(string $start, string $end): self
    {
        return self::between(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    /**
     * A single day, as a range. Used for "as at" reporting, where the answer is a balance on one date.
     */
    public static function onlyDay(CarbonImmutable $day): self
    {
        return self::between($day, $day);
    }

    public function contains(CarbonImmutable $date): bool
    {
        $day = $date->startOfDay();

        return $day->greaterThanOrEqualTo($this->start) && $day->lessThanOrEqualTo($this->end);
    }

    /**
     * Whether two ranges share any day at all.
     *
     * This is what the fiscal-period overlap rule is expressed in terms of: two periods of the same
     * company may not both contain a given date, or a transaction on that date belongs to two
     * periods and every report double-counts it.
     */
    public function overlaps(self $other): bool
    {
        return $this->start->lessThanOrEqualTo($other->end)
            && $other->start->lessThanOrEqualTo($this->end);
    }

    /**
     * Whether this range begins on the day immediately after the other ends.
     *
     * The contiguity rule for a fiscal year's periods: no gap between them, or transactions dated in
     * the gap belong to no period and can never be posted.
     */
    public function immediatelyFollows(self $other): bool
    {
        return $this->start->equalTo($other->end->addDay());
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function equals(self $other): bool
    {
        return $this->start->equalTo($other->start) && $this->end->equalTo($other->end);
    }
}
