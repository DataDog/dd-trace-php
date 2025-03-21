<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class SttyCommand extends Command
{
    protected static $defaultName = 'app:stty';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $terminal = new Terminal();
        $terminal->hasSttyAvailable();

        return 0; // success
    }
}