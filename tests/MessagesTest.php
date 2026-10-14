<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Identity;
use OneTrace\Tests\Support\ClientFactory;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MessagesTest extends TestCase
{
    use ClientFactory;

    public function testSendsATemplateOrStepsWithoutEmptyOptions(): void
    {
        $transport = (new FakeTransport())->push(202, ['id' => 'm-1'])->push(202, ['id' => 'c-1']);
        $messages = $this->client($transport)->messages();

        $messages->send(Identity::email('anna@example.com'), 12, ['data' => ['order_id' => 'A-1001']], 'order-A-1001');

        self::assertSame(['identity' => ['type' => 'email', 'value' => 'anna@example.com'], 'template_id' => 12, 'data' => ['order_id' => 'A-1001']], $transport->lastJson());
        self::assertSame('order-A-1001', $transport->last()->getHeader('Idempotency-Key'));

        $steps = [
            ['integration_id' => 3, 'template_id' => 21, 'wait_for' => 'delivered', 'timeout' => ['amount' => 2, 'unit' => 'minute']],
            ['integration_id' => 4, 'template_id' => 22],
        ];
        $messages->sendCascade(Identity::phone('+4915112345678'), $steps, ['traits' => ['first_name' => 'Anna']]);

        self::assertSame(['identity' => ['type' => 'phone', 'value' => '+4915112345678'], 'steps' => $steps, 'traits' => ['first_name' => 'Anna']], $transport->lastJson());
        self::assertNotNull($transport->last()->getHeader('Idempotency-Key'));
    }
}
