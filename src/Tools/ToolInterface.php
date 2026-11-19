<?php

declare(strict_types=1);

namespace AgentePHP\Tools;

/**
 * Something that offers tools to an agent.
 *
 * The Composite pattern, and the reason an agent needs no idea whether it was handed one
 * tool or forty: a single Tool answers with itself, a Toolkit answers with everything it
 * exposes, and the agent treats both the same way.
 */
interface ToolInterface
{
    /**
     * Every individual tool reachable through this offer.
     *
     * @return list<Tool>
     */
    public function tools(): array;
}
