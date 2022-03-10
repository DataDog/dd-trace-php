<?php

/** @generate-class-entries */

namespace DDTrace;

class HookData {
    public array $args;
    public mixed $returned;
    public ?\Throwable $exception;
    public mixed $data;
}

function install_hook(string|\Closure|\Generator $target, ?\Closure $begin, ?\Closure $end): int {}