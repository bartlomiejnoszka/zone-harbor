<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Configuration;
use OvhDns\Operation\WriteExecutor;
use OvhDns\Dns\OvhDnsClient;
use OvhDns\Dns\DnsService;

final class ApplicationFactory
{
    public static function create(?OvhDnsClient $client = null, ?string $operationDirectory = null): DnsApplication
    {
        $application = new DnsApplication('ZoneHarbor', \OvhDns\Version::NUMBER);
        $service = new DnsService(static fn (): OvhDnsClient => $client ?? new OvhDnsClient(Configuration::createApi()));
        $provider = static fn (): DnsService => $service;
        foreach (['zone:list', 'zone:export', 'zone:refresh', 'record:list', 'record:show', 'record:add', 'record:update', 'record:delete'] as $name) {
            $application->addCommand(new DnsCommand($name, $provider, new WriteExecutor($service, $operationDirectory ?? Configuration::operationDirectory(...))));
        }
        foreach (['domain:check', 'certificate:check'] as $name) $application->addCommand(new HealthCommand($name, \OvhDns\Infrastructure\HealthFactory::create(...)));
        $application->addCommand(new TuiCommand());
        $application->addCommand(new SetupCommand());
        $application->addCommand(new InstallCommand());
        $application->addCommand(new DoctorCommand());
        $application->addCommand(new ConfigValidateCommand());
        $application->addCommand(new McpConfigCommand());
        $application->addCommand(new McpServeCommand());

        return $application;
    }
}
