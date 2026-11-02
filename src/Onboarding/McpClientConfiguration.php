<?php

declare(strict_types=1);

namespace OvhDns\Onboarding;

final class McpClientConfiguration
{
    public function __construct(private readonly string $runtime = 'native', private readonly string $dockerCommand = 'docker', private readonly string $image = 'zone-harbor:local', private readonly string $volume = 'zone-harbor_config', private readonly string $client = 'generic')
    {
        if (!in_array($client, ['generic', 'cursor', 'claude-desktop'], true) || !in_array($runtime, ['native', 'docker'], true) || $dockerCommand === '' || preg_match('/[\x00-\x1f\x7f]/', $dockerCommand)) throw new \InvalidArgumentException('Invalid MCP runtime or Docker executable.');
        if (!preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._/:@-]*$~D', $image) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $volume)) throw new \InvalidArgumentException('Choose a Docker image and named configuration volume.');
    }
    public static function inContainer(): bool { return is_file('/.dockerenv'); }
    public static function runtimeFor(string $file): string { return self::inContainer() && str_starts_with($file, '/app/user-config/') ? 'docker' : 'native'; }
    public function entry(string $configuration): array
    {
        if ($this->runtime === 'docker') {
            if (!str_starts_with(Paths::absolute($configuration), '/app/user-config/')) throw new \InvalidArgumentException('Generate Docker MCP settings inside the onboarding container, using its mounted /app/user-config configuration.');
            return ['command' => $this->dockerCommand, 'args' => ['run', '--rm', '-i', '--read-only', '--tmpfs', '/tmp', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', '--mount', 'type=volume,source='.$this->volume.',target=/app/user-config', $this->image, 'php', '/app/bin/zone-harbor', '--config', $configuration, 'mcp:serve']];
        }
        return ['command' => Paths::php(), 'args' => [Paths::launcher(), '--config', Paths::absolute($configuration), 'mcp:serve']];
    }
    public function merge(?string $before, string $configuration): string
    {
        try { $data = $before === null ? new \stdClass() : json_decode($before, flags: JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \InvalidArgumentException('Existing client configuration is not valid JSON. It was not changed.'); }
        if (!$data instanceof \stdClass || isset($data->mcpServers) && !$data->mcpServers instanceof \stdClass) throw new \InvalidArgumentException('Existing client configuration must contain an object of MCP servers.');
        $data->mcpServers ??= new \stdClass();
        $entry = $this->entry($configuration);
        if ($this->client === 'cursor') $entry = ['type' => 'stdio'] + $entry;
        $data->mcpServers->{'zone-harbor'} = $entry;
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
