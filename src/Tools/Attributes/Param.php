<?php

declare(strict_types=1);

namespace AgentePHP\Tools\Attributes;

use Attribute;

/**
 * Describes one argument of a tool, for the model that has to fill it in.
 *
 * Optional: a well-named parameter often speaks for itself, and its type already reaches
 * the model through the generated schema. Worth adding wherever the name alone would leave
 * the format ambiguous - which order number, which date format, which unit.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Param
{
    public function __construct(public string $description)
    {
    }
}
