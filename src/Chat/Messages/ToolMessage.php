<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Messages;

use AgentePHP\Chat\Enums\MessageRole;

/**
 * What a tool answered, as a turn in the conversation.
 *
 * It carries the id of the call it belongs to because the vendors need that pairing, and
 * the tool's name because a stored conversation is far easier to read with it - and
 * because Ollama wants the name rather than the id.
 */
final class ToolMessage extends Message
{
    public function __construct(
        string $content,
        public readonly string $toolCallId,
        public readonly string $toolName,
    ) {
        parent::__construct($content);
    }

    public function role(): MessageRole
    {
        return MessageRole::Tool;
    }
}
