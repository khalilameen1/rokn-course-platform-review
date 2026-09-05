<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;

/** One transport attempt per generation, including Guzzle's internal rewind recovery. */
final class OpenRouterCurlFactory implements CurlFactoryInterface
{
    private bool $started = false;
    private CurlFactory $factory;

    public function __construct()
    {
        $this->factory = new CurlFactory(0);
    }

    public function create(RequestInterface $request, array $options): EasyHandle
    {
        if ($this->started) {
            throw new AiProviderUnavailableException(
                false,
                'A generation transport attempt cannot be replayed.',
                outcomeUnknown: true,
                providerCode: 'request_replay_blocked'
            );
        }
        $this->started = true;

        return $this->factory->create($request, $options);
    }

    public function release(EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }
}
