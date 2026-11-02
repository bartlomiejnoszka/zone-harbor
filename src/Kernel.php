<?php

declare(strict_types=1);

namespace OvhDns;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        $user = Configuration::user();
        if ($user === null) return parent::getCacheDir();
        $key = substr(hash('sha256', $this->getProjectDir().'|'.Version::NUMBER.'|'.$user->file.'|'.json_encode($user->data).'|'.(getenv('MCP_ALLOWED_HOSTS') ?: '').'|'.(getenv('MCP_ALLOWED_ORIGINS') ?: '')), 0, 16);
        return $user->stateDirectory().'/cache/'.$key.'/'.$this->environment;
    }
    public function getLogDir(): string { $user = Configuration::user(); return $user !== null ? $user->stateDirectory().'/logs' : parent::getLogDir(); }
    public function getProjectDir(): string { return dirname(__DIR__); }
}
