<?php

declare(strict_types=1);

namespace Binnash\Typesafe\Tests\Support;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * A PSR-18 client that replays a queue of responses and records the requests it received.
 */
final class FakeHttpClient implements ClientInterface
{
    /** Requests received so far, in order. */
    public array $requests = [];

    /** Invoked with each request before a queued response is returned. */
    public ?Closure $onRequest = null;

    /**
     * @param  list<ResponseInterface|Throwable>  $queue  Responses or failures to replay.
     */
    public function __construct(private array $queue = [])
    {
        $this->queue = array_values($queue);
    }

    /**
     * Append responses or failures to the queue.
     *
     * @param  list<ResponseInterface|Throwable>  $items
     */
    public function push(array $items): void
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }
    }

    /**
     * Number of queued items not yet consumed.
     */
    public function remaining(): int
    {
        return count($this->queue);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->onRequest !== null) {
            ($this->onRequest)($request);
        }

        if ($this->queue === []) {
            throw new RuntimeException(sprintf(
                'No queued HTTP response for %s %s.',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * The most recent request, or `null` when none was sent.
     */
    public function lastRequest(): ?RequestInterface
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }
}
