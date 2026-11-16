<?php

namespace App\MessageHandler;

use App\Message\LuckyNumberNotification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class LuckyNumberNotificationHandler
{
    public function __invoke(LuckyNumberNotification $message)
    {
        if ($message->content > 100 || $message->content < 0) {
            throw new \OutOfBoundsException("Number is out of bounds");
        }

        echo "Received number: {$message->content}\n";
    }
}
