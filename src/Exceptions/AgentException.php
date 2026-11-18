<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

/** An agent was asked to work before it had what it needs. */
final class AgentException extends AgenteException
{
    public static function noProvider(string $agent): self
    {
        return new self(sprintf(
            '%s has no model to talk to. Either pass one for this instance: '
            . '$agent->withProvider(OpenAIProvider::fromEnvironment(model: \'gpt-4o-mini\')), '
            . 'or declare one by overriding provider() in your agent class.',
            $agent,
        ));
    }
}
