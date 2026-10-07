<?php

declare(strict_types=1);

namespace Tests\Support;

/** Disposable P-256 key for capability fixtures, never an account credential. */
final class AppleClientSigningFixture
{
    public static function base64(): string
    {
        $options = [
            'config' => __DIR__.'/../Fixtures/apple-store-signer.cnf',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ];
        $key = openssl_pkey_new($options);
        if ($key === false || !openssl_pkey_export($key, $pem, null, $options)) {
            throw new \RuntimeException('Could not generate disposable Apple signing fixture.');
        }

        return base64_encode($pem);
    }
}
