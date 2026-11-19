<?php

declare(strict_types=1);

namespace AgentePHP\Exceptions;

use Throwable;

/** A tool could not be described to the model, or could not be run with what the model sent. */
final class ToolException extends AgenteException
{
    public static function nameIsNotUsable(string $name): self
    {
        return new self(sprintf(
            'A tool name may contain only letters, digits, "-" and "_", and must be 1 to 64 '
            . 'characters long; got %s. Vendors reject anything else, so this would fail at '
            . 'the API rather than here.',
            var_export($name, true),
        ));
    }

    public static function descriptionIsMissing(string $tool): self
    {
        return new self(sprintf(
            'Tool "%s" has no description. The description is the only thing the model reads '
            . 'when deciding whether this is the right tool, so a tool without one will be '
            . 'called at random or not at all.',
            $tool,
        ));
    }

    /**
     * @param list<string> $supported
     */
    public static function parameterTypeIsNotSupported(
        string $tool,
        string $parameter,
        string $declared,
        array $supported,
    ): self {
        return new self(sprintf(
            'Tool "%s" cannot describe its parameter $%s to a model: %s. A tool parameter must '
            . 'be one of %s. For anything richer, accept a string and decode it inside the '
            . 'tool - and say in #[Param] what format you expect.',
            $tool,
            $parameter,
            $declared === '' ? 'it has no type declaration' : sprintf('its type is %s', $declared),
            implode(', ', $supported),
        ));
    }

    public static function returnTypeIsNotSupported(string $tool, string $declared): self
    {
        return new self(sprintf(
            'Tool "%s" returns %s, so there would be nothing for the model to read. Return a '
            . 'string, int, float or bool - a short sentence stating the outcome is usually '
            . 'the most useful thing a tool can answer.',
            $tool,
            $declared === '' ? 'no declared type' : $declared,
        ));
    }

    public static function argumentIsMissing(string $tool, string $parameter): self
    {
        return new self(sprintf(
            'The model called "%s" without the required argument "%s". If the model keeps '
            . 'leaving it out, say in #[Param] what it is for and where to find it.',
            $tool,
            $parameter,
        ));
    }

    public static function argumentIsNotConvertible(
        string $tool,
        string $parameter,
        string $expected,
        mixed $given,
    ): self {
        return new self(sprintf(
            'The model called "%s" with %s for "%s", which is not %s and cannot be converted '
            . 'to one. Make the #[Param] description state the expected format, or accept a '
            . 'string and parse it inside the tool.',
            $tool,
            self::describe($given),
            $parameter,
            $expected,
        ));
    }

    public static function failed(string $tool, string $why, ?Throwable $previous = null): self
    {
        return new self(sprintf('Tool "%s" could not answer: %s', $tool, $why), previous: $previous);
    }

    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return sprintf('the string %s', var_export($value, true));
        }

        if (is_scalar($value)) {
            return sprintf('%s (%s)', var_export($value, true), get_debug_type($value));
        }

        return sprintf('a value of type %s', get_debug_type($value));
    }
}
