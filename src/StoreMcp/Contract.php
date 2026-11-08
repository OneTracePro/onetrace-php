<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * Store MCP contract of OneTrace (docs/api/store-mcp.md on the platform): tool names, access marks and input
 * schemas. Version 2 is version 1 and the order tools for AI shopping assistants (store_quote_order,
 * store_create_order, guest customers). The platform uses its own descriptions; the ones here are for other MCP
 * clients.
 */
final class Contract
{
    /** The latest contract version; a server announces it only with both order tools (Server::version()). */
    public const VERSION = 2;
    public const PROTOCOL = '2025-06-18';

    /** Order tools of version 2: a server with both of them announces version 2. */
    public const ORDER_TOOLS = ['store_quote_order', 'store_create_order'];

    /** Customer tools a guest token (version 2: a confirmed email or phone without an account) may call. */
    public const GUEST_TOOLS = ['store_get_customer', 'store_create_order'];

    /**
     * @return array<string, array{read_only: bool, auth: string, description: string, input: array<string, mixed>, required: list<string>}>
     */
    public static function tools(): array
    {
        $order = ['type' => 'string', 'description' => 'Order number as the customer knows it'];
        $items = ['type' => 'array', 'minItems' => 1, 'maxItems' => OrderInput::MAX_LINES, 'items' => ['type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => OrderInput::MAX_QUANTITY]], 'required' => ['product_id', 'quantity']]];
        $shipping = ['type' => 'object', 'properties' => array_fill_keys(OrderInput::SHIPPING_FIELDS, ['type' => 'string'])];

        return [
            'store_start_verification' => ['read_only' => true, 'auth' => 'none', 'description' => 'Send a one-time code to the customer’s email or phone.', 'input' => ['contact' => ['type' => 'string'], 'order' => $order, 'purpose' => ['type' => 'string', 'enum' => ['order'], 'description' => 'order — a code for a guest order, also to an address without an account']], 'required' => ['contact']],
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
            'store_quote_order' => ['read_only' => true, 'auth' => 'none', 'description' => 'Price an order before placing it: items, delivery methods for the address, total and payment methods.', 'input' => ['items' => $items, 'shipping' => $shipping, 'shipping_method' => ['type' => 'string']], 'required' => ['items']],
            'store_create_order' => ['read_only' => false, 'auth' => 'customer', 'description' => 'Place an order for the confirmed customer or guest; the answer has the order number and the payment link.', 'input' => ['items' => $items, 'shipping' => $shipping, 'shipping_method' => ['type' => 'string'], 'payment_method' => ['type' => 'string'], 'comment' => ['type' => 'string']], 'required' => ['items', 'shipping', 'shipping_method']],
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
