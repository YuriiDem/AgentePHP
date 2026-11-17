<?php

declare(strict_types=1);

namespace AgentePHP\Providers\OpenAI;

use AgentePHP\Chat\Messages\Message;

/**
 * Translates our messages into the shape OpenAI's chat-completions API expects.
 *
 * An Adapter, and a deliberately separate class from the provider: the mapping is the part
 * most likely to change when a vendor revises its API, and it is far easier to test as a
 * pure function from messages to arrays than through an HTTP round trip.
 */
final class OpenAIMessageMapper
{
    /**
     * OpenAI takes the system prompt as an ordinary message at the head of the conversation,
     * so every role maps the same way.
     *
     * @param list<Message> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    public function map(array $messages): array
    {
        return array_map(
            static fn (Message $message): array => [
                'role' => $message->role()->value,
                'content' => $message->content(),
            ],
            $messages,
        );
    }
}
