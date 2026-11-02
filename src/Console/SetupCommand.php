<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Configuration;
use OvhDns\Dns\DnsInput;
use OvhDns\Onboarding\{ConfigStore, Diagnostics, McpClientConfiguration, Paths, UserConfiguration};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Dotenv\Dotenv;

final class SetupCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('setup')->setDescription('Create or update private user settings with an interactive wizard.')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'health or ovh; required with --no-interaction.')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Default domain to open in the TUI.')
            ->addOption('skip-checks', null, InputOption::VALUE_NONE, 'Save configuration without connection checks.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $file = Paths::selected($input->getOption('config'));
            $before = is_file($file) ? @file_get_contents($file) : null;
            if ($before === false) throw new \InvalidArgumentException('Cannot read the existing configuration.');
            $existing = $before !== null ? UserConfiguration::load($file) : null;
            $data = $existing !== null ? $existing->data : ['version' => 1, 'mode' => 'health'];
            $mode = $input->getOption('mode');
            if ($mode === null && !$input->isInteractive()) throw new \InvalidArgumentException('Non-interactive setup requires --mode=health or --mode=ovh. Credentials can be supplied through the environment.');
            $io->title('ZoneHarbor setup');
            if ($mode === null) $mode = $io->choice('What would you like to use?', ['health' => 'Public DNS and certificate checks (no account)', 'ovh' => 'OVH DNS management and health checks'], $data['mode']);
            // Symfony choice returns the label when associative choices are used.
            if ($mode === 'Public DNS and certificate checks (no account)') $mode = 'health';
            if ($mode === 'OVH DNS management and health checks') $mode = 'ovh';
            if (!in_array($mode, ['health', 'ovh'], true)) throw new \InvalidArgumentException('Choose mode health or ovh.');
            $data['mode'] = $mode;
            $credentials = null;
            if ($mode === 'ovh') {
                $endpoint = getenv('OVH_ENDPOINT') ?: ($data['ovh']['endpoint'] ?? 'ovh-eu');
                if (!in_array($endpoint, ['ovh-eu', 'ovh-ca', 'ovh-us'], true)) throw new \InvalidArgumentException('Choose a valid OVH region.');
                if ($input->isInteractive()) $endpoint = $io->choice('OVH region', ['ovh-eu', 'ovh-ca', 'ovh-us'], $endpoint);
                $data['ovh']['endpoint'] = $endpoint;
                if ($input->isInteractive()) $io->text('Create DNS-scoped credentials in the OVH API portal: https://api.ovh.com/');
                $current = $this->currentCredentials($existing);
                $credentials = [];
                foreach (['OVH_APPLICATION_KEY' => 'Application key', 'OVH_APPLICATION_SECRET' => 'Application secret', 'OVH_CONSUMER_KEY' => 'Consumer key'] as $key => $label) {
                    $value = $current[$key] ?? '';
                    if ($input->isInteractive()) {
                        $question = (new Question($label.($value === '' ? '' : ' (leave blank to keep configured value)')))->setHidden(true)->setHiddenFallback(false);
                        $answer = $io->askQuestion($question);
                        if (is_string($answer) && $answer !== '') $value = $answer;
                    }
                    if ($value === '' || strpbrk($value, "\r\n\0") !== false) throw new \InvalidArgumentException('All three OVH credentials must be non-empty single-line values.');
                    $credentials[$key] = $value;
                }
                if ($existing?->credentialsFile() !== null && $credentials === $current) $credentials = null;
            }
            $domain = $input->getOption('domain') ?? ($data['default_domain'] ?? null);
            if ($input->isInteractive()) $domain = $io->ask('Default domain (optional)', $domain);
            if (is_string($domain) && $domain !== '') $data['default_domain'] = DnsInput::zone($domain);
            else unset($data['default_domain']);
            if ($input->isInteractive() && isset($data['default_domain'])) $this->mailSettings($io, $data);
            $integration = $input->isInteractive() ? $io->choice('MCP client settings to generate', ['none', 'generic', 'claude-desktop', 'cursor'], 'none') : 'none';
            $io->text(['Mode: '.$data['mode'], 'Default domain: '.($data['default_domain'] ?? 'choose in TUI'), 'Configuration: '.$file, 'Credentials: '.($mode === 'ovh' ? 'private file or existing environment; values hidden' : 'not needed')]);
            if ($input->isInteractive() && !$io->confirm('Save these settings?', true)) { $io->text('Setup cancelled. Existing settings were preserved.'); return 0; }
            (new ConfigStore())->save($file, $data, $credentials, $before === null ? null : hash('sha256', $before));
            Configuration::initialize(dirname(__DIR__, 2), $file);
            $io->success('Configuration saved. Start the TUI with zone-harbor. Re-run setup to change settings.');
            if (!$input->getOption('skip-checks')) {
                $online = $input->isInteractive();
                $report = (new Diagnostics())->inspect($file, $online);
                $io->text('Installation checks: '.$report['status'].'. Run zone-harbor doctor --online for details.');
            }
            if ($integration !== 'none') {
                $io->text('Generated local STDIO settings (no credential values):');
                $output->write((new McpClientConfiguration(McpClientConfiguration::runtimeFor($file), image: getenv('ZONE_HARBOR_DOCKER_IMAGE') ?: 'zone-harbor:local', volume: getenv('ZONE_HARBOR_DOCKER_VOLUME') ?: 'zone-harbor_config', client: $integration))->merge(null, $file), false, OutputInterface::OUTPUT_RAW);
                if ($integration !== 'generic') $io->text('Preview with zone-harbor mcp:config --client='.$integration.'; add --write to merge with a private backup.');
            }
            return 0;
        } catch (\InvalidArgumentException|\OvhDns\ConfigurationException $e) { DnsApplication::error($output, $e->getMessage()); return 2; }
        catch (\Throwable) { DnsApplication::error($output, 'Setup could not finish. Check configuration directory permissions; credential values were hidden.'); return 1; }
    }

    private function currentCredentials(?UserConfiguration $user): array
    {
        $values = [];
        if (($file = $user?->credentialsFile()) !== null && is_file($file)) {
            try { $values = (new Dotenv())->parse(file_get_contents($file)); }
            catch (\Throwable) { throw new \InvalidArgumentException('Cannot read existing credentials. Repair the credentials file before re-running setup.'); }
        }
        $result = [];
        foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY'] as $key) {
            $result[$key] = getenv($key) !== false ? getenv($key) : ($values[$key] ?? $_ENV[$key] ?? '');
            $secretFile = getenv($key.'_FILE') ?: ($_ENV[$key.'_FILE'] ?? null);
            if ($secretFile !== null) {
                $value = @file_get_contents($secretFile);
                if ($value === false || trim($value) === '') throw new \InvalidArgumentException('Cannot read a configured OVH secret file. Check its permissions.');
                $result[$key] = trim($value);
            }
        }
        return $result;
    }
    private function mailSettings(SymfonyStyle $io, array &$data): void
    {
        $domain = $data['default_domain']; $settings = $data['health']['domains'][$domain] ?? [];
        $mail = $io->choice('Mail provider expectation', ['auto', 'google', 'external', 'none'], $settings['mail'] ?? 'auto');
        if ($mail === 'auto') unset($settings['mail']); else $settings['mail'] = $mail;
        $selectors = $io->ask('DKIM selectors, comma-separated (optional)', implode(',', $settings['dkim_selectors'] ?? []));
        $selectors = array_values(array_unique(array_filter(array_map('trim', explode(',', $selectors ?? '')))));
        if ($selectors === []) unset($settings['dkim_selectors']); else $settings['dkim_selectors'] = $selectors;
        if ($settings !== []) $data['health']['domains'][$domain] = $settings;
    }
}
