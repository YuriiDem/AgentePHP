<?php

declare(strict_types=1);

namespace AgentePHP\Providers\Ollama;

use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\ToolCall;
use AgentePHP\Chat\Messages\ToolMessage;
use AgentePHP\Tools\Tool;

/**
 * Translates our messages and tools into the shape Ollama's /api/chat expects.
 *
 * Close to OpenAI's format, because Ollama modelled its endpoint on it - but not the same:
 * the arguments of a tool call are an object here rather than a JSON string, and a tool
 * result carries no call id, since Ollama does not issue one. Two independent APIs on
 * separate release schedules, so this stays a class of its own rather than sharing one.
 */
final class OllamaMessageMapper
{
    /**
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>>
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
     */
    private function one(Message $message): array
    {
        if ($message instanceof ToolMessage) {
            // No id to send back: Ollama pairs a result with the request by position, so
            // the name is the only thing worth carrying.
            return [
                'role' => 'tool',
                'content' => $message->content(),
            ];
        }

        $mapped = [
            'role' => $message->role()->value,
            'content' => $message->content(),
        ];

        if ($message instanceof AssistantMessage && $message->hasToolCalls()) {
            $mapped['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'function' => [
                        'name' => $call->name,

                        // An object, not a JSON string - the difference from OpenAI.
                        'arguments' => $call->arguments,
                    ],
                ],
                $message->toolCalls(),
            );
        }

        return $mapped;
    }
}
