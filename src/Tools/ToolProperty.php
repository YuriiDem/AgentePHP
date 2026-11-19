<?php

declare(strict_types=1);

namespace AgentePHP\Tools;

use AgentePHP\Tools\Enums\ToolPropertyType;

/**
 * One argument of a tool: what it is called, what it holds, and whether it may be left out.
 *
 * A value object derived from a PHP parameter, never written by hand - which is what keeps
 * a signature and the schema sent to the model from drifting apart.
 */
final readonly class ToolProperty
{
    public function __construct(
        public string $name,
        public ToolPropertyType $type,
        public string $description = '',
        public bool $required = true,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toJsonSchema(): array
    {
        $schema = ['type' => $this->type->value];

        // Omitted rather than sent empty: a blank description is noise in the prompt the
        // model is charged for.
        if ($this->description !== '') {
            $schema['description'] = $this->description;
        }

        return $schema;
    }
}
