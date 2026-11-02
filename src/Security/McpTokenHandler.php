<?php

declare(strict_types=1);

namespace OvhDns\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class McpTokenHandler implements AccessTokenHandlerInterface
{
    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $expected = getenv('MCP_HTTP_TOKEN') ?: ($_ENV['MCP_HTTP_TOKEN'] ?? '');
        if (strlen($expected) < 32 || !hash_equals($expected, $accessToken)) {
            throw new BadCredentialsException('Invalid bearer token.');
        }
        return new UserBadge('mcp');
    }
}
