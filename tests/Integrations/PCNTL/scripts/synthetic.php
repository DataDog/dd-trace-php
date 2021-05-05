<?php

$forkPid = pcntl_fork();

if ($forkPid > 0) {
    pcntl_wait($childStatus);
}
