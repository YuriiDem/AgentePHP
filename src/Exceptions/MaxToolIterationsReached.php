<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

/**
 * The model kept asking for tools and never produced an answer.
 *
 * A cap is not optional. A model can get stuck calling the same tool with the same
 * arguments, and without a limit that is an unbounded number of paid API calls in a loop
 * that never returns. The request fails; the process does not.
 */
final class MaxToolIterationsReached extends AgenteException
{
    /**
     * @param list<string> $calledTools in the order they were called
     */
    public static function after(int $iterations, array $calledTools): self
    {
        return new self(sprintf(
            'The model asked for tools %d times without answering, which is the limit. '
            . 'It called: %s. Either the tools keep returning something the model cannot use '
            . '- check what they answer - or the work genuinely needs more steps, in which '
            . 'case raise the limit by overriding maxToolIterations() on your agent.',
            $iterations,
            $calledTools === [] ? '(nothing)' : implode(', ', $calledTools),
        ));
    }
}
