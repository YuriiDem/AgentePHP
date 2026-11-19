<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Messages;

use AgentePHP\Chat\Enums\MessageRole;

/**
 * What the model answered: words, or a request to have tools run, or both.
 *
 * Usage defaults to zero so that a test double, or a message rebuilt from stored history,
 * can be constructed without inventing token counts.
 */
final class AssistantMessage extends Message
{
    /**
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(
        string $content,
        private readonly Usage $usage = new Usage(),
        private readonly array $toolCalls = [],
    ) {
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

    /**
     * The tools the model wants run before it can answer.
     *
     * An answer asking for a tool usually has no text of its own, and that empty content
     * is not a failure - the request is the content.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
