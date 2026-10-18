<?php

namespace App\Services;

use Exception;
use Google\Client;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Log;

class GoogleService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client;
        $connectTimeout = max(1.0, min(10.0, (float) config('services.google.connect_timeout_seconds', 3)));
        $timeout = max($connectTimeout, min(30.0, (float) config('services.google.timeout_seconds', 8)));
        $this->client->setHttpClient(new HttpClient([
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
        ]));
        $clientId = (string) config('services.google.client_id');
        if ($clientId !== '') {
            $this->client->setClientId($clientId);
        }
    }

    /**
     * Verify Google ID token and retrieve user data
     *
     * @return array ['id', 'name', 'email', 'picture']
     *
     * @throws Exception
     */
    public function verify(string $idToken, ?string $expectedNonceHash = null): array
    {
        try {
            Log::info('Verifying Google token');

            if ((string) config('services.google.client_id') === '') {
                throw new Exception('Google sign-in is not configured.');
            }

            $ticket = $this->client->verifyIdToken($idToken);

            if (! $ticket) {
                throw new Exception('Invalid Google ID token');
            }

            // google/apiclient 2.x Verify::verifyIdToken returns array payload; older code expected a ticket object.
            if (is_array($ticket)) {
                $claims = $ticket;
            } elseif (is_object($ticket) && method_exists($ticket, 'getAttributes')) {
                $attributes = $ticket->getAttributes();
                $claims = $attributes['payload'];
            } else {
                throw new Exception('Unexpected Google token verification result');
            }

            if (! isset($claims['sub'])) {
                throw new Exception('Missing user ID in token');
            }

            $expectedNonceHash = trim((string) $expectedNonceHash);
            if ($expectedNonceHash !== '') {
                $tokenNonce = trim((string) ($claims['nonce'] ?? ''));
                if (
                    $tokenNonce === ''
                    || ! hash_equals($expectedNonceHash, hash('sha256', $tokenNonce))
                ) {
                    throw new Exception('Google identity token nonce does not match.');
                }
            }

            if (empty($claims['email']) || filter_var($claims['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new Exception('Google account did not provide an email address');
            }

            if (
                ! array_key_exists('email_verified', $claims)
                || ! filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN)
            ) {
                throw new Exception('Google email address is not verified');
            }

            Log::info('Google token verified');

            return [
                'id' => (string) $claims['sub'],
                'identity_issued_at' => is_numeric($claims['iat'] ?? null)
                    ? (int) $claims['iat']
                    : null,
                'name' => $claims['name'] ?? null,
                'email' => $claims['email'] ?? null,
                'email_verified' => true,
                'picture' => $claims['picture'] ?? null,
            ];
        } catch (Exception $e) {
            Log::warning('Google identity verification failed');
            throw $e;
        }
    }
}
