<?php

declare(strict_types=1);

namespace AgentePHP\Chat\History;

use AgentePHP\Chat\Enums\MessageRole;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\SystemMessage;
use AgentePHP\Chat\Messages\ToolCall;
use AgentePHP\Chat\Messages\ToolMessage;
use AgentePHP\Chat\Messages\Usage;
use AgentePHP\Chat\Messages\UserMessage;
use AgentePHP\Exceptions\HistoryException;

/**
 * Translates a message to plain data and back.
 *
 * Its own class, and not a method on `FileChatHistory`, for two reasons. It is a pure
 * function of its input, so it is tested without touching a disk; and the translation is
 * what every persistent store needs, so the SQLite store the roadmap promises will reuse
 * this instead of copying it.
 *
 * No interface yet: there is one way to represent a message, and inventing a second format
 * before anything asks for one would be an abstraction without a case.
 */
final class MessageSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Message $message): array
    {
        $data = [
            'role' => $message->role()->value,
            'content' => $message->content(),
        ];

        // Only the assistant has a bill to remember.
        if ($message instanceof AssistantMessage) {
            $data['usage'] = [
                'prompt' => $message->usage()->prompt,
                'completion' => $message->usage()->completion,
            ];

            // Stored only when there are any: a conversation that loses its tool calls
            // cannot be replayed, because every vendor requires the result to follow the
            // request that asked for it.
            if ($message->hasToolCalls()) {
                $data['tool_calls'] = array_map(
                    static fn (ToolCall $call): array => [
                        'id' => $call->id,
                        'name' => $call->name,
                        'arguments' => $call->arguments,
                    ],
                    $message->toolCalls(),
                );
            }
        }

        if ($message instanceof ToolMessage) {
            $data['tool_call_id'] = $message->toolCallId;
            $data['tool_name'] = $message->toolName;
        }

        return $data;
    }

    /**
     * Rebuild a message that was stored earlier, possibly by another process or another
     * version of this library - which is why every field is checked rather than trusted.
     *
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): Message
    {
        $role = $data['role'] ?? null;

        if (!is_string($role)) {
            throw HistoryException::missingStoredField('role', 'one of: ' . implode(', ', $this->knownRoles()));
        }

        $content = $data['content'] ?? null;

        if (!is_string($content)) {
            throw HistoryException::missingStoredField('content', 'a string');
        }

        return match (MessageRole::tryFrom($role)) {
            MessageRole::User => new UserMessage($content),
            MessageRole::System => new SystemMessage($content),
            MessageRole::Assistant => new AssistantMessage(
                $content,
                $this->usageFrom($data),
                $this->toolCallsFrom($data),
            ),
            MessageRole::Tool => $this->toolMessageFrom($content, $data),
            null => throw HistoryException::unknownStoredRole($role, $this->knownRoles()),
        };
    }

    /**
     * A conversation stored before usage was recorded, or by a store that did not keep it,
     * is still a readable conversation - so a missing bill is zero rather than a failure.
     *
     * @param array<string, mixed> $data
     */
    private function usageFrom(array $data): Usage
    {
        $usage = $data['usage'] ?? null;

        if (!is_array($usage)) {
            return new Usage();
        }

        $prompt = $usage['prompt'] ?? 0;
        $completion = $usage['completion'] ?? 0;

        return new Usage(
            prompt: is_int($prompt) && $prompt >= 0 ? $prompt : 0,
            completion: is_int($completion) && $completion >= 0 ? $completion : 0,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<ToolCall>
     */
    private function toolCallsFrom(array $data): array
    {
        $stored = $data['tool_calls'] ?? null;

        if (!is_array($stored)) {
            return [];
        }

        $calls = [];

        foreach ($stored as $call) {
            if (!is_array($call) || !is_string($call['id'] ?? null) || !is_string($call['name'] ?? null)) {
                throw HistoryException::missingStoredField('tool_calls', 'entries with an id and a name');
            }

            $arguments = $call['arguments'] ?? [];

            /** @var array<string, mixed> $arguments */
            $arguments = is_array($arguments) ? $arguments : [];

            $calls[] = new ToolCall(id: $call['id'], name: $call['name'], arguments: $arguments);
        }

        return $calls;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toolMessageFrom(string $content, array $data): ToolMessage
    {
        $id = $data['tool_call_id'] ?? null;

        if (!is_string($id)) {
            throw HistoryException::missingStoredField('tool_call_id', 'the id of the call it answers');
        }

        $name = $data['tool_name'] ?? null;

        return new ToolMessage(
            content: $content,
            toolCallId: $id,
            toolName: is_string($name) ? $name : '',
        );
    }

    /**
     * @return list<string>
     */
    private function knownRoles(): array
    {
        return array_map(static fn (MessageRole $role): string => $role->value, MessageRole::cases());
    }
}
