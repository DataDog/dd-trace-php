<?php

namespace App\MessageHandler;

use App\Message\LuckyNumberNotification;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

final class LuckyNumberNotificationHandler implements MessageHandlerInterface
{
    public function __invoke(LuckyNumberNotification $message)
    {
        if ($message->content > 100 || $message->content < 0) {
            throw new UnrecoverableMessageHandlingException("Number is out of bounds");
        }

        echo "Received number: {$message->content}\n";
    }
}
