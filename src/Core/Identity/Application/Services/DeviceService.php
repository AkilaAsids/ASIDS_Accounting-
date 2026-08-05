<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Application\Services;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Identity\Domain\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Device recognition, so a user can answer "where am I signed in?" and revoke a
 * device they no longer control.
 *
 * A device is identified by a signed, long-lived cookie — not by a browser
 * fingerprint. Fingerprints of user agent plus screen size collide constantly
 * inside one office (identical corporate laptops), which would merge several
 * people's sessions into one "device" and make revocation meaningless. The cookie
 * is authoritative; the user agent only names the row for display.
 */
final class DeviceService
{
    private const string COOKIE_NAME = 'asids_device';

    private const int COOKIE_LIFETIME_MINUTES = 60 * 24 * 400;

    public function recognise(Request $request, User $user): UserDevice
    {
        $token = $this->tokenFrom($request);

        $device = UserDevice::query()
            ->where('user_id', $user->getKey())
            ->where('fingerprint_hash', hash('sha256', $token))
            ->first();

        $agent = $this->parseUserAgent((string) $request->userAgent());

        if ($device === null) {
            $device = new UserDevice();
            $device->fill([
                'user_id' => $user->getKey(),
                'fingerprint_hash' => hash('sha256', $token),
                'name' => $agent['name'],
                'device_type' => $agent['type'],
                'platform' => $agent['platform'],
                'browser' => $agent['browser'],
            ]);
        }

        $device->last_ip_address = $request->ip();
        $device->last_seen_at = now();
        $device->save();

        // Re-issued on every recognition so an active device's cookie does not
        // silently expire mid-use.
        Cookie::queue(Cookie::make(
            name: self::COOKIE_NAME,
            value: $token,
            minutes: self::COOKIE_LIFETIME_MINUTES,
            secure: ! app()->environment('local'),
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $device;
    }

    public function trust(UserDevice $device): UserDevice
    {
        $device->trusted_at = now();
        // Trust always expires. An indefinitely trusted device is a permanent
        // bypass of the second factor.
        $device->trust_expires_at = now()->addDays(30);
        $device->save();

        return $device;
    }

    public function revoke(UserDevice $device, User $revokedBy): UserDevice
    {
        $device->revoked_at = now();
        $device->revoked_by_id = $revokedBy->getKey();
        // Clearing trust is required by the table's own check constraint, and is
        // the substantive part of revocation.
        $device->trusted_at = null;
        $device->trust_expires_at = null;
        $device->save();

        return $device;
    }

    public function isTrusted(Request $request, User $user): bool
    {
        $token = $request->cookie(self::COOKIE_NAME);

        if (! is_string($token) || $token === '') {
            return false;
        }

        $device = UserDevice::query()
            ->where('user_id', $user->getKey())
            ->where('fingerprint_hash', hash('sha256', $token))
            ->first();

        return $device?->isTrusted() ?? false;
    }

    private function tokenFrom(Request $request): string
    {
        $existing = $request->cookie(self::COOKIE_NAME);

        return is_string($existing) && $existing !== ''
            ? $existing
            : Str::random(64);
    }

    /**
     * Deliberately minimal user agent parsing: enough to label a row in a list
     * ("Chrome on macOS"), not enough to pretend it is identification. Pulling in a
     * full device-detection library for a display string is not worth the
     * dependency or its regex-heavy hot path.
     *
     * @return array{name: string, type: string, platform: string, browser: string}
     */
    private function parseUserAgent(string $agent): array
    {
        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown platform',
        };

        $browser = match (true) {
            str_contains($agent, 'ASIDS-Mobile') => 'ASIDS mobile app',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $type = match (true) {
            str_contains($agent, 'iPad') || str_contains($agent, 'Tablet') => 'tablet',
            str_contains($agent, 'Mobile') || str_contains($agent, 'iPhone') => 'mobile',
            default => 'desktop',
        };

        return [
            'name' => $browser.' on '.$platform,
            'type' => $type,
            'platform' => $platform,
            'browser' => $browser,
        ];
    }
}
