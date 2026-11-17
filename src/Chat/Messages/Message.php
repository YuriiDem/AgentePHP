<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Messages;

use AgentePHP\Chat\Enums\MessageRole;

/**
 * One turn in a conversation.
 *
 * Immutable: a message already handed to a provider, stored in history, or read by a
 * listener must never change underneath them.
 *
 * An abstract class rather than an interface, because there is shared state to hold - the
 * content - and a concrete subclass exists only to fix the role. `content()` is a method
 * rather than a public property so that a future multimodal message can assemble its text
 * from content parts without changing a single call site.
 */
abstract class Message
{
    public function __construct(private readonly string $content)
    {
    }

    abstract public function role(): MessageRole;

    public function content(): string
    {
        return $this->content;
    }
}
