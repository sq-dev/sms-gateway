<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Fixtures;

use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal PSR-18 client used by provider adapter unit tests.
 *
 * Queue one or more responses (or {@see ClientExceptionInterface} instances) to
 * simulate a sequence of HTTP interactions, then inspect {@see $requests} to
 * assert how the adapter built its outbound call.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function enqueue(ResponseInterface|ClientExceptionInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new LogicException(
                'MockHttpClient received an unexpected request - queue is empty.',
            );
        }

        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->requests === []) {
            throw new LogicException('MockHttpClient has not received any request yet.');
        }

        return $this->requests[array_key_last($this->requests)];
    }
}
