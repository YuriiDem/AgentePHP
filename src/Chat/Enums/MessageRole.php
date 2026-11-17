<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Enums;

/**
 * Who said a thing in a conversation.
 *
 * An enum rather than string constants: an invalid role becomes impossible to construct,
 * instead of failing later inside a provider request where the cause is no longer visible.
 * The backing values are the strings every vendor API already uses.
 */
enum MessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
