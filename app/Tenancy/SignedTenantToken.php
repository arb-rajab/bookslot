<?php

namespace App\Tenancy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * D-0009's public-path "signed-token" mechanism, generalized by D-0021 into
 * a reusable capability class: a stateless, purpose-scoped, tenant-carrying
 * token. Two named instances exist in the decision log — `manage_booking`
 * (D-0009's original manage-booking link) and `confirm_payment` (D-0021) —
 * both minted and verified through this one class, never a separate signing
 * scheme per purpose.
 *
 * Signing/tamper-evidence reuses Laravel's own Crypt facade (AES-256-CBC +
 * HMAC, keyed on APP_KEY) rather than introducing a new scheme — the
 * payload is a JSON envelope, encrypted, then base64url-encoded so the
 * result is safe to embed as a single URL path segment (Crypt's own base64
 * output contains '+', '/', and '=', none of which are safe there).
 *
 * No storage: unlike an API token, nothing is persisted or revocable by id —
 * verification is signature + claims only, per D-0021's explicit "no new
 * storage" decision.
 */
final class SignedTenantToken
{
    public static function issue(
        string $purpose,
        string $tenantId,
        string $appointmentId,
        ?Carbon $expiresAt = null,
    ): string {
        $payload = [
            'purpose' => $purpose,
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'issued_at' => Carbon::now()->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
        ];

        return self::toUrlSafeBase64(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array{purpose: string, tenant_id: string, appointment_id: string, issued_at: string, expires_at: ?string}
     *
     * @throws InvalidTenantTokenException on any bad signature, wrong
     *                                     purpose, or expiry — deliberately one exception type for
     *                                     all three (see its own docblock).
     */
    public static function verify(string $token, string $expectedPurpose): array
    {
        try {
            $decrypted = Crypt::decryptString(self::fromUrlSafeBase64($token));
            $payload = json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Broadly caught on purpose: this is an attacker-facing input
            // boundary (a caller-supplied token string), not internal
            // trusted state — any failure here (including a malformed
            // base64 string that never reaches a valid encrypted payload)
            // must fail closed as an invalid token, never as an uncaught
            // exception.
            throw new InvalidTenantTokenException;
        }

        if (
            ! is_array($payload)
            || ($payload['purpose'] ?? null) !== $expectedPurpose
            || ! is_string($payload['tenant_id'] ?? null)
            || ! is_string($payload['appointment_id'] ?? null)
        ) {
            throw new InvalidTenantTokenException;
        }

        if (is_string($payload['expires_at'] ?? null) && Carbon::parse($payload['expires_at'])->isPast()) {
            throw new InvalidTenantTokenException;
        }

        /** @var array{purpose: string, tenant_id: string, appointment_id: string, issued_at: string, expires_at: ?string} $payload */
        return $payload;
    }

    private static function toUrlSafeBase64(string $value): string
    {
        return rtrim(strtr($value, '+/', '-_'), '=');
    }

    private static function fromUrlSafeBase64(string $value): string
    {
        $base64 = strtr($value, '-_', '+/');

        return $base64.str_repeat('=', (4 - strlen($base64) % 4) % 4);
    }
}
