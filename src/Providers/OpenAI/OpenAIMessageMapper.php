<?php

declare(strict_types=1);

namespace AgentePHP\Providers\OpenAI;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\ToolCall;
use AgentePHP\Chat\Messages\ToolMessage;
use AgentePHP\Tools\Tool;
use JsonException;

/**
 * Translates our messages and tools into the shape OpenAI's chat-completions API expects.
 *
 * An Adapter, and a deliberately separate class from the provider: the mapping is the part
 * most likely to change when a vendor revises its API, and it is far easier to test as a
 * pure function from messages to arrays than through an HTTP round trip.
 */
final class OpenAIMessageMapper
{
    /**
     * OpenAI takes the system prompt as an ordinary message at the head of the conversation,
     * so every role maps one-to-one - including the tool results, which are messages here
     * too.
     *
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    public function map(array $messages): array
    {
        $mapped = [];

        foreach ($messages as $message) {
            $mapped[] = $this->one($message);
        }

        return $mapped;
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<array<string, mixed>>
     */
    public function tools(array $tools): array
    {
        return array_map(
            static fn (Tool $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->jsonSchema(),
                ],
            ],
            $tools,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function one(Message $message): array
    {
        if ($message instanceof ToolMessage) {
            return [
                'role' => 'tool',
                'tool_call_id' => $message->toolCallId,
                'content' => $message->content(),
            ];
        }

        $mapped = [
            'role' => $message->role()->value,
            'content' => $message->content(),
        ];

        if ($message instanceof AssistantMessage && $message->hasToolCalls()) {
            // OpenAI wants the arguments as a JSON *string* inside the message, not as an
            // object - the one place in this payload where JSON is nested in JSON.
            $mapped['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        'arguments' => json_encode($call->arguments, JSON_THROW_ON_ERROR),
                    ],
                ],
                $message->toolCalls(),
            );
        }

        return $mapped;
    }
}
