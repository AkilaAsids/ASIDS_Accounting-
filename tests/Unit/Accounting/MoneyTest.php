<?php

declare(strict_types=1);

use Asids\Core\Accounting\Domain\ValueObjects\Money;

/**
 * The money value object.
 *
 * Everything in the ledger is built on this, so it is tested harder than anything else in the phase.
 * The property that matters throughout: **no operation may lose or invent a minor unit.** A trial
 * balance that is out by one cent is indistinguishable, to the person reading it, from a system that
 * cannot be trusted with their books.
 */
describe('construction', function (): void {
    it('reads a decimal string exactly', function (): void {
        expect(Money::of('1234.56', 'LKR')->minorUnits)->toBe(12_345_600);
    });

    it('reads all four decimal places', function (): void {
        expect(Money::of('0.0001', 'LKR')->minorUnits)->toBe(1);
    });

    it('reads a negative amount', function (): void {
        expect(Money::of('-42.50', 'LKR')->minorUnits)->toBe(-425_000);
    });

    it('reads an integer amount with no decimal point', function (): void {
        expect(Money::of('100', 'LKR')->minorUnits)->toBe(1_000_000);
    });

    it('pads a short fraction rather than misreading it', function (): void {
        // "1.5" is one and a half, not one and five ten-thousandths. Getting this wrong would make
        // every hand-written amount in an API payload wrong by three orders of magnitude.
        expect(Money::of('1.5', 'LKR')->minorUnits)->toBe(15_000);
    });

    it('normalises the currency code', function (): void {
        expect(Money::of('1.00', ' lkr ')->currency)->toBe('LKR');
    });

    it('refuses more precision than it can represent', function (): void {
        // Not rounded silently. Five decimal places means the caller is working at a precision this
        // type does not hold, and quietly discarding the fifth digit is how a total stops matching
        // the sum of its parts.
        $exception = catchPlatformException(fn () => Money::of('1.00001', 'LKR'));

        expect($exception->problemCode())->toBe('invalid-money-amount');
    });

    it('refuses a float-formatted string with an exponent', function (): void {
        expect(catchPlatformException(fn () => Money::of('1.0e3', 'LKR'))->problemCode())
            ->toBe('invalid-money-amount');
    });

    it('refuses a thousands separator rather than guessing the locale', function (): void {
        // "1,234.56" and "1.234,56" are the same number in different locales. Parsing either would
        // mean picking one, and picking wrong changes the amount by a factor of a thousand.
        expect(catchPlatformException(fn () => Money::of('1,234.56', 'LKR'))->problemCode())
            ->toBe('invalid-money-amount');
    });

    it('refuses a malformed currency code', function (): void {
        expect(catchPlatformException(fn () => Money::of('1.00', 'RUPEES'))->problemCode())
            ->toBe('invalid-currency-code');
    });
});

describe('round tripping', function (): void {
    it('returns exactly what it was given, to four places', function (): void {
        expect(Money::of('1234.5678', 'LKR')->toDecimalString())->toBe('1234.5678');
    });

    it('always emits four decimal places, matching the database column', function (): void {
        // `numeric(19,4)` returns "100.0000". Emitting "100" would make a string comparison against
        // a stored value fail for two amounts that are equal.
        expect(Money::of('100', 'LKR')->toDecimalString())->toBe('100.0000');
    });

    it('round trips a negative amount', function (): void {
        expect(Money::of('-0.0001', 'LKR')->toDecimalString())->toBe('-0.0001');
    });

    it('round trips through minor units', function (): void {
        $original = Money::of('987.6543', 'LKR');

        expect(Money::ofMinorUnits($original->minorUnits, 'LKR')->equals($original))->toBeTrue();
    });
});

