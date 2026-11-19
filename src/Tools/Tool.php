<?php

declare(strict_types=1);

namespace AgentePHP\Tools;

use AgentePHP\Exceptions\ToolException;
use AgentePHP\Tools\Attributes\Param;
use AgentePHP\Tools\Enums\ToolPropertyType;
use Closure;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;

/**
 * One thing the model may ask to have done: a name, a description, a signature, and the
 * code behind it.
 *
 * The Command pattern - an action packaged as an object - and the leaf of the Composite
 * that ToolInterface describes. Its schema is derived from the PHP signature rather than
 * written by hand, so a parameter renamed in code cannot keep its old name in the prompt.
 */
final class Tool implements ToolInterface
{
    /** What every vendor accepts as a function name. */
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @param list<ToolProperty> $parameters
     */
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        private readonly array $parameters,
        private readonly Closure $callback,
    ) {
    }

    /**
     * A tool from any callable - for a one-off that does not deserve a class.
     */
    public static function make(string $name, string $description, callable $callback): self
    {
        $closure = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return self::describing($name, $description, new ReflectionFunction($closure), $closure);
    }

    /**
     * A tool from a method of an object, which is how a Toolkit builds its own.
     *
     * The closure is bound to the owner, so the tool runs as a method of its toolkit and
     * can use whatever collaborators that object was given.
     */
    public static function fromMethod(object $owner, ReflectionMethod $method, string $name, string $description): self
    {
        return self::describing($name, $description, $method, $method->getClosure($owner));
    }

    public function tools(): array
    {
        return [$this];
    }

    /**
     * The JSON Schema for this tool's arguments, in the shape every vendor expects.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $parameter) {
            $properties[$parameter->name] = $parameter->toJsonSchema();

            if ($parameter->required) {
                $required[] = $parameter->name;
            }
        }

        $schema = [
            'type' => 'object',

            // An empty PHP array encodes as [], and a vendor expects {} here, so a tool
            // with no arguments has to say so with an object.
            'properties' => $properties === [] ? new stdClass() : $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Run the tool with the arguments the model chose.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(array $arguments): string
    {
        $named = [];

        foreach ($this->parameters as $parameter) {
            if (!array_key_exists($parameter->name, $arguments)) {
                if ($parameter->required) {
                    throw ToolException::argumentIsMissing($this->name, $parameter->name);
                }

                // Left out: PHP applies the default the signature declares.
                continue;
            }

            $named[$parameter->name] = $this->convert($parameter, $arguments[$parameter->name]);
        }

        // Spread by name, never by position: an omitted optional argument would otherwise
        // shift every later one into the wrong parameter. Arguments the signature does not
        // mention are dropped here - the schema already told the model what exists, and a
        // hallucinated extra key is noise rather than a reason to fail the run.
        return $this->render(($this->callback)(...$named));
    }

    private static function describing(
        string $name,
        string $description,
        ReflectionFunctionAbstract $signature,
        Closure $callback,
    ): self {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw ToolException::nameIsNotUsable($name);
        }

        if (trim($description) === '') {
            throw ToolException::descriptionIsMissing($name);
        }

        self::guardReturnType($name, $signature);

        return new self($name, $description, self::parametersOf($name, $signature), $callback);
    }

    /**
     * @return list<ToolProperty>
     */
    private static function parametersOf(string $tool, ReflectionFunctionAbstract $signature): array
    {
        $parameters = [];

        foreach ($signature->getParameters() as $parameter) {
            $parameters[] = new ToolProperty(
                name: $parameter->getName(),
                type: self::typeOf($tool, $parameter),
                description: self::describedBy($parameter),
                // Nullable counts as optional: the model may leave it out, and PHP will
                // pass the default or null.
                required: !$parameter->isDefaultValueAvailable() && !$parameter->allowsNull(),
            );
        }

        return $parameters;
    }

    private static function typeOf(string $tool, ReflectionParameter $parameter): ToolPropertyType
    {
        $declared = $parameter->getType();
        $type = $declared instanceof ReflectionNamedType
            ? ToolPropertyType::fromPhpType($declared->getName())
            : null;

        if ($type === null) {
            throw ToolException::parameterTypeIsNotSupported(
                $tool,
                $parameter->getName(),
                $declared === null ? '' : (string) $declared,
                ToolPropertyType::supportedPhpTypes(),
            );
        }

        return $type;
    }

    private static function describedBy(ReflectionParameter $parameter): string
    {
        foreach ($parameter->getAttributes(Param::class) as $attribute) {
            return $attribute->newInstance()->description;
        }

        return '';
    }

    private static function guardReturnType(string $tool, ReflectionFunctionAbstract $signature): void
    {
        $declared = $signature->getReturnType();

        if (!$declared instanceof ReflectionNamedType || ToolPropertyType::fromPhpType($declared->getName()) === null) {
            throw ToolException::returnTypeIsNotSupported(
                $tool,
                $declared === null ? '' : (string) $declared,
            );
        }
    }

    private function convert(ToolProperty $parameter, mixed $value): string|int|float|bool
    {
        return match ($parameter->type) {
            ToolPropertyType::Text => $this->asText($parameter, $value),
            ToolPropertyType::Integer => $this->asInteger($parameter, $value),
            ToolPropertyType::Number => $this->asNumber($parameter, $value),
            ToolPropertyType::Boolean => $this->asBoolean($parameter, $value),
        };
    }

    private function asText(ToolProperty $parameter, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw ToolException::argumentIsNotConvertible($this->name, $parameter->name, 'a string', $value);
    }

    /**
     * JSON routinely carries a number as a string, so "11482" is accepted for an int:
     * nothing is lost in the conversion. "11.5" and "eleven thousand" are not, because
     * one would silently lose precision and the other has no integer meaning at all.
     */
    private function asInteger(ToolProperty $parameter, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && $value === floor($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw ToolException::argumentIsNotConvertible($this->name, $parameter->name, 'an integer', $value);
    }

    private function asNumber(ToolProperty $parameter, mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        throw ToolException::argumentIsNotConvertible($this->name, $parameter->name, 'a number', $value);
    }

    private function asBoolean(ToolProperty $parameter, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));

            if ($normalised === 'true') {
                return true;
            }

            if ($normalised === 'false') {
                return false;
            }
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        throw ToolException::argumentIsNotConvertible($this->name, $parameter->name, 'true or false', $value);
    }

    /** What goes back to the model is text, so a scalar result is rendered as one. */
    private function render(mixed $result): string
    {
        if (is_bool($result)) {
            return $result ? 'true' : 'false';
        }

        if (is_string($result) || is_int($result) || is_float($result)) {
            return (string) $result;
        }

        throw ToolException::returnTypeIsNotSupported($this->name, get_debug_type($result));
    }
}
