<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AppAssociationTest extends TestCase
{
    public function test_android_asset_links_advertise_only_the_configured_signed_application(): void
    {
        $fingerprint = implode(':', array_fill(0, 32, 'AB'));
        config([
            'app_links.android_package' => 'com.rokn',
            'app_links.android_sha256_fingerprints' => [$fingerprint, strtolower($fingerprint), $fingerprint],
        ]);

        $response = $this->get('/.well-known/assetlinks.json');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=3600, public, stale-while-revalidate=86400')
            ->assertHeader('X-Rokn-Mobile-Contract', '1')
            ->assertExactJson([[
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => 'com.rokn',
                    'sha256_cert_fingerprints' => [$fingerprint],
                ],
            ]]);
    }

    public function test_apple_association_advertises_only_supported_navigation_routes(): void
    {
        config([
            'app_links.apple_app_ids' => [
                'ABCDE12345.com.rokn.app',
                'ABCDE12345.com.rokn.app',
                'FGHIJ67890.com.rokn.app.beta',
            ],
        ]);

        $expected = [
            'applinks' => [
                'apps' => [],
                'details' => [
                    [
                        'appID' => 'ABCDE12345.com.rokn.app',
                        'paths' => [
                            '/home',
                            '/profile',
                            '/wallet',
                            '/support/*',
                            '/course/*',
                        ],
                    ],
                    [
                        'appID' => 'FGHIJ67890.com.rokn.app.beta',
                        'paths' => [
                            '/home',
                            '/profile',
                            '/wallet',
                            '/support/*',
                            '/course/*',
                        ],
                    ],
                ],
            ],
        ];

        $this->get('/.well-known/apple-app-site-association')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertExactJson($expected);

        $this->get('/apple-app-site-association')
            ->assertOk()
            ->assertExactJson($expected);
    }

    public function test_association_endpoints_fail_closed_for_missing_or_malformed_identity(): void
    {
        config([
            'app_links.android_package' => 'com.rokn',
            'app_links.android_sha256_fingerprints' => [],
            'app_links.apple_app_ids' => [],
        ]);

        $this->get('/.well-known/assetlinks.json')->assertNotFound();
        $this->get('/.well-known/apple-app-site-association')->assertNotFound();

        config([
            'app_links.android_package' => 'not-a-package',
            'app_links.android_sha256_fingerprints' => [implode(':', array_fill(0, 32, 'GG'))],
            'app_links.apple_app_ids' => ['ABCDE12345.*'],
        ]);

        $this->get('/.well-known/assetlinks.json')->assertNotFound();
        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
    }
}
