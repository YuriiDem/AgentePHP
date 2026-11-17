<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Exceptions;

use AgentePHP\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;

final class HttpExceptionTest extends TestCase
{
    public function test_a_transport_failure_names_the_call_and_the_reason(): void
    {
        $exception = HttpException::transportFailed('POST', 'https://api.example.test/v1', 'Could not resolve host');

        self::assertStringContainsString('POST https://api.example.test/v1', $exception->getMessage());
        self::assertStringContainsString('Could not resolve host', $exception->getMessage());
    }

    /**
     * A PHP install with no CA bundle configured - the default on Windows - fails every
     * HTTPS call with a message that reads like a problem with the remote server. Since
     * this is the first thing a new user is likely to hit, the exception says what to fix.
     */
    public function test_a_missing_certificate_authority_bundle_explains_how_to_fix_php(): void
    {
        $exception = HttpException::transportFailed(
            'POST',
            'https://api.openai.com/v1/chat/completions',
            'SSL certificate problem: unable to get local issuer certificate',
            curlErrorNumber: 60,
        );

        self::assertStringContainsString('curl.cainfo', $exception->getMessage());
        self::assertStringContainsString('php.ini', $exception->getMessage());
    }

    public function test_an_unrelated_failure_does_not_mention_certificates(): void
    {
        $exception = HttpException::transportFailed(
            'GET',
            'https://api.example.test',
            'Operation timed out',
            curlErrorNumber: 28,
        );

        self::assertStringNotContainsString('curl.cainfo', $exception->getMessage());
    }
}
