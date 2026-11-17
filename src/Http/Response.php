<?php

declare(strict_types=1);

namespace AgentePHP\Http;

use AgentePHP\Exceptions\HttpException;
use JsonException;

/**
 * What came back: a status and a body.
 *
 * Headers are not modelled yet. Nothing in this milestone reads one, and adding a field
 * that only CurlHttpClient can populate would mean untestable code with no caller.
 */
final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The body decoded as a JSON object.
     *
     * Both failure modes get their own message, because the two have different causes: a
     * body that is not JSON at all usually came from a proxy, while valid JSON that is not
     * an object means the API changed shape.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw HttpException::bodyIsNotJson($this->body, $e);
        }

        if (!is_array($decoded)) {
            throw HttpException::bodyIsNotAnObject($this->body);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
