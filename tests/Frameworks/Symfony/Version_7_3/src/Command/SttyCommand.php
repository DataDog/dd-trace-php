<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

#[AsCommand(
    name: 'app:stty'
)]
class SttyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $terminal = new Terminal();
        $terminal->hasSttyAvailable();

        return Command::SUCCESS;
    }
}