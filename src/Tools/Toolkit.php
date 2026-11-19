<?php

declare(strict_types=1);

namespace AgentePHP\Tools;

use AgentePHP\Tools\Attributes\AsTool;
use ReflectionClass;
use ReflectionMethod;

/**
 * A set of related tools, written as ordinary methods of one class.
 *
 * Extend it, mark the methods the model may call with #[AsTool], and give the class
 * whatever collaborators those methods need - a repository, an HTTP client of your own, a
 * clock. The tools run as methods of this object, so they can use them.
 *
 * The composite half of ToolInterface: a toolkit answers with everything it exposes, and
 * an agent handed one treats it exactly as it treats a single tool.
 */
abstract class Toolkit implements ToolInterface
{
    public function tools(): array
    {
        $tools = [];

        // Every declared method is examined, whatever its visibility, so that the attribute
        // really is the only thing that decides. Marking a private method and silently
        // getting nothing would be worse than either allowing or refusing it.
        foreach ((new ReflectionClass($this))->getMethods() as $method) {
            $attribute = $this->asToolAttribute($method);

            if ($attribute === null) {
                continue;
            }

            $tools[] = Tool::fromMethod(
                owner: $this,
                method: $method,
                name: $attribute->name ?? $method->getName(),
                description: $attribute->description,
            );
        }

        return $tools;
    }

    private function asToolAttribute(ReflectionMethod $method): ?AsTool
    {
        foreach ($method->getAttributes(AsTool::class) as $attribute) {
            return $attribute->newInstance();
        }

        return null;
    }
}
