<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Providers\OpenAI;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Providers\OpenAI\OpenAIMessageMapper;
use PHPUnit\Framework\TestCase;

final class OpenAIMessageMapperTest extends TestCase
{
    public function test_it_maps_every_message_including_the_system_one_into_the_array(): void
    {
        $mapper = new OpenAIMessageMapper();

        $mapped = $mapper->map([
            new SystemMessage('Be brief.'),
            new UserMessage('Hello.'),
            new AssistantMessage('Hi.'),
        ]);

        self::assertSame([
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'Hello.'],
            ['role' => 'assistant', 'content' => 'Hi.'],
        ], $mapped);
    }

    public function test_it_preserves_the_order_it_was_given(): void
    {
        $mapper = new OpenAIMessageMapper();

        $mapped = $mapper->map([new UserMessage('first'), new UserMessage('second')]);

        self::assertSame('first', $mapped[0]['content']);
        self::assertSame('second', $mapped[1]['content']);
    }
}