describe('arithmetic', function (): void {
    it('adds exactly where a float would not', function (): void {
        // The canonical float failure: 0.1 + 0.2 !== 0.3. This is the entire reason the class exists.
        expect(Money::of('0.1', 'LKR')->plus(Money::of('0.2', 'LKR'))->toDecimalString())
            ->toBe('0.3000');
    });

    it('subtracts exactly', function (): void {
        expect(Money::of('0.3', 'LKR')->minus(Money::of('0.1', 'LKR'))->toDecimalString())
            ->toBe('0.2000');
    });

    it('survives a long chain of additions without drifting', function (): void {
        $total = Money::zero('LKR');

        for ($i = 0; $i < 10_000; $i++) {
            $total = $total->plus(Money::of('0.01', 'LKR'));
        }

        // A float accumulator is measurably wrong well before ten thousand iterations. This is what
        // a year of transaction lines looks like to the totalling code.
        expect($total->toDecimalString())->toBe('100.0000');
    });

    it('negates and takes absolute values', function (): void {
        expect(Money::of('5.00', 'LKR')->negated()->toDecimalString())->toBe('-5.0000')
            ->and(Money::of('-5.00', 'LKR')->absolute()->toDecimalString())->toBe('5.0000');
    });

    it('refuses to combine different currencies', function (): void {
        $exception = catchPlatformException(
            fn () => Money::of('1.00', 'LKR')->plus(Money::of('1.00', 'USD')),
        );

        // No exchange rate exists until the FX phase, so there is no correct answer to return.
        expect($exception->problemCode())->toBe('currency-mismatch');
    });

    it('refuses to compare different currencies', function (): void {
        expect(catchPlatformException(
            fn () => Money::of('1.00', 'LKR')->isGreaterThan(Money::of('1.00', 'USD')),
        )->problemCode())->toBe('currency-mismatch');
    });

    it('treats amounts in different currencies as unequal rather than throwing', function (): void {
        // Equality is a question with an answer — they are not equal — so it does not throw. Only
        // operations that would have to invent a rate do.
        expect(Money::of('1.00', 'LKR')->equals(Money::of('1.00', 'USD')))->toBeFalse();
    });
});

describe('multiplication', function (): void {
    it('multiplies by a whole quantity', function (): void {
        expect(Money::of('3.3333', 'LKR')->multipliedBy('7')->toDecimalString())->toBe('23.3331');
    });

    it('multiplies by a rate', function (): void {
        // 18 % VAT on 100.00.
        expect(Money::of('100.00', 'LKR')->multipliedBy('0.18')->toDecimalString())->toBe('18.0000');
    });

    it('rounds half away from zero, not to even', function (): void {
        // PHP's default is banker's rounding, which sends 0.5 to the nearest even number. Every
        // accountant and every tax authority expects half away from zero, and a total that differs
        // from the one a bookkeeper reaches on a calculator is reported as a bug regardless of which
        // rule is statistically fairer.
        expect(Money::ofMinorUnits(5, 'LKR')->multipliedBy('0.1')->minorUnits)->toBe(1)
            ->and(Money::ofMinorUnits(15, 'LKR')->multipliedBy('0.1')->minorUnits)->toBe(2);
    });

    it('rounds a negative product away from zero too', function (): void {
        expect(Money::ofMinorUnits(-5, 'LKR')->multipliedBy('0.1')->minorUnits)->toBe(-1);
    });

    it('refuses a malformed multiplier', function (): void {
        expect(catchPlatformException(fn () => Money::of('1.00', 'LKR')->multipliedBy('abc'))->problemCode())
            ->toBe('invalid-money-factor');
    });
});

