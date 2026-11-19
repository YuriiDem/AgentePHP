<?php

declare(strict_types=1);

namespace AgentePHP\Tools\Toolkits;

use AgentePHP\Exceptions\ToolException;
use AgentePHP\Tools\Attributes\AsTool;
use AgentePHP\Tools\Attributes\Param;
use AgentePHP\Tools\Toolkit;

/**
 * Arithmetic a model should not be doing in its head.
 *
 * A language model predicts text, so it is unreliable at sums the moment the numbers stop
 * being familiar. Handing it a calculator is cheaper and more honest than hoping, and this
 * toolkit is also the shortest demonstration of what a toolkit is: four methods, four
 * attributes, no registration anywhere.
 */
final class Calculator extends Toolkit
{
    #[AsTool('Add two numbers and return the sum.')]
    public function add(
        #[Param('The first number.')]
        float $a,
        #[Param('The second number.')]
        float $b,
    ): float {
        return $a + $b;
    }

    #[AsTool('Subtract the second number from the first.')]
    public function subtract(
        #[Param('The number to subtract from.')]
        float $a,
        #[Param('The number to subtract.')]
        float $b,
    ): float {
        return $a - $b;
    }

    #[AsTool('Multiply two numbers.')]
    public function multiply(
        #[Param('The first factor.')]
        float $a,
        #[Param('The second factor.')]
        float $b,
    ): float {
        return $a * $b;
    }

    #[AsTool('Divide the first number by the second.')]
    public function divide(
        #[Param('The number to divide.')]
        float $a,
        #[Param('The number to divide by; must not be zero.')]
        float $b,
    ): float {
        // Reported rather than thrown as a DivisionByZeroError: the model picked these
        // numbers, so this is bad input arriving from outside, not a bug in the code.
        if ($b === 0.0) {
            throw ToolException::failed('divide', 'dividing by zero has no answer to give.');
        }

        return $a / $b;
    }
}
