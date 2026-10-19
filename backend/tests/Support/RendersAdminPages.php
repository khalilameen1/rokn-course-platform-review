<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * Requests dashboard pages the way an operator's browser does and reads the
 * rendered HTML, so presentation contracts are checked on what is served
 * rather than on Blade source.
 */
trait RendersAdminPages
{
    private function dashboardUser(string $role, string $email = ''): User
    {
        return User::query()->forceCreate([
            'name' => ucfirst($role),
            'name_ar' => 'مستخدم '.$role,
            'email' => $email !== '' ? $email : $role.'-'.bin2hex(random_bytes(4)).'@rokn.test',
            'role' => $role,
            'active' => true,
        ]);
    }

    /** A fresh dashboard session per page, so flashes never leak between pages. */
    private function adminGet(User $user, string $url): TestResponse
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->flushSession();
        // PHPUnit reuses a PHP process across HTTP requests. Match Livewire's
        // request boundary instead of injecting the previous page's assets.
        Livewire::flushState();

        return $this->actingAs($user, 'web')->get($url);
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
    }

    private function assertNoInlineStyleAttributes(string $html, string $page): void
    {
        self::assertSame(
            0,
            $this->dom($html)->query('//*[@style]')->length,
            "{$page} renders inline style attributes"
        );
    }

    private function styleBlockCount(string $html): int
    {
        return $this->dom($html)->query('//style')->length;
    }

    /**
     * Local stylesheet and script URLs the page asks the browser to load.
     *
     * @return list<string> paths relative to the public root, without query
     */
    private function linkedLocalAssets(string $html): array
    {
        $xpath = $this->dom($html);
        $assets = [];
        foreach ($xpath->query('//link[@rel="stylesheet"]/@href | //script/@src') as $attribute) {
            $url = (string) $attribute->nodeValue;
            $path = (string) parse_url($url, PHP_URL_PATH);
            $host = parse_url($url, PHP_URL_HOST);
            if ($path === '' || ($host !== null && $host !== 'localhost')) {
                continue;
            }
            $assets[] = ltrim($path, '/');
        }

        return array_values(array_unique($assets));
    }

    private function assertLinksStylesheet(string $html, string $stylesheet, string $page): void
    {
        self::assertContains(
            'admin/assets/css/'.$stylesheet,
            $this->linkedLocalAssets($html),
            "{$page} does not load {$stylesheet}"
        );
    }
}