describe('allocation', function (): void {
    it('splits without losing a minor unit', function (): void {
        $parts = Money::of('100.00', 'LKR')->allocate([1, 1, 1]);

        // 33.3333 each would total 99.9999. The largest-remainder method puts the missing unit
        // somewhere deterministic instead of dropping it.
        $total = array_reduce(
            $parts,
            static fn (Money $carry, Money $part): Money => $carry->plus($part),
            Money::zero('LKR'),
        );

        expect($total->toDecimalString())->toBe('100.0000')
            ->and(count($parts))->toBe(3);
    });

    it('splits by uneven weights proportionally', function (): void {
        $parts = Money::of('100.00', 'LKR')->allocate([70, 30]);

        expect($parts[0]->toDecimalString())->toBe('70.0000')
            ->and($parts[1]->toDecimalString())->toBe('30.0000');
    });

    it('gives the remainder to the largest fractional part, deterministically', function (): void {
        // One minor unit across three equal weights: exactly one part gets it, and it is the same
        // one every time. A non-deterministic allocation makes a reversing entry fail to reverse.
        $first = Money::ofMinorUnits(1, 'LKR')->allocate([1, 1, 1]);
        $second = Money::ofMinorUnits(1, 'LKR')->allocate([1, 1, 1]);

        expect(array_map(static fn (Money $m): int => $m->minorUnits, $first))->toBe([1, 0, 0])
            ->and(array_map(static fn (Money $m): int => $m->minorUnits, $second))->toBe([1, 0, 0]);
    });

    it('allocates a negative amount without losing a unit', function (): void {
        // Credit notes and reversals are negative. The shortfall has the opposite sign, and the
        // distribution has to step the other way or the parts overshoot.
        $parts = Money::of('-100.00', 'LKR')->allocate([1, 1, 1]);

        $total = array_reduce(
            $parts,
            static fn (Money $carry, Money $part): Money => $carry->plus($part),
            Money::zero('LKR'),
        );

        expect($total->toDecimalString())->toBe('-100.0000');
    });

    it('preserves the total across many randomised allocations', function (): void {
        // The property, stated directly: whatever the amount and whatever the weights, the parts sum
        // to the whole. A hand-picked example can pass while the general rule fails.
        for ($case = 0; $case < 200; $case++) {
            $amount = Money::ofMinorUnits(random_int(-5_000_000, 5_000_000), 'LKR');

            $weights = [];
            for ($w = 0, $count = random_int(1, 9); $w < $count; $w++) {
                $weights[] = random_int(0, 100);
            }

            if (array_sum($weights) === 0) {
                $weights[0] = 1;
            }

            $total = array_reduce(
                $amount->allocate($weights),
                static fn (Money $carry, Money $part): Money => $carry->plus($part),
                Money::zero('LKR'),
            );

            expect($total->minorUnits)->toBe($amount->minorUnits);
        }
    });

    it('allocates zero across weights without complaint', function (): void {
        $parts = Money::zero('LKR')->allocate([1, 2]);

        expect($parts[0]->isZero())->toBeTrue()->and($parts[1]->isZero())->toBeTrue();
    });

    it('refuses an empty weight set', function (): void {
        expect(catchPlatformException(fn () => Money::of('1.00', 'LKR')->allocate([]))->problemCode())
            ->toBe('invalid-allocation');
    });

    it('refuses weights that are all zero', function (): void {
        expect(catchPlatformException(fn () => Money::of('1.00', 'LKR')->allocate([0, 0]))->problemCode())
            ->toBe('invalid-allocation');
    });

    it('refuses a negative weight', function (): void {
        expect(catchPlatformException(fn () => Money::of('1.00', 'LKR')->allocate([1, -1]))->problemCode())
            ->toBe('invalid-allocation');
    });
});

describe('rounding to a currency precision', function (): void {
    it('rounds to two places for a currency like LKR', function (): void {
        expect(Money::of('10.0050', 'LKR')->roundedTo(2)->toDecimalString())->toBe('10.0100');
    });

    it('rounds half away from zero', function (): void {
        expect(Money::of('10.005', 'LKR')->roundedTo(2)->toDecimalString())->toBe('10.0100')
            ->and(Money::of('-10.005', 'LKR')->roundedTo(2)->toDecimalString())->toBe('-10.0100');
    });

    it('rounds to zero places for a currency with no minor unit', function (): void {
        // Not every currency has cents. Storing 1234.5000 JPY and displaying ¥1,235 while the ledger
        // holds the half is how a trial balance stops tying to the accounts.
        expect(Money::of('1234.5', 'JPY')->roundedTo(0)->toDecimalString())->toBe('1235.0000');
    });

    it('leaves an already-rounded amount untouched', function (): void {
        expect(Money::of('10.99', 'LKR')->roundedTo(2)->toDecimalString())->toBe('10.9900');
    });

    it('refuses a precision it cannot store', function (): void {
        expect(catchPlatformException(fn () => Money::of('1.00', 'LKR')->roundedTo(6))->problemCode())
            ->toBe('unsupported-currency-precision');
    });
});

describe('predicates and presentation', function (): void {
    it('reports sign correctly', function (): void {
        expect(Money::of('1.00', 'LKR')->isPositive())->toBeTrue()
            ->and(Money::of('-1.00', 'LKR')->isNegative())->toBeTrue()
            ->and(Money::zero('LKR')->isZero())->toBeTrue();
    });

    it('compares within a currency', function (): void {
        expect(Money::of('2.00', 'LKR')->isGreaterThan(Money::of('1.00', 'LKR')))->toBeTrue()
            ->and(Money::of('1.00', 'LKR')->isLessThan(Money::of('2.00', 'LKR')))->toBeTrue();
    });

    it('renders with its currency for logs and messages', function (): void {
        expect((string) Money::of('1234.56', 'LKR'))->toBe('LKR 1234.5600');
    });
});
