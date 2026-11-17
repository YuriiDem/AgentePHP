<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Integration\Http;

use AgentePHP\Exceptions\HttpException;
use AgentePHP\Http\CurlHttpClient;
use AgentePHP\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * CurlHttpClient is the one class a unit test cannot reach: its whole job is the network.
 *
 * These tests need outbound HTTP, so they are skipped unless AGENTE_HTTP_TESTS=1 is set.
 * Everything that could be decided without a network - what a 429 means, what a payload
 * should look like - lives in the providers and is covered in tests/Unit.
 */
final class CurlHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('AGENTE_HTTP_TESTS') !== '1') {
            self::markTestSkipped('Set AGENTE_HTTP_TESTS=1 to run tests that make real HTTP requests.');
        }
    }

    public function test_it_returns_the_status_and_body_of_a_real_response(): void
    {
        $response = (new CurlHttpClient())->send(new Request('GET', 'https://example.com/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Example Domain', $response->body);
    }

    public function test_it_reports_an_unsuccessful_status_as_a_response_rather_than_an_exception(): void
    {
        $response = (new CurlHttpClient())->send(new Request('GET', 'https://example.com/nothing-here'));

        self::assertFalse($response->isSuccessful());
    }

    public function test_it_explains_a_call_that_never_reached_a_server(): void
    {
        $client = new CurlHttpClient(timeoutSeconds: 5, connectTimeoutSeconds: 2);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('before a response arrived');

        $client->send(new Request('GET', 'https://localhost:9/'));
    }
}
