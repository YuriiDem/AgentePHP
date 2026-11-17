<?php

declare(strict_types=1);

namespace AgentePHP\Chat\Messages;

use InvalidArgumentException;

/**
 * How many tokens one exchange cost.
 *
 * A value object: it has no identity, two instances with the same counts are the same thing,
 * and it never changes after construction. Vendors name these fields differently
 * (prompt/completion, input/output); each provider translates into this one shape.
 */
final readonly class Usage
{
    public function __construct(
        public int $prompt = 0,
        public int $completion = 0,
    ) {
        if ($prompt < 0) {
            throw new InvalidArgumentException(sprintf(
                'A prompt token count cannot be negative, got %d. '
                . 'If a provider reported no usage, construct Usage with no arguments instead.',
                $prompt,
            ));
        }

        if ($completion < 0) {
            throw new InvalidArgumentException(sprintf(
                'A completion token count cannot be negative, got %d. '
                . 'If a provider reported no usage, construct Usage with no arguments instead.',
                $completion,
            ));
        }
    }

    public function total(): int
    {
        return $this->prompt + $this->completion;
    }

    /**
     * The sum of two counts, as a new value. Needed once one request costs several round
     * trips - a tool-call loop, or a retry - and the caller wants the bill for all of them.
     */
    public function plus(self $other): self
    {
        return new self(
            prompt: $this->prompt + $other->prompt,
            completion: $this->completion + $other->completion,
        );
    }
}
