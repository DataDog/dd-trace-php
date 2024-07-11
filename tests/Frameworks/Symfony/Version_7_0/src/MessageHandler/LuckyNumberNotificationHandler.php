<?php

namespace App\MessageHandler;

use App\Message\LuckyNumberNotification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class LuckyNumberNotificationHandler
{
    public function __invoke(LuckyNumberNotification $message)
    {
        echo 'Received number: ' . $message->content . "\n";
    }
}
