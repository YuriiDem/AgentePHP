<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

use Throwable;

/** The request never produced a usable HTTP response, or the response body was not what it claimed. */
final class HttpException extends AgenteException
{
    /** CURLE_PEER_FAILED_VERIFICATION and CURLE_SSL_CACERT_BADFILE. */
    private const CERTIFICATE_ERRORS = [60, 77];

    public static function transportFailed(
        string $method,
        string $url,
        string $reason,
        int $curlErrorNumber = 0,
    ): self {
        $message = sprintf(
            '%s %s failed before a response arrived: %s. '
            . 'Check that the host is reachable from this machine and that no proxy is required.',
            $method,
            $url,
            $reason,
        );

        if (in_array($curlErrorNumber, self::CERTIFICATE_ERRORS, true)) {
            $message .= ' This PHP install cannot verify TLS certificates, which is the default on '
                . 'Windows: download https://curl.se/ca/cacert.pem and point php.ini at it with '
                . 'curl.cainfo="<path>\\cacert.pem" (and openssl.cafile for the same path). '
                . 'Do not disable certificate verification to work around this - it would send your '
                . 'API key to whoever answers.';
        }

        return new self($message);
    }

    public static function bodyIsNotJson(string $body, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Expected a JSON object in the response body, got: %s. '
                . 'A gateway or a proxy usually answers with HTML when it, rather than the API, '
                . 'handled the request.',
                self::excerpt($body),
            ),
            previous: $previous,
        );
    }

    public static function bodyIsNotAnObject(string $body): self
    {
        return new self(sprintf(
            'Expected a JSON object in the response body, got valid JSON that is not an object: %s.',
            self::excerpt($body),
        ));
    }

    private static function excerpt(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return '(an empty body)';
        }

        return mb_strlen($body) > 200
            ? mb_substr($body, 0, 200) . '...'
            : $body;
    }
}
