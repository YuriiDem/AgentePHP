<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Messages;

use AgentePHP\Chat\Enums\MessageRole;

/** What the person using the agent said. */
final class UserMessage extends Message
{
    public function role(): MessageRole
    {
        return MessageRole::User;
    }
}
