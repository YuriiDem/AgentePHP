<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Support;

use AgentePHP\Http\HttpClientInterface;
use AgentePHP\Http\Request;
use AgentePHP\Http\Response;
use RuntimeException;

/**
 * An HTTP client that answers from a canned script and records what it was asked.
 *
 * This is how a provider becomes testable without an API key or a network round trip.
 * It lives in tests/ because it is test infrastructure, not part of the public API.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<Response> */
    private array $responses;

    /** @var list<Request> */
    private array $requests = [];

    public function __construct(Response ...$responses)
    {
        $this->responses = array_values($responses);
    }

    /**
     * The common case: one successful JSON response.
     *
     * @param array<string, mixed> $payload
     */
    public static function returningJson(array $payload, int $status = 200): self
    {
        return new self(new Response($status, json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    public static function returning(string $body, int $status = 200): self
    {
        return new self(new Response($status, $body));
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses);

        if ($response === null) {
            throw new RuntimeException(sprintf(
                'FakeHttpClient ran out of canned responses: request %d was %s %s. '
                . 'Pass one Response per expected request.',
                count($this->requests),
                $request->method,
                $request->url,
            ));
        }

        return $response;
    }

    public function lastRequest(): Request
    {
        $request = end($this->requests);

        if ($request === false) {
            throw new RuntimeException('FakeHttpClient was never sent a request.');
        }

        return $request;
    }

    /** @return list<Request> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * The decoded body of the last request, which is what provider tests assert on.
     *
     * @return array<string, mixed>
     */
    public function lastSentJson(): array
    {
        $body = $this->lastRequest()->body ?? '';
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('The last request body was not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
