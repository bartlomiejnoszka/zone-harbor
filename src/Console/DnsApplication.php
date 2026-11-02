<?php

declare(strict_types=1);

namespace OvhDns\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class DnsApplication extends Application
{
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ExceptionInterface $exception) {
            self::error($output, $exception->getMessage());

            return 2;
        } catch (Throwable) {
            self::error($output, 'Unexpected application error. Details were hidden to protect configuration.');

            return 1;
        }
    }

    public static function error(OutputInterface $output, string $message): void
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stderr->writeln('<error>'.OutputFormatter::escape($message).'</error>');
    }
}
