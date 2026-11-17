<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

/**
 * The provider asked us to slow down (HTTP 429).
 *
 * A subclass of ProviderException rather than a sibling: code that does not care catches
 * ProviderException and is unaffected, while code that can queue the work and try later
 * catches this one specifically. That is the whole reason it exists as its own type.
 */
final class RateLimited extends ProviderException
{
    public static function forProvider(string $provider, string $body): self
    {
        return new self(sprintf(
            '%s rate-limited this request (HTTP 429): %s '
            . 'Retry the call later - ideally from a queue rather than in a loop.',
            $provider,
            self::readable($body),
        ));
    }
}
