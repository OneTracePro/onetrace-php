<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * Store MCP contract v1 of OneTrace (docs/api/store-mcp.md on the platform): tool names, access marks and input
 * schemas. The platform uses its own descriptions; the ones here are for other MCP clients.
 */
final class Contract
{
    public const VERSION = 1;
    public const PROTOCOL = '2025-06-18';

    /**
     * @return array<string, array{read_only: bool, auth: string, description: string, input: array<string, mixed>, required: list<string>}>
     */
    public static function tools(): array
    {
        $order = ['type' => 'string', 'description' => 'Order number as the customer knows it'];

        return [
            'store_start_verification' => ['read_only' => true, 'auth' => 'none', 'description' => 'Send a one-time code to the customer’s email or phone.', 'input' => ['contact' => ['type' => 'string'], 'order' => $order], 'required' => ['contact']],
            'store_verify' => ['read_only' => true, 'auth' => 'none', 'description' => 'Check the code and issue a customer token.', 'input' => ['contact' => ['type' => 'string'], 'code' => ['type' => 'string']], 'required' => ['contact', 'code']],
            'store_get_customer' => ['read_only' => true, 'auth' => 'customer', 'description' => 'The confirmed customer.', 'input' => [], 'required' => []],
            'store_find_orders' => ['read_only' => true, 'auth' => 'customer', 'description' => 'Orders of the customer, newest first.', 'input' => ['status' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]], 'required' => []],
            'store_get_order' => ['read_only' => true, 'auth' => 'customer', 'description' => 'One order of the customer.', 'input' => ['order' => $order], 'required' => ['order']],
            'store_get_tracking' => ['read_only' => true, 'auth' => 'customer', 'description' => 'Parcel tracking of an order.', 'input' => ['order' => $order], 'required' => ['order']],
            'store_check_availability' => ['read_only' => true, 'auth' => 'none', 'description' => 'Stock and price of products.', 'input' => ['query' => ['type' => 'string'], 'sku' => ['type' => 'string'], 'product_id' => ['type' => 'string']], 'required' => []],
            'store_get_delivery_options' => ['read_only' => true, 'auth' => 'none', 'description' => 'Delivery methods, prices and terms.', 'input' => ['country' => ['type' => 'string'], 'city' => ['type' => 'string'], 'postcode' => ['type' => 'string'], 'total' => ['type' => 'number']], 'required' => []],
            'store_get_return_policy' => ['read_only' => true, 'auth' => 'none', 'description' => 'The return policy.', 'input' => [], 'required' => []],
            'store_cancel_order' => ['read_only' => false, 'auth' => 'customer', 'description' => 'Cancel an order.', 'input' => ['order' => $order, 'reason' => ['type' => 'string']], 'required' => ['order']],
            'store_create_return' => ['read_only' => false, 'auth' => 'customer', 'description' => 'Create a return request.', 'input' => ['order' => $order, 'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string'], 'quantity' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['sku', 'quantity']]], 'reason' => ['type' => 'string']], 'required' => ['order', 'items', 'reason']],
            'store_change_delivery' => ['read_only' => false, 'auth' => 'customer', 'description' => 'Change delivery address, date or time window.', 'input' => ['order' => $order, 'address' => ['type' => 'string'], 'date' => ['type' => 'string'], 'time_window' => ['type' => 'string']], 'required' => ['order']],
        ];
    }

    /**
     * A tools/list entry.
     *
     * @return array<string, mixed>
     */
    public static function definition(string $name): array
    {
        $tool = self::tools()[$name];
        $schema = ['type' => 'object', 'properties' => $tool['input'] === [] ? new \stdClass() : $tool['input']];

        if ($tool['required'] !== []) {
            $schema['required'] = $tool['required'];
        }

        return [
            'name' => $name,
            'description' => $tool['description'],
            'inputSchema' => $schema,
            'annotations' => $tool['read_only'] ? ['readOnlyHint' => true] : ['readOnlyHint' => false, 'destructiveHint' => true],
            '_meta' => ['onetrace/auth' => $tool['auth']],
        ];
    }
}
