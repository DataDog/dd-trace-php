<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ThrowCommand extends Command
{
    protected static $defaultName = 'app:throw';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new \Exception('This is an exception');
    }
}