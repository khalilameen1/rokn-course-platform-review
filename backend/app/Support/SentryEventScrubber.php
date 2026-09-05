<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

final class SentryEventScrubber
{
    private const SENSITIVE_KEY_PARTS = [
        'authorization', 'cookie', 'password', 'token', 'secret', 'api_key',
        'signature', 'card_number', 'cvv', 'cvc',
    ];

    public static function scrub(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        unset($request['data'], $request['cookies'], $request['query_string']);
        if (isset($request['url'])) {
            $request['url'] = strtok((string) $request['url'], '?#') ?: '';
        }
        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = self::scrubMap($request['headers']);
        }
        $event->setRequest($request);
        $event->setExtra(self::scrubMap($event->getExtra()));

        $tags = self::scrubMap($event->getTags());
        $request = self::currentRequest();
        if ($request !== null) {
            foreach (['request_id', 'endpoint', 'app_version', 'app_build', 'platform'] as $key) {
                unset($tags[$key]);
            }
            $tags = array_merge($tags, self::requestTags($request));
            $event->setUser(self::requestUser($request));
        } else {
            $event->setUser(self::safeUser($event->getUser()?->getId()));
        }
        $event->setTags($tags);

        return $event;
    }

    private static function currentRequest(): ?Request
    {
        try {
            $container = Container::getInstance();
            if (!$container->bound('request')) {
                return null;
            }

            $request = $container->make('request');
        } catch (\Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }

    /** @return array<string,string> */
    private static function requestTags(Request $request): array
    {
        $tags = [];
        $requestId = trim((string) $request->attributes->get('request_id', ''));
        if (Str::isUuid($requestId)) {
            $tags['request_id'] = strtolower($requestId);
        }

        $route = $request->route();
        if ($route instanceof Route) {
            $endpoint = '/' . ltrim($route->uri(), '/');
            if (strlen($endpoint) <= 180
                && preg_match('/^\/[A-Za-z0-9._\-\/{\}?]+$/', $endpoint) === 1) {
                $tags['endpoint'] = $endpoint;
            }
        }

        $version = self::safeHeader($request, 'X-Rokn-App-Version', '/^[0-9A-Za-z._-]{1,32}$/');
        if ($version !== null) {
            $tags['app_version'] = $version;
        }

        $build = self::safeHeader($request, 'X-Rokn-App-Build', '/^[0-9]{1,10}$/');
        $safeBuild = filter_var($build, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ]);
        if ($safeBuild !== false) {
            $tags['app_build'] = (string) $safeBuild;
        }

        $platform = strtolower(trim((string) $request->header('X-Rokn-Platform')));
        if (in_array($platform, ['android', 'ios'], true)) {
            $tags['platform'] = $platform;
        }

        return $tags;
    }

    private static function safeHeader(Request $request, string $name, string $pattern): ?string
    {
        $value = trim((string) $request->header($name));
        return preg_match($pattern, $value) === 1 ? $value : null;
    }

    private static function requestUser(Request $request): ?UserDataBag
    {
        try {
            $user = $request->user('api') ?: $request->user();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? self::safeUser($user->getKey()) : null;
    }

    private static function safeUser(mixed $userId): ?UserDataBag
    {
        $safeUserId = filter_var($userId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $safeUserId === false
            ? null
            : UserDataBag::createFromUserIdentifier($safeUserId);
    }

    /** @param array<mixed> $values @return array<mixed> */
    private static function scrubMap(array $values): array
    {
        foreach ($values as $key => $value) {
            $normalized = strtolower(str_replace('-', '_', (string) $key));
            if (self::isSensitiveKey($normalized)) {
                $values[$key] = '[Filtered]';
                continue;
            }
            if (is_array($value)) {
                $values[$key] = self::scrubMap($value);
            }
        }

        return $values;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_PARTS as $sensitivePart) {
            if (str_contains($key, $sensitivePart)) {
                return true;
            }
        }

        return false;
    }
}
