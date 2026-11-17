<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Messages;

use AgentePHP\Chat\Enums\MessageRole;

/**
 * What the model answered, and what that answer cost.
 *
 * Usage defaults to zero so that a test double, or a message rebuilt from stored history,
 * can be constructed without inventing token counts.
 */
final class AssistantMessage extends Message
{
    public function __construct(string $content, private readonly Usage $usage = new Usage())
    {
        parent::__construct($content);
    }

    public function role(): MessageRole
    {
        return MessageRole::Assistant;
    }

    public function usage(): Usage
    {
        return $this->usage;
    }
}
