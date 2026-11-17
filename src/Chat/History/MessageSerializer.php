<?php

declare(strict_types=1);

namespace AgentePHP\Chat\History;

use AgentePHP\Chat\Enums\MessageRole;
use AgentePHP\Chat\Messages\AssistantMessage;
use AgentePHP\Chat\Messages\Message;
use AgentePHP\Chat\Messages\SystemMessage;
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
            MessageRole::Assistant => new AssistantMessage($content, $this->usageFrom($data)),
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
     * @return list<string>
     */
    private function knownRoles(): array
    {
        return array_map(static fn (MessageRole $role): string => $role->value, MessageRole::cases());
    }
}
