<?php

declare(strict_types=1);

namespace AgentePHP\Http;

use AgentePHP\Exceptions\HttpException;
use CurlHandle;

/**
 * The real transport, on ext-curl.
 *
 * Kept deliberately small: it turns a Request into a Response and nothing more. Every
 * decision that could be tested - what a 429 means, what a payload should look like - lives
 * in the provider instead, because this class is the one piece a unit test cannot reach
 * without a network. Its own test lives in tests/Integration.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $connectTimeoutSeconds = 10,
    ) {
    }

    public function send(Request $request): Response
    {
        $handle = curl_init();

        try {
            return $this->execute($handle, $request);
        } finally {
            curl_close($handle);
        }
    }

    private function execute(CurlHandle $handle, Request $request): Response
    {
        curl_setopt_array($handle, [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($request->headers),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
        ]);

        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $body = curl_exec($handle);

        if ($body === false) {
            throw HttpException::transportFailed(
                $request->method,
                $request->url,
                curl_error($handle),
                curl_errno($handle),
            );
        }

        /** @var string $body curl returns a string because CURLOPT_RETURNTRANSFER is set */
        return new Response(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}
