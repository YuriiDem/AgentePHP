<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

/**
 * A provider was asked to configure itself from the environment, and the key was not there.
 *
 * Reading configuration from the environment is only acceptable if failing to find it says
 * so plainly; this message is the price of that convenience.
 */
final class MissingApiKey extends AgenteException
{
    public static function inEnvironment(string $variable, string $provider, string $model): self
    {
        return new self(sprintf(
            '%s::fromEnvironment() found no %s in the environment. '
            . 'Export it (export %s=...), or pass the key explicitly: '
            . 'new %s(apiKey: $key, model: %s). '
            . 'Note that PHP does not read .env files on its own.',
            $provider,
            $variable,
            $variable,
            $provider,
            var_export($model, true),
        ));
    }
}
