<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Messages;

use AgentePHP\Chat\Enums\MessageRole;

/**
 * The standing instruction the model is given before the conversation starts.
 *
 * Vendors disagree on where this belongs: OpenAI takes it as the first message in the
 * conversation, Anthropic as a separate top-level field. Here it is a message like any
 * other, and each provider's mapper puts it where its API wants it.
 */
final class SystemMessage extends Message
{
    public function role(): MessageRole
    {
        return MessageRole::System;
    }
}
