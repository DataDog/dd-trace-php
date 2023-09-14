<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Amqp\Test\Unit\Topology;

use Magento\Framework\Amqp\Topology\QueueInstaller;
use Magento\Framework\MessageQueue\Topology\Config\QueueConfigItemInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;

class QueueInstallerTest extends TestCase
{
    public function testInstall()
    {
        $model = new QueueInstaller();
        $channel = $this->createMock(AMQPChannel::class);

        $queue = $this->getMockForAbstractClass(QueueConfigItemInterface::class);
        $queue->expects($this->once())->method('getName')->willReturn('queue01');
        $queue->expects($this->once())->method('isDurable')->willReturn(true);
        $queue->expects($this->once())->method('isAutoDelete')->willReturn(false);
        $queue->expects($this->once())->method('getArguments')->willReturn(['some' => 'value']);

        $channel->expects($this->once())
            ->method('queue_declare')
            ->with('queue01', false, true, false, false, false, ['some' => ['S', 'value']], null);
        $model->install($channel, $queue);
    }
}
