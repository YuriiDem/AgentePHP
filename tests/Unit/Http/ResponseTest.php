<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Http;

use AgentePHP\Exceptions\HttpException;
use AgentePHP\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_a_2xx_status_is_successful(): void
    {
        self::assertTrue((new Response(200, ''))->isSuccessful());
        self::assertTrue((new Response(299, ''))->isSuccessful());
    }

    public function test_anything_outside_2xx_is_not_successful(): void
    {
        self::assertFalse((new Response(199, ''))->isSuccessful());
        self::assertFalse((new Response(300, ''))->isSuccessful());
        self::assertFalse((new Response(401, ''))->isSuccessful());
        self::assertFalse((new Response(500, ''))->isSuccessful());
    }

    public function test_it_decodes_a_json_object_body(): void
    {
        $response = new Response(200, '{"id":"msg_1","usage":{"input_tokens":3}}');

        self::assertSame('msg_1', $response->json()['id']);
    }

    public function test_it_explains_which_body_could_not_be_decoded(): void
    {
        $response = new Response(200, '<html>502 Bad Gateway</html>');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('502 Bad Gateway');

        $response->json();
    }

    public function test_it_refuses_a_json_body_that_is_not_an_object(): void
    {
        $this->expectException(HttpException::class);

        (new Response(200, '"just a string"'))->json();
    }
}
