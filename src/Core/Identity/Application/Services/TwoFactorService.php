<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Application\Services;

use Asids\Core\Identity\Domain\Exceptions\InvalidTwoFactorCode;
use Asids\Core\Identity\Domain\Models\TwoFactorRecoveryCode;
use Asids\Core\Identity\Domain\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment and verification (RFC 6238).
 *
 * Enrolment is two-phase on purpose. `beginEnrolment` stores the secret but leaves
 * `two_factor_confirmed_at` null; only `confirmEnrolment`, which requires a working
 * code, marks it active and issues recovery codes. A single-phase enrolment locks
 * out any user whose authenticator was misconfigured — a support burden that is
 * entirely avoidable.
 */
final readonly class TwoFactorService
{
    public function __construct(private Google2FA $google2fa) {}

    /**
     * @return array{secret: string, otpauth_uri: string, qr_svg: string}
     */
    public function beginEnrolment(User $user): array
    {
        /** @var array{secret_length:int, issuer:string, digits:int, period:int, algorithm:string} $config */
        $config = config('asids.auth.two_factor');

        $secret = $this->google2fa->generateSecretKey($config['secret_length']);

        $user->two_factor_secret = $secret;
        $user->two_factor_enrolled_at = now();
        // Re-enrolling invalidates the previous confirmation, so a user cannot end
        // up with an active second factor that no longer matches the stored secret.
        $user->two_factor_confirmed_at = null;
        $user->save();

        $uri = $this->otpauthUri($user, $secret);

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => $this->renderQrCode($uri),
        ];
    }

    /**
     * @return list<string> The plaintext recovery codes, shown exactly once.
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        if ($user->two_factor_secret === null) {
            throw new InvalidTwoFactorCode;
        }

        if (! $this->verifyTotp($user, $code)) {
            throw new InvalidTwoFactorCode;
        }

        return DB::transaction(function () use ($user): array {
            $user->two_factor_confirmed_at = now();
            $user->save();

            return $this->regenerateRecoveryCodes($user);
        });
    }

    public function disable(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->two_factor_secret = null;
            $user->two_factor_enrolled_at = null;
            $user->two_factor_confirmed_at = null;
            $user->save();

            // Recovery codes are useless without a secret, and leaving them would
            // let a re-enrolment silently inherit codes the user had already
            // printed and lost.
            TwoFactorRecoveryCode::query()->where('user_id', $user->getKey())->delete();
        });
    }

    /**
     * Verify either a TOTP code or an unused recovery code.
     *
     * @return 'totp'|'recovery_code' The method that succeeded.
     */
    public function verify(User $user, string $code): string
    {
        if ($this->verifyTotp($user, $code)) {
            return 'totp';
        }

        if ($this->consumeRecoveryCode($user, $code)) {
            return 'recovery_code';
        }

        throw new InvalidTwoFactorCode;
    }

    public function verifyTotp(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret === null) {
            return false;
        }

        $code = preg_replace('/\D/', '', $code) ?? '';

        if ($code === '') {
            return false;
        }

        /** @var array{window:int} $config */
        $config = config('asids.auth.two_factor');

        // `verifyKeyNewer` rather than `verifyKey`: it refuses a code from a
        // timestep at or before the last accepted one, which is what actually
        // stops replay of a code sniffed seconds earlier.
        $timestamp = $this->google2fa->verifyKeyNewer(
            secret: $secret,
            key: $code,
            oldTimestamp: $this->lastAcceptedTimestep($user),
            window: $config['window'],
        );

        if ($timestamp === false) {
            return false;
        }

        $this->rememberTimestep($user, (int) $timestamp);

        return true;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        /** @var array{recovery_code_count:int, recovery_code_bytes:int} $config */
        $config = config('asids.auth.two_factor');

        // Clamped, not trusted: `random_bytes(0)` throws and a negative length is a fatal error,
        // so a mistyped environment variable would take out recovery-code generation entirely.
        $bytes = max(1, $config['recovery_code_bytes']);
        $count = max(1, $config['recovery_code_count']);

        return DB::transaction(function () use ($user, $bytes, $count): array {
            TwoFactorRecoveryCode::query()->where('user_id', $user->getKey())->delete();

            $plaintext = [];
            $rows = [];

            for ($i = 0; $i < $count; $i++) {
                $raw = strtolower(bin2hex(random_bytes($bytes)));
                // Hyphenated for legibility when written down; the hash is computed
                // over the normalised form so either presentation verifies.
                $display = implode('-', str_split($raw, 5));

                $plaintext[] = $display;
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->getKey(),
                    'code_hash' => TwoFactorRecoveryCode::hash($display),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            TwoFactorRecoveryCode::query()->insert($rows);

            return $plaintext;
        });
    }

    public function unusedRecoveryCodeCount(User $user): int
    {
        return TwoFactorRecoveryCode::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->count();
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = TwoFactorRecoveryCode::hash($code);

        // A conditional UPDATE rather than select-then-save: two concurrent
        // requests presenting the same code must not both succeed, and the
        // database's own row lock is the only reliable way to guarantee that.
        $consumed = TwoFactorRecoveryCode::query()
            ->where('user_id', $user->getKey())
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->update([
                'used_at' => now(),
                'used_ip' => request()->ip(),
            ]);

        return $consumed === 1;
    }

    private function otpauthUri(User $user, string $secret): string
    {
        /** @var array{issuer:string, digits:int, period:int, algorithm:string} $config */
        $config = config('asids.auth.two_factor');

        // The label carries the workspace as well as the address, so a user who is
        // a member of several ASIDS workspaces can tell the entries apart in their
        // authenticator app.
        $label = ($user->tenant->slug ?? 'platform').':'.$user->email;

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            rawurlencode($label),
            $secret,
            rawurlencode($config['issuer']),
            strtoupper($config['algorithm']),
            $config['digits'],
            $config['period'],
        );
    }

    private function renderQrCode(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(size: 256, margin: 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($uri);
    }

    /**
     * The last accepted TOTP timestep, held in the cache rather than a column.
     *
     * A cache is the right store: the value is worthless after `period` seconds, and
     * writing a column on every successful sign-in would add a write to the hottest
     * path in the system for data with a thirty-second lifetime.
     */
    private function lastAcceptedTimestep(User $user): ?int
    {
        $value = cache()->get($this->timestepKey($user));

        return is_int($value) ? $value : null;
    }

    private function rememberTimestep(User $user, int $timestamp): void
    {
        /** @var array{period:int, window:int} $config */
        $config = config('asids.auth.two_factor');

        cache()->put(
            key: $this->timestepKey($user),
            value: $timestamp,
            ttl: $config['period'] * ($config['window'] * 2 + 2),
        );
    }

    private function timestepKey(User $user): string
    {
        return 'two-factor:timestep:'.$user->getKey();
    }
}
