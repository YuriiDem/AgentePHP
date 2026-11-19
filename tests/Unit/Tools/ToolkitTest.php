<?php

declare(strict_types=1);

namespace AgentePHP\Tests\Unit\Tools;

use AgentePHP\Exceptions\ToolException;
use AgentePHP\Tools\Attributes\AsTool;
use AgentePHP\Tools\Attributes\Param;
use AgentePHP\Tools\Tool;
use AgentePHP\Tools\ToolInterface;
use AgentePHP\Tools\Toolkit;
use PHPUnit\Framework\TestCase;

final class ToolkitTest extends TestCase
{
    public function test_it_offers_one_tool_per_attributed_method(): void
    {
        $names = array_map(
            static fn (Tool $tool): string => $tool->name,
            (new OrderToolkit())->tools(),
        );

        sort($names);

        self::assertSame(['cancel_order', 'orderStatus'], $names);
    }

    /**
     * The attribute is the boundary of what the model can reach. A public method without
     * it is ordinary code, not an offer - which is what makes that boundary greppable.
     */
    public function test_a_public_method_without_the_attribute_is_not_a_tool(): void
    {
        $names = array_map(static fn (Tool $tool): string => $tool->name, (new OrderToolkit())->tools());

        self::assertNotContains('internalHelper', $names);
    }

    public function test_the_description_comes_from_the_attribute(): void
    {
        $tool = $this->toolNamed('orderStatus');

        self::assertSame('Look up the delivery status of a single order.', $tool->description);
    }

    public function test_a_tool_can_be_named_differently_from_its_method(): void
    {
        $tool = $this->toolNamed('cancel_order');

        self::assertSame('cancel_order', $tool->name);
    }

    public function test_the_schema_is_derived_from_the_method_signature(): void
    {
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'orderNumber' => [
                    'type' => 'integer',
                    'description' => 'Order number as shown on the customer receipt.',
                ],
            ],
            'required' => ['orderNumber'],
        ], $this->toolNamed('orderStatus')->jsonSchema());
    }

    /** The tool runs as a method of its toolkit, so it can use the toolkit's collaborators. */
    public function test_calling_a_tool_runs_the_method_on_its_own_object(): void
    {
        $toolkit = new OrderToolkit(['11482' => 'in transit']);

        $answer = $this->toolNamed('orderStatus', $toolkit)->call(['orderNumber' => 11482]);

        self::assertSame('Order 11482: in transit.', $answer);
    }

    public function test_a_toolkit_satisfies_the_same_contract_as_a_single_tool(): void
    {
        $single = Tool::make('ping', 'Replies.', static fn (): string => 'pong');
        $toolkit = new OrderToolkit();

        foreach ([$single, $toolkit] as $offer) {
            self::assertInstanceOf(ToolInterface::class, $offer);

            foreach ($offer->tools() as $tool) {
                self::assertInstanceOf(Tool::class, $tool, $offer::class);
            }
        }
    }

    public function test_it_refuses_a_tool_whose_attribute_says_nothing(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('description');

        (new UndescribedToolkit())->tools();
    }

    private function toolNamed(string $name, ?Toolkit $toolkit = null): Tool
    {
        foreach (($toolkit ?? new OrderToolkit())->tools() as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        self::fail("The toolkit offers no tool named {$name}.");
    }
}

final class OrderToolkit extends Toolkit
{
    /** @param array<int, string> $statuses keyed by order number - PHP turns '11482' into 11482 */
    public function __construct(private readonly array $statuses = [])
    {
    }

    #[AsTool('Look up the delivery status of a single order.')]
    public function orderStatus(
        #[Param('Order number as shown on the customer receipt.')]
        int $orderNumber,
    ): string {
        $status = $this->statuses[$orderNumber] ?? null;

        return $status === null
            ? "No order {$orderNumber} exists."
            : "Order {$orderNumber}: {$status}.";
    }

    #[AsTool('Cancel an order that has not shipped yet.', name: 'cancel_order')]
    public function cancelOrder(
        #[Param('Order number to cancel.')]
        int $orderNumber,
    ): string {
        return "Order {$orderNumber} cancelled.";
    }

    /** Ordinary code the model must not see. */
    public function internalHelper(): string
    {
        return 'not a tool';
    }
}

final class UndescribedToolkit extends Toolkit
{
    #[AsTool('')]
    public function mystery(): string
    {
        return 'what am I for?';
    }
}
