<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

/**
 * The provider's API answered, and the answer was not usable.
 *
 * Both constructors name the provider, because an application that switches models on one
 * line needs to know which vendor refused.
 */
class ProviderException extends AgenteException
{
    public static function requestFailed(string $provider, int $status, string $body): self
    {
        return new self(sprintf(
            '%s refused the request with HTTP %d: %s',
            $provider,
            $status,
            self::readable($body),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function unexpectedPayload(string $provider, string $expectedPath, array $payload): self
    {
        return new self(sprintf(
            '%s answered with HTTP 200 but no %s, so there is no message to return. '
            . 'This usually means the model stopped for a reason this version does not handle yet '
            . '(a refusal, a tool call, or a content filter). The payload was: %s',
            $provider,
            $expectedPath,
            self::readable(json_encode($payload) ?: '(unencodable)'),
        ));
    }

    protected static function readable(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return '(an empty body)';
        }

        // The vendor's own message is the useful part; show it instead of the envelope.
        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $message = $decoded['error']['message'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return mb_strlen($body) > 300
            ? mb_substr($body, 0, 300) . '...'
            : $body;
    }
}
