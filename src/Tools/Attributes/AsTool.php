<?php

declare(strict_types=1);

namespace AgentePHP\Tools\Attributes;

use Attribute;

/**
 * Marks a method as something the model may call.
 *
 * The attribute, and not the method's visibility, is the boundary of what the model can
 * reach. That is deliberate: "every public method of a toolkit is a tool" would be a rule
 * the reader has to know, and a refactor could widen what a model can do without anyone
 * noticing. With an attribute the boundary is one grep away.
 *
 * The description is not optional - it is the only thing the model reads when deciding
 * whether this is the right tool for what the user asked.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class AsTool
{
    /**
     * @param string      $description what the tool does, written for the model
     * @param string|null $name        the name the model uses; defaults to the method name
     */
    public function __construct(
        public string $description,
        public ?string $name = null,
    ) {
    }
}
