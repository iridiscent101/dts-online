<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends push notifications through Firebase Cloud Messaging (the HTTP v1 API), signed with a Firebase service account.
 * No Firebase package: a Google access token is made from the service account key with PHP's OpenSSL and kept in the
 * cache for 50 minutes (Google's last for 60).
 *
 * Set one of these (Firebase console > Project settings > Service accounts > Generate new private key):
 * - FIREBASE_CREDENTIALS: the path to that JSON file on this server (outside public/, never committed), or
 * - FIREBASE_CREDENTIALS_BASE64: the whole JSON file, base64-encoded (for Vercel, which has no private files).
 */
class FirebaseMessaging
{
    public const SENT = 'sent';

    /** Firebase says the token no longer works (app removed, permission revoked): delete it. */
    public const INVALID_TOKEN = 'invalid';

    public const FAILED = 'failed';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array<string, string>|null|false */
    private array|null|false $credentials = false;

    public function configured(): bool
    {
        return $this->credentials() !== null && $this->projectId() !== '';
    }

    /**
     * @return self::SENT|self::INVALID_TOKEN|self::FAILED
     */
    public function send(string $token, string $title, string $body, ?string $link = null): string
    {
        if (! $this->configured()) {
            return self::FAILED;
        }

        $message = [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'webpush' => array_filter([
                'notification' => ['icon' => url('images/icon.png')],
                'fcm_options' => $link !== null ? ['link' => $link] : null,
            ]),
        ];

        try {
            $response = Http::withToken($this->accessToken())
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send", ['message' => $message]);
        } catch (\Throwable $error) {
            Log::warning('Push notification failed.', ['error' => $error->getMessage()]);

            return self::FAILED;
        }

        if ($response->successful()) {
            return self::SENT;
        }

        $errorCode = (string) collect($response->json('error.details', []))->pluck('errorCode')->filter()->first();
        if ($response->status() === 404 || $errorCode === 'UNREGISTERED'
            || ($response->status() === 400 && $errorCode === 'INVALID_ARGUMENT' && str_contains((string) $response->json('error.message'), 'token'))) {
            return self::INVALID_TOKEN;
        }

        // A rejected access token (key revoked or replaced) is dropped so the next push makes a new one.
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
        }
        Log::warning('Push notification failed.', ['status' => $response->status(), 'error' => $response->json('error.message')]);

        return self::FAILED;
    }

    private function accessToken(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(50), function (): string {
            $credentials = $this->credentials();
            $now = time();
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $segments = [
                $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64Url(json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $tokenUri,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('The Firebase service account key could not sign the request.');
            }
            $segments[] = $this->base64Url($signature);

            $response = Http::asForm()->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw();

            return (string) $response->json('access_token');
        });
    }

    /**
     * @return array<string, string>|null
     */
    private function credentials(): ?array
    {
        if ($this->credentials !== false) {
            return $this->credentials;
        }

        $json = null;
        $path = (string) config('services.firebase.credentials');
        if ($path !== '' && is_readable($path)) {
            $json = file_get_contents($path);
        } elseif (filled($encoded = config('services.firebase.credentials_base64'))) {
            $json = base64_decode((string) $encoded, true) ?: null;
        }

        $decoded = $json ? json_decode($json, true) : null;

        return $this->credentials = is_array($decoded) && filled($decoded['client_email'] ?? null) && filled($decoded['private_key'] ?? null)
            ? $decoded
            : null;
    }

    private function projectId(): string
    {
        return (string) (config('services.firebase.project_id') ?: ($this->credentials()['project_id'] ?? ''));
    }

    private function cacheKey(): string
    {
        return 'firebase-messaging-token:'.md5((string) ($this->credentials()['client_email'] ?? ''));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
