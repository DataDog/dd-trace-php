<?php

namespace App\MessageHandler;

use App\Message\LuckyNumberNotification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class LuckyNumberNotificationHandler
{
    public function __invoke(LuckyNumberNotification $message)
    {
        if ($message->content > 100 || $message->content < 0) {
            throw new UnrecoverableMessageHandlingException("Number is out of bounds");
        }

        echo "Received number: {$message->content}\n";
    }
}
