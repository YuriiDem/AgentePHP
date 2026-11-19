<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Messages;

/**
 * A request from the model to have one tool run.
 *
 * It lives next to the messages rather than in the Tools namespace because it is something
 * the model *said* - a datum of the conversation, which history stores and every provider
 * maps - while the Tools namespace is about running code. Keeping it here leaves the
 * foundational layer with no dependency on the tools layer at all.
 *
 * The id is the vendor's way of pairing a result with the request that caused it, and
 * matters as soon as a model asks for two tools in one turn. Ollama sends none, so one is
 * invented there: it only has to survive a single round trip.
 */
final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments as the model filled them in, not yet validated
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {
    }
}
