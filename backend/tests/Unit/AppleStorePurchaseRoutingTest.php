<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\StorePurchaseVerificationException;
use App\Services\LiveStorePurchaseProviderGateway;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AppleStorePurchaseRoutingTest extends TestCase
{
    private const PRODUCT = 'rokn.coins.900';
    private const TRANSACTION = '1000000000000001';
    private const ACCOUNT = '20b579ee-c954-401f-9e01-b18c79a5a3c5';

    private string $privateKey;
    /** @var list<string> */
    private array $certificateChain;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Disposable PKI exercises the real JWS verifier without storing or
        // loading any account key or transaction from an Apple customer.
        $config = __DIR__ . '/../Fixtures/apple-store-signer.cnf';
        $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $rootKey = openssl_pkey_new($options);
        $leafKey = openssl_pkey_new($options);
        self::assertNotFalse($rootKey);
        self::assertNotFalse($leafKey);
        $rootRequest = openssl_csr_new(['commonName' => 'Rokn disposable root'], $rootKey, $options);
        $leafRequest = openssl_csr_new(['commonName' => 'Rokn disposable store signer'], $leafKey, $options);
        self::assertNotFalse($rootRequest);
        self::assertNotFalse($leafRequest);
        $root = openssl_csr_sign($rootRequest, null, $rootKey, 2, $options + ['x509_extensions' => 'root_ext'], 1);
        self::assertNotFalse($root);
        $leaf = openssl_csr_sign($leafRequest, $root, $rootKey, 1, $options + ['x509_extensions' => 'leaf_ext'], 2);
        self::assertNotFalse($leaf);
        self::assertTrue(openssl_pkey_export($leafKey, $privateKey, null, ['config' => $config]));
        $this->privateKey = $privateKey;
        $this->certificateChain = [];
        foreach ([$leaf, $root] as $certificate) {
            self::assertTrue(openssl_x509_export($certificate, $pem));
            $this->certificateChain[] = str_replace(
                ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n"], '', $pem
            );
        }
        config([
            'store_billing.apple.bundle_id' => 'com.rokn',
            'store_billing.apple.issuer_id' => 'disposable-issuer',
            'store_billing.apple.key_id' => 'disposable-key',
            'store_billing.apple.private_key_base64' => base64_encode($this->privateKey),
            'store_billing.apple.root_certificate_sha256' => [hash('sha256', base64_decode($this->certificateChain[1], true))],
        ]);
    }

    public function test_signed_sandbox_receipt_never_depends_on_production_authorization(): void
    {
        $jws = $this->signedTransaction();
        Http::fake([
            'https://api.storekit.itunes.apple.com/*' => Http::response([], 401),
            'https://api.storekit-sandbox.itunes.apple.com/*' => Http::response(['signedTransactionInfo' => $jws]),
        ]);
        $verified = $this->verify($jws);
        self::assertSame('sandbox', $verified->environment);
        self::assertSame(self::ACCOUNT, $verified->accountBinding);
        self::assertSame(11.11, $verified->grossAmount);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() ===
            'https://api.storekit-sandbox.itunes.apple.com/inApps/v1/transactions/' . self::TRANSACTION);
    }

    public function test_signed_production_receipt_is_not_sent_to_sandbox(): void
    {
        $jws = $this->signedTransaction(['environment' => 'Production']);
        Http::fake(['https://api.storekit.itunes.apple.com/*' => Http::response(['signedTransactionInfo' => $jws])]);
        self::assertSame('production', $this->verify($jws)->environment);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.storekit.itunes.apple.com/'));
    }

    public function test_tampering_with_signed_environment_is_rejected_before_any_server_request(): void
    {
        $segments = explode('.', $this->signedTransaction());
        $segments[1] = rtrim(strtr(base64_encode(json_encode(['environment' => 'Production'])), '+/', '-_'), '=');
        $this->assertRejected(implode('.', $segments), 'apple_transaction_signature_invalid');
        Http::assertNothingSent();
    }

    /** @dataProvider invalidDeviceClaims */
    public function test_signed_device_evidence_must_belong_to_this_purchase(array $claims, string $error): void
    {
        $this->assertRejected($this->signedTransaction($claims), $error);
        Http::assertNothingSent();
    }

    public static function invalidDeviceClaims(): array
    {
        return [
            [['environment' => 'Xcode'], 'store_purchase_environment_invalid'],
            [['environment' => ''], 'store_purchase_environment_invalid'],
            [['bundleId' => 'com.foreign.app'], 'store_product_mismatch'],
            [['productId' => 'foreign.product'], 'store_product_mismatch'],
            [['transactionId' => 'another-transaction'], 'store_product_mismatch'],
            [['appAccountToken' => 'foreign-account'], 'store_account_mismatch'],
            [['appAccountToken' => null], 'store_account_mismatch'],
            [['revocationDate' => 1780000000000], 'store_purchase_not_entitled'],
            [['type' => 'Auto-Renewable Subscription'], 'store_purchase_not_entitled'],
            [['quantity' => 2], 'store_purchase_quantity_unsupported'],
        ];
    }

    /** @dataProvider invalidServerClaims */
    public function test_server_signed_snapshot_is_still_required_and_authoritative(array $claims, string $error): void
    {
        Http::fake(['https://api.storekit-sandbox.itunes.apple.com/*' =>
            Http::response(['signedTransactionInfo' => $this->signedTransaction($claims)])]);
        $this->assertRejected($this->signedTransaction(), $error);
        Http::assertSentCount(1);
    }

    public static function invalidServerClaims(): array
    {
        return [
            [['environment' => 'Production'], 'store_purchase_environment_invalid'],
            [['productId' => 'foreign.product'], 'store_product_mismatch'],
            [['transactionId' => 'foreign-transaction'], 'store_product_mismatch'],
            [['appAccountToken' => 'foreign-account'], 'store_account_mismatch'],
            [['revocationDate' => 1780000000000], 'store_purchase_not_entitled'],
        ];
    }

    /** @dataProvider retryableProviderFailures */
    public function test_provider_failure_is_retryable_not_a_declined_purchase(int $status): void
    {
        Http::fake(['https://api.storekit-sandbox.itunes.apple.com/*' => Http::response([], $status)]);
        $this->assertRejected($this->signedTransaction(), 'apple_verification_unavailable', 503);
        Http::assertSentCount(1);
    }

    public static function retryableProviderFailures(): array
    {
        return [[401], [403], [429], [500], [503]];
    }

    public function test_connection_failure_preserves_retryable_verification(): void
    {
        Http::fake(fn () => throw new ConnectionException('Disposable connection failure'));
        $this->assertRejected($this->signedTransaction(), 'apple_verification_unavailable', 503);
    }

    public function test_not_found_is_not_retried_in_a_different_environment(): void
    {
        Http::fake(['https://api.storekit-sandbox.itunes.apple.com/*' => Http::response(['errorCode' => 4040010], 404)]);
        $this->assertRejected($this->signedTransaction(), 'store_purchase_not_found');
        Http::assertSentCount(1);
    }

    public function test_unsigned_server_response_is_not_sufficient_to_accept_a_purchase(): void
    {
        Http::fake(['https://api.storekit-sandbox.itunes.apple.com/*' => Http::response(['transactionId' => self::TRANSACTION])]);
        $this->assertRejected($this->signedTransaction(), 'apple_signed_transaction_missing');
    }

    private function signedTransaction(array $overrides = []): string
    {
        return JWT::encode(array_replace([
            'bundleId' => 'com.rokn', 'productId' => self::PRODUCT, 'transactionId' => self::TRANSACTION,
            'originalTransactionId' => self::TRANSACTION, 'appAccountToken' => self::ACCOUNT,
            'environment' => 'Sandbox', 'type' => 'Consumable', 'quantity' => 1,
            'currency' => 'EGP', 'price' => 11110, 'signedDate' => time() * 1000,
        ], $overrides), $this->privateKey, 'ES256', null, ['x5c' => $this->certificateChain]);
    }

    private function verify(string $jws): \App\Data\VerifiedStorePurchase
    {
        return (new LiveStorePurchaseProviderGateway())->verify('apple', self::PRODUCT, $jws, self::TRANSACTION, self::ACCOUNT);
    }

    private function assertRejected(string $jws, string $error, int $httpStatus = 422): void
    {
        try {
            $this->verify($jws);
            self::fail('Unverified purchase must not be accepted.');
        } catch (StorePurchaseVerificationException $exception) {
            self::assertSame($error, $exception->errorCode);
            self::assertSame($httpStatus, $exception->httpStatus);
        }
    }
}
