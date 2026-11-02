<?php

declare(strict_types=1);

// Isolated interactive demo/smoke fixture: no OVH configuration is loaded.
require dirname(__DIR__, 2).'/vendor/autoload.php';

if (!isset($argv[1]) || !is_dir($argv[1])) {
    fwrite(STDERR, "Pass an existing temporary directory for the simulated DNS state.\n");
    exit(2);
}

use OvhDns\Tui\Application;
use OvhDns\Tui\ProcessGateway;
use OvhDns\Tui\State;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Style\VerticalAlign;
use Symfony\Component\Tui\Tui;

$state = new State();
$state->label = 'ZoneHarbor · SIMULATED DEMO';
$tui = new Tui(new StyleSheet([':root' => new Style(verticalAlign: VerticalAlign::Top)]));
$gateway = new ProcessGateway([PHP_BINARY, __DIR__.'/tui-worker.php', $argv[1]]);
$ui = new Application($tui, $gateway, $state);
fwrite(STDOUT, "\e[?1049h\e[H");
try { $ui->run('demo.example.test'); }
finally { $tui->stop(); fwrite(STDOUT, "\e[?1049l"); }
