<?php

declare(strict_types=1);

namespace AgentePHP\Tools\Enums;

/**
 * The JSON Schema types a tool argument may have.
 *
 * Backed by the JSON Schema spelling, because that is what goes on the wire, and limited
 * to the four scalars on purpose: a model filling in a nested object needs the schema
 * machinery that structured output will bring, and guessing at it now would produce
 * schemas no vendor validates the same way.
 */
enum ToolPropertyType: string
{
    case Text = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';

    /** The PHP type declaration this corresponds to, used in error messages. */
    public function phpType(): string
    {
        return match ($this) {
            self::Text => 'string',
            self::Integer => 'int',
            self::Number => 'float',
            self::Boolean => 'bool',
        };
    }

    public static function fromPhpType(string $phpType): ?self
    {
        return match ($phpType) {
            'string' => self::Text,
            'int' => self::Integer,
            'float' => self::Number,
            'bool' => self::Boolean,
            default => null,
        };
    }

    /** @return list<string> */
    public static function supportedPhpTypes(): array
    {
        return array_map(static fn (self $type): string => $type->phpType(), self::cases());
    }
}
