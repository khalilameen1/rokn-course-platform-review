<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CourseAttachmentExternalUrl;
use PHPUnit\Framework\TestCase;

final class CourseAttachmentExternalUrlTest extends TestCase
{
    public function test_drive_file_links_preserve_the_public_resource_key(): void
    {
        foreach ([
            'https://drive.google.com/file/d/abc_DEF-123/view?usp=sharing&resourcekey=0-secret',
            'https://drive.google.com/open?id=abc_DEF-123&resourcekey=0-secret',
        ] as $url) {
            self::assertSame(
                'https://drive.google.com/uc?export=download&id=abc_DEF-123&resourcekey=0-secret',
                CourseAttachmentExternalUrl::normalize($url)
            );
        }
    }

    public function test_dropbox_file_links_download_and_keep_access_parameters(): void
    {
        self::assertSame(
            'https://www.dropbox.com/scl/fi/id/assets.zip?rlkey=a%2Bb%2F&st=abc&dl=1',
            CourseAttachmentExternalUrl::normalize('https://www.dropbox.com/scl/fi/id/assets.zip?rlkey=a%2Bb%2F&dl=0&raw=1&st=abc#preview')
        );
        self::assertSame(
            'https://dropbox.com/s/id/file.pdf?dl=1',
            CourseAttachmentExternalUrl::normalize('https://dropbox.com/s/id/file.pdf')
        );
    }

    public function test_signed_urls_and_unrecognized_page_links_are_not_guessed_or_reencoded(): void
    {
        foreach ([
            'https://cdn.example.com/pack.zip?signature=A%2fb+%3D&part=1&part=2',
            'https://drive.google.com/uc?export=download&id=abc&confirm=t&uuid=xyz',
            'https://drive.google.com/drive/folders/abc?resourcekey=xyz',
            'https://docs.google.com/document/d/abc/edit',
            'https://drive.google.com.evil.example/file/d/abc/view',
            'https://dropbox.com/scl/fo/id/folder?dl=0',
            'https://drive.google.com/open?id[]=abc',
        ] as $url) {
            self::assertSame($url, CourseAttachmentExternalUrl::normalize($url));
        }
    }

    public function test_invalid_or_credential_bearing_links_are_rejected(): void
    {
        foreach ([
            '', 'http://example.com/file.pdf', 'javascript:alert(1)',
            'https://user:password@example.com/file.pdf',
            "https://example.com/file\r\nLocation: https://elsewhere.example",
            'https://example.com/'.str_repeat('a', 2000),
        ] as $url) {
            self::assertNull(CourseAttachmentExternalUrl::normalize($url));
        }
    }
}
