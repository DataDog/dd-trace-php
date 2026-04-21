<?php

namespace App\Message;

final class LuckyNumberNotification
{
    public function __construct(public int $content) { }
}
