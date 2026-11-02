<?php

declare(strict_types=1);

namespace OvhDns\Tests;

require_once __DIR__.'/Support/HealthDoubles.php';

use OvhDns\Console\HealthCommand;
use OvhDns\Health\{Budget, CertificateChecker, DnsAnswer, DnsSession, DomainChecker, HealthConfig, MailChecker, PublicAddress, Report, Status};
use OvhDns\Mcp\HealthTools;
use OvhDns\Tests\Support\{FakeClock, FakeResolver, FakeTlsProbe, MemoryProvider};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class HealthTest extends TestCase
{
    private static string $pem = '';
    private FakeClock $clock;
    private FakeTlsProbe $tls;
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'example.com'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 100);
        openssl_x509_export($certificate, self::$pem);
    }
    protected function setUp(): void
    {
        $cert = openssl_x509_parse(self::$pem); $this->clock = new FakeClock($cert['validFrom_time_t'] + 86400);
        $this->tls = new FakeTlsProbe(self::$pem);
    }
    private function checker(?FakeResolver $dns = null, ?HealthConfig $config = null, ?\Closure $provider = null): DomainChecker
    {
        $config ??= new HealthConfig();
        return new DomainChecker($dns ?? FakeResolver::healthy(), new CertificateChecker($this->tls, $this->clock, $config), $this->clock, $config, $provider);
    }
    private function checks(Report $report): array
    {
        $checks = []; foreach ($report->checks as $check) $checks[$check->id] = $check;
        return $checks;
    }
    public function testHealthyPublicAuditNeedsNoCredentialsOrProvider(): void
    {
        $report = $this->checker()->domain('EXAMPLE.COM.'); $checks = $this->checks($report);
        self::assertSame(Status::PASS, $report->status()); self::assertSame(0, $report->exitCode());
        self::assertSame(Status::PASS, $checks['dns.delegation']->status);
        self::assertSame(Status::SKIP, $checks['provider.comparison']->status);
        self::assertSame(Status::SKIP, $checks['mail.dkim']->status);
        self::assertSame(1, $this->tls->calls);
    }
    public function testAuthoritativeDisagreementAndStaleRecursiveAnswersAreDistinct(): void
    {
        $dns = FakeResolver::healthy(); $dns->set('example.com', 'A', ['93.184.216.35'], '1.1.1.4');
        $dns->set('example.com', 'A', ['93.184.216.36'], '1.1.1.1');
        $checks = $this->checks($this->checker($dns)->domain('example.com'));
        self::assertSame(Status::FAIL, $checks['dns.consistency.example.com|A']->status);
        self::assertSame(Status::WARN, $checks['dns.recursive.Cloudflare.example.com|A']->status);
    }
    public function testTimeoutIsUnknownAndNxDomainIsConfirmedFailure(): void
    {
        $dns = FakeResolver::healthy(); $dns->set('example.com', 'NS', [], status: 'UNKNOWN');
        $report = $this->checker($dns)->domain('example.com'); self::assertSame(3, $report->exitCode());
        $dns->set('example.com', 'NS', [], status: 'NXDOMAIN');
        self::assertSame(Status::FAIL, $this->checks($this->checker($dns)->domain('example.com'))['dns.existence']->status);
    }
    public function testProviderDifferenceIsNotReportedAsPublishedConfiguration(): void
    {
        $provider = new MemoryProvider();
        $checks = $this->checks($this->checker(provider: static fn () => $provider)->domain('example.com'));
        self::assertSame(Status::WARN, $checks['provider.comparison.example.com|A']->status);
        self::assertSame(0, $provider->writes);
        $checks = $this->checks($this->checker(provider: static fn () => throw new \RuntimeException('secret diagnostic'))->domain('example.com'));
        self::assertSame(Status::UNKNOWN, $checks['provider.comparison']->status);
        self::assertStringNotContainsString('secret diagnostic', json_encode($checks));
    }
    public function testRrsetsIgnoreOrderAndCountdownTtl(): void
    {
        $one = new DnsAnswer('NOERROR', [['name' => 'EXAMPLE.COM.', 'type' => 'MX', 'value' => '10 MAIL.EXAMPLE.COM.', 'ttl' => 200], ['name' => 'example.com', 'type' => 'MX', 'value' => '20 backup.example.com.', 'ttl' => 100]]);
        $two = new DnsAnswer('NOERROR', array_reverse(array_map(static fn (array $v): array => array_replace($v, ['ttl' => 1]), $one->records)));
        self::assertSame($one->values('example.com', 'MX'), $two->values('example.com', 'MX'));
    }
    public function testCnameConflictIsAConfirmedFinding(): void
    {
        $dns = FakeResolver::healthy(); $dns->set('example.com', 'CNAME', ['other.example.com']);
        self::assertSame(Status::FAIL, $this->checks($this->checker($dns)->domain('example.com'))['dns.cname.example.com']->status);
    }
    public static function certificates(): array
    {
        return [['valid', .5, Status::PASS], ['warning', .20, Status::WARN], ['critical', .05, Status::FAIL], ['expired', -.1, Status::FAIL], ['future', 1.1, Status::FAIL], ['warning-boundary', .25, Status::PASS], ['critical-boundary', .10, Status::WARN]];
    }
    #[DataProvider('certificates')]
    public function testRelativeExpiryPolicy(string $label, float $remaining, Status $expected): void
    {
        $cert = openssl_x509_parse(self::$pem); $lifetime = $cert['validTo_time_t'] - $cert['validFrom_time_t'];
        $this->clock->timestamp = $cert['validTo_time_t'] - (int) ($lifetime * $remaining);
        $checks = $this->checks($this->checker()->certificate('example.com'));
        self::assertSame($expected, $checks['tls.example.com:443.expiry']->status, $label);
    }
    public function testWrongHostnameUntrustedChainAndNetworkFailure(): void
    {
        $dns = FakeResolver::healthy(); $dns->set('wrong.example.com', 'A', ['93.184.216.34']); $this->tls->verified = false;
        $checks = $this->checks($this->checker($dns)->certificate('wrong.example.com'));
        self::assertSame(Status::FAIL, $checks['tls.wrong.example.com:443.hostname']->status);
        self::assertSame(Status::FAIL, $checks['tls.wrong.example.com:443.verification']->status);
        self::assertSame(Status::PASS, $checks['tls.wrong.example.com:443.expiry']->status);
        $this->tls->fail = true; $report = $this->checker()->certificate('example.com');
        self::assertSame(Status::UNKNOWN, $report->status()); self::assertSame(3, $report->exitCode());
        self::assertStringNotContainsString('private diagnostic detail', json_encode($report));
    }
    public static function blockedAddresses(): array
    {
        return array_map(static fn (string $ip): array => [$ip], ['127.0.0.1', '10.0.0.1', '169.254.169.254', '192.168.1.1', '172.16.0.1', '100.64.0.1', '0.0.0.0', '224.0.0.1', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '192.0.0.1', '192.88.99.1', '2001:db8::1', '2002:7f00:1::', '2001::1']);
    }
    #[DataProvider('blockedAddresses')]
    public function testPrivateTargetsBlockedBeforeTransport(string $ip): void
    {
        self::assertFalse(PublicAddress::allowed($ip));
        $dns = FakeResolver::healthy(); $dns->set('example.com', 'A', [$ip]);
        self::assertSame(Status::UNKNOWN, $this->checker($dns)->certificate('example.com')->status()); self::assertSame(0, $this->tls->calls);
    }
    public function testCertificateNameMatchingHonorsSansAndSingleLabelWildcards(): void
    {
        $cert = ['subject' => ['commonName' => 'legacy.example.com'], 'extensions' => ['subjectAltName' => 'DNS:*.example.com, DNS:example.org']];
        self::assertTrue(CertificateChecker::matches($cert, 'mail.example.com'));
        foreach (['example.com', 'a.b.example.com', 'legacy.example.net'] as $name) self::assertFalse(CertificateChecker::matches($cert, $name));
        self::assertFalse(CertificateChecker::matches(['subject' => ['commonName' => 'example.com'], 'extensions' => ['subjectAltName' => 'DNS:other.example.com']], 'example.com'));
    }
    public function testMailChecksDoNotGuessSelectorsOrSendMail(): void
    {
        $dns = FakeResolver::healthy(); $dns->set('example.com', 'MX', ['1 smtp.google.com']); $dns->set('smtp.google.com', 'A', ['142.250.1.1']);
        $dns->set('example.com', 'TXT', ['v=spf1 include:_spf.google.com ~all']); $dns->set('_spf.google.com', 'TXT', ['v=spf1 ip4:1.2.3.0/24 -all']);
        $dns->set('_dmarc.example.com', 'TXT', ['v=DMARC1; p=none;']);
        $checks = $this->checks($this->checker($dns, new HealthConfig(['example.com' => ['mail' => 'google']]))->domain('example.com'));
        self::assertSame(Status::PASS, $checks['mail.mx']->status); self::assertSame(Status::PASS, $checks['mail.spf']->status);
        self::assertSame(Status::WARN, $checks['mail.dmarc']->status); self::assertSame(Status::SKIP, $checks['mail.dkim']->status); self::assertSame(Status::SKIP, $checks['mail.delivery']->status);
    }
    public function testInvalidSpfDuplicateDmarcAndMissingConfiguredDkim(): void
    {
        $dns = FakeResolver::healthy(); $dns->set('example.com', 'TXT', ['v=spf1 -all', 'v=spf1 ~all']);
        $dns->set('_dmarc.example.com', 'TXT', ['v=DMARC1; p=reject; p=none;']);
        $checks = $this->checks($this->checker($dns, new HealthConfig(['example.com' => ['dkim_selectors' => ['google']]]))->domain('example.com'));
        foreach (['mail.spf', 'mail.dmarc', 'mail.dkim.google'] as $id) self::assertSame(Status::FAIL, $checks[$id]->status);
    }
    public function testDkimPublicKeyAndConfiguredMailEndpoint(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]); $pem = openssl_pkey_get_details($key)['key'];
        $public = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
        $dns = FakeResolver::healthy(); $dns->set('google._domainkey.example.com', 'TXT', ['v=DKIM1; k=rsa; p='.$public]);
        $dns->set('mail.example.com', 'A', ['93.184.216.34']);
        $config = new HealthConfig(['example.com' => ['dkim_selectors' => ['google'], 'endpoints' => [['host' => 'mail.example.com', 'port' => 587]]]]);
        $checks = $this->checks($this->checker($dns, $config)->domain('example.com'));
        self::assertSame(Status::PASS, $checks['mail.dkim.google']->status); self::assertArrayHasKey('tls.mail.example.com:587.expiry', $checks); self::assertSame(2, $this->tls->calls);
    }
    public function testSpfIncludeCyclesLookupBudgetAndCaseInsensitiveTerms(): void
    {
        $dns = FakeResolver::healthy();
        $dns->set('example.com', 'TXT', ['v=spf1 include:_spf.example.com -all']);
        $dns->set('_spf.example.com', 'TXT', ['v=spf1 include:example.com -all']);
        self::assertSame(Status::FAIL, $this->checks($this->checker($dns)->domain('example.com'))['mail.spf']->status);
        $dns->set('example.com', 'TXT', ['v=spf1 '.str_repeat('a ', 11).'-all']);
        self::assertSame(Status::FAIL, $this->checks($this->checker($dns)->domain('example.com'))['mail.spf']->status);
        $dns->set('example.com', 'TXT', ['V=SPF1 INCLUDE:_spf.example.net -ALL']);
        $dns->set('_spf.example.net', 'TXT', ['v=spf1 IP4:1.2.3.0/24 -all']);
        self::assertSame(Status::PASS, $this->checks($this->checker($dns)->domain('example.com'))['mail.spf']->status);
    }
    public function testNullMxCannotCoexistWithOtherMailExchangers(): void
    {
        $dns = FakeResolver::healthy(); $dns->set('example.com', 'MX', ['0 ', '10 mail.example.com']);
        self::assertSame(Status::FAIL, $this->checks($this->checker($dns)->domain('example.com'))['mail.mx']->status);
    }
    public function testInvalidHealthSettingsFailWithoutNetworkAccess(): void
    {
        foreach ([['warning' => .05, 'critical' => .1], ['domains' => ['example.com' => ['endpoints' => [['host' => 'mail.example.com', 'port' => 22]]]]]] as $args) {
            try { new HealthConfig(...$args); self::fail('Invalid health settings accepted.'); }
            catch (\InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }

    public function testCliJsonExitCodesAndMcpFindingsAreNormalResults(): void
    {
        $command = new CommandTester(new HealthCommand('certificate:check', fn () => $this->checker()));
        $command->execute(['host' => 'example.com', '--json' => true]); self::assertSame(0, $command->getStatusCode());
        self::assertSame('PASS', json_decode($command->getDisplay(), true)['status']);
        $command->execute(['host' => 'example.com', '--port' => '22', '--json' => true]); self::assertSame(2, $command->getStatusCode());
        $this->tls->verified = false; $result = (new HealthTools($this->checker()))->certificate('example.com');
        self::assertFalse($result->isError); self::assertSame('FAIL', $result->structuredContent['status']);
        self::assertTrue((new HealthTools($this->checker()))->certificate('../bad')->isError);
    }
    public function testBudgetExhaustionAndEndpointLimitAreIncomplete(): void
    {
        $session = new DnsSession(FakeResolver::healthy(), new Budget(0)); self::assertSame('UNKNOWN', $session->query('example.com', 'A')->status);
        $endpoints = []; for ($i = 0; $i < 9; ++$i) $endpoints[] = ['host' => 'mail'.$i.'.example.com', 'port' => 993];
        $report = $this->checker(config: new HealthConfig(['example.com' => ['endpoints' => $endpoints]]))->domain('example.com');
        self::assertSame(Status::UNKNOWN, $this->checks($report)['tls.limit']->status);
    }
}
