<?php

$pid = pcntl_fork();
if ($pid == -1) {
    return;
}
if ($pid) {
    // parent
    usleep(100000);

    $tracer = DDTrace\GlobalTracer::get();
    $scope = $tracer->startActiveSpan("parent");
    $scope->close();
} else {
    // child
    $tracer = DDTrace\GlobalTracer::get();
    $scope = $tracer->startActiveSpan("child");
    $scope->close();

    posix_kill(posix_getpid(), SIGTERM);
}
