<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Http;

use AgentePHP\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_it_carries_what_a_transport_needs_and_nothing_else(): void
    {
        $request = new Request('GET', 'https://example.test/ping');

        self::assertSame('GET', $request->method);
        self::assertSame('https://example.test/ping', $request->url);
        self::assertSame([], $request->headers);
        self::assertNull($request->body);
    }

    public function test_a_json_post_encodes_its_payload_and_declares_its_content_type(): void
    {
        $request = Request::postJson(
            'https://api.example.test/v1/chat',
            ['Authorization' => 'Bearer k'],
            ['model' => 'tiny', 'messages' => []],
        );

        self::assertSame('POST', $request->method);
        self::assertSame('{"model":"tiny","messages":[]}', $request->body);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame('Bearer k', $request->headers['Authorization']);
    }

    public function test_a_json_post_does_not_escape_slashes_or_unicode(): void
    {
        $request = Request::postJson('https://api.example.test', [], ['text' => 'a/b — ü']);

        self::assertSame('{"text":"a/b — ü"}', $request->body);
    }

    public function test_it_refuses_an_empty_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URL');

        new Request('GET', '');
    }

    public function test_it_refuses_an_empty_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('method');

        new Request('', 'https://example.test');
    }
}
