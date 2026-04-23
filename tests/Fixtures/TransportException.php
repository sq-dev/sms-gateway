<?php

declare(strict_types=1);

namespace SmsGateway\Tests\Fixtures;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * PSR-18 network exception used by tests to simulate transport-level failures
 * (DNS, connection reset, TLS, ...). It implements
 * {@see NetworkExceptionInterface}, which extends
 * {@see \Psr\Http\Client\ClientExceptionInterface}, so adapters under test see
 * it exactly like a failure from a real client.
 */
final class TransportException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message = 'Simulated network failure.',
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
