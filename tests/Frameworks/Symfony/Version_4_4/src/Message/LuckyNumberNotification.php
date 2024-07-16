<?php

namespace App\Message;

final class LuckyNumberNotification
{
    public $content;

    public function __construct(int $content) {
        $this->content = $content;
    }
}
