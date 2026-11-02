<?php

declare(strict_types=1);

namespace OvhDns\Infrastructure;

use GuzzleHttp\Client;
use OvhDns\Configuration;
use OvhDns\Dns\OvhDnsClient;
use OvhDns\Health\{Budget, CertificateChecker, DomainChecker, HealthConfig, NetDnsResolver, StreamTlsProbe, SystemClock};
use OvhDns\Provider\OvhProvider;

final class HealthFactory
{
    public static function create(): DomainChecker
    {
        $clock = new SystemClock(); $config = Configuration::user()?->health() ?? HealthConfig::load(dirname(__DIR__, 2).'/zone-harbor.local.yaml');
        $configured = Configuration::hasCredentials();
        $provider = $configured ? static fn (Budget $budget): OvhProvider => new OvhProvider(static fn (): OvhDnsClient => new OvhDnsClient(
            Configuration::createApi(new Client(['timeout' => min(2, $budget->timeout(2)), 'connect_timeout' => 1])),
            static fn () => $budget->timeout(2))) : null;
        return new DomainChecker(new NetDnsResolver(), new CertificateChecker(new StreamTlsProbe(), $clock, $config), $clock, $config, $provider);
    }
}
