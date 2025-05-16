<?php

declare(strict_types=1);

namespace Cando\ComposerPatchesInliner\Plugin\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

class ComposerPlugin implements PluginInterface, Capable
{
    protected Composer $composer;
    protected IOInterface $io;

    public function getCapabilities()
    {
        return array(
            'Composer\Plugin\Capability\CommandProvider' => 'Cando\ComposerPatchesInliner\Plugin\Composer\CommandProvider',
        );
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public function install(Composer $composer, IOInterface $io)
    {
    }
}
