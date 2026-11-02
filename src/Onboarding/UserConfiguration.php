<?php

declare(strict_types=1);

namespace OvhDns\Onboarding;

use OvhDns\ConfigurationException;
use OvhDns\Dns\DnsInput;
use OvhDns\Health\HealthConfig;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

final readonly class UserConfiguration
{
    private function __construct(public string $file, public array $data) {}
    public static function load(string $file): self
    {
        $contents = @file_get_contents($file);
        if ($contents === false) throw new ConfigurationException('Cannot read the selected configuration. Run zone-harbor setup --config PATH to create it.');
        try {
            $object = Yaml::parse($contents, Yaml::PARSE_OBJECT_FOR_MAP);
            $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/schema/zone-harbor.schema.json'), flags: JSON_THROW_ON_ERROR);
            if (!(new Validator())->validate($object, $schema)->isValid()) throw new \InvalidArgumentException();
            $data = json_decode(json_encode($object, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            if (isset($data['default_domain'])) $data['default_domain'] = DnsInput::zone($data['default_domain']);
            foreach (array_keys($data['health']['domains'] ?? []) as $domain) if (DnsInput::zone($domain) !== $domain) throw new \InvalidArgumentException();
            self::healthFrom($data);
            foreach ([$data['ovh']['credentials_file'] ?? null, $data['storage']['directory'] ?? null] as $path) if ($path !== null) Paths::absolute($path, dirname($file));
            return new self(Paths::absolute($file), $data);
        } catch (\Throwable) { throw new ConfigurationException('Invalid user configuration. Check version, field names and values against the example and schema.'); }
    }
    public function health(): HealthConfig { return self::healthFrom($this->data); }
    private static function healthFrom(array $data): HealthConfig
    {
        return new HealthConfig($data['health']['domains'] ?? [], $data['health']['certificate']['warning_remaining'] ?? 0.25, $data['health']['certificate']['critical_remaining'] ?? 0.10);
    }
    public function mode(): string { return $this->data['mode']; }
    public function credentialsFile(): ?string
    {
        return isset($this->data['ovh']['credentials_file']) ? Paths::absolute($this->data['ovh']['credentials_file'], dirname($this->file)) : null;
    }
    public function stateDirectory(): string
    {
        return Paths::absolute($this->data['storage']['directory'] ?? 'state', dirname($this->file));
    }
}
