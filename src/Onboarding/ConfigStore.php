<?php

declare(strict_types=1);

namespace OvhDns\Onboarding;

use Symfony\Component\Yaml\Yaml;

final class ConfigStore
{
    /** Credentials are staged in a new file; replacing config never invalidates the previously active file. */
    public function save(string $file, array $data, ?array $credentials, ?string $expectedHash): void
    {
        $created = null;
        try {
            PrivateFile::update($file, function (?string $before) use ($file, &$data, $credentials, $expectedHash, &$created): string {
                if (($before === null ? null : hash('sha256', $before)) !== $expectedHash) throw new \RuntimeException('Configuration changed during setup. Restart setup to preserve those edits.');
                if ($credentials !== null) {
                    $created = dirname($file).'/credentials-'.bin2hex(random_bytes(8)).'.env';
                    $text = '';
                    foreach ($credentials as $key => $value) {
                        if (!in_array($key, ['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY'], true) || !is_string($value) || $value === '' || strpbrk($value, "\r\n\0") !== false) throw new \InvalidArgumentException('Credentials must be non-empty single-line values.');
                        $text .= $key."='".str_replace("'", "'\\''", $value)."'\n";
                    }
                    PrivateFile::create($created, $text);
                    $data['ovh']['credentials_file'] = basename($created);
                }
                // Empty maps must remain maps when serialized and validated against JSON Schema.
                foreach (['health', 'ovh', 'storage'] as $key) if (isset($data[$key]) && $data[$key] === []) unset($data[$key]);
                if (isset($data['health']['domains'])) foreach ($data['health']['domains'] as &$domain) if ($domain === []) $domain = new \stdClass();
                unset($domain);
                $yaml = "# ZoneHarbor configuration; credentials are stored separately.\n".Yaml::dump($data, 8, 2, Yaml::DUMP_OBJECT_AS_MAP);
                $validation = dirname($file).'/.validate-'.bin2hex(random_bytes(8)).'.yaml';
                try { PrivateFile::create($validation, $yaml); UserConfiguration::load($validation); }
                finally { if (is_file($validation)) unlink($validation); }
                return $yaml;
            });
        } catch (\Throwable $error) {
            if ($created !== null && is_file($created)) unlink($created);
            throw $error;
        }
    }
}
