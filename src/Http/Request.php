<?php

declare(strict_types=1);

namespace AgentePHP\Http;

use InvalidArgumentException;
use JsonException;

/**
 * Everything a transport needs to make one call, and nothing else.
 *
 * A DTO: no behaviour beyond construction, immutable, and deliberately ignorant of curl -
 * which is what lets a test read a request instead of intercepting a network call.
 */
final readonly class Request
{
    /**
     * The properties are declared rather than promoted on purpose: they are narrower than
     * what the constructor accepts. A caller may pass any string - the value often comes
     * from configuration or from outside the program - and the guards below are what turn
     * it into a value a transport can rely on.
     *
     * @var non-empty-string
     */
    public string $method;

    /** @var non-empty-string */
    public string $url;

    /** @var array<string, string> */
    public array $headers;

    public ?string $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $method, string $url, array $headers = [], ?string $body = null)
    {
        if ($method === '') {
            throw new InvalidArgumentException(
                'A request needs an HTTP method, e.g. new Request(\'GET\', $url).',
            );
        }

        if ($url === '') {
            throw new InvalidArgumentException(sprintf(
                'A %s request needs a URL to send to. An empty URL usually means a '
                . 'configuration value was read before it was set.',
                $method,
            ));
        }

        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * The only shape every provider needs: a JSON POST.
     *
     * Slashes and unicode are left unescaped so that a body printed in a failing test, or a
     * log line, reads as the text that was actually sent.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public static function postJson(string $url, array $headers, array $payload): self
    {
        return new self(
            method: 'POST',
            url: $url,
            headers: ['Content-Type' => 'application/json'] + $headers,
            body: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
