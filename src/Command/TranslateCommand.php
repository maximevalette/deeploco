<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class TranslateCommand
 *
 * @package App\Command
 */
class TranslateCommand extends Command
{
    private $client;

    private $firms;

    protected function configure()
    {
        $this
            ->setName('app:transfer')
            ->setDescription('Transfer invoices')
            ->addOption('page', 'p', InputOption::VALUE_OPTIONAL, 'Page number', 1);
    }
}