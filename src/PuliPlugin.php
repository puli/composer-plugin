<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use Puli\Extension\Composer\RepositoryBuilder\RepositoryBuilder;
use Puli\Extension\Composer\RepositoryDumper\RepositoryDumper;
use Puli\Repository\ResourceRepository;

/**
 * A plugin for managing resources of Composer dependencies.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPlugin implements PluginInterface, EventSubscriberInterface
{
    const VERSION = '@package_version@';

    const RELEASE_DATE = '@release_date@';

    private $firstRun = true;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'dumpRepository',
            ScriptEvents::POST_UPDATE_CMD => 'dumpRepository',
        );
    }

    /**
     * Apply plugin modifications to composer
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getEventDispatcher()->addSubscriber($this);
    }

    public function dumpRepository(CommandEvent $event)
    {
        // This method is called twice. Run it only once.
        if (!$this->firstRun) {
            return;
        }

        $this->firstRun = false;

        $composer = $event->getComposer();
        $repositoryManager = $composer->getRepositoryManager();

        $dumper = new RepositoryDumper();
        $dumper->setInstallationManager($composer->getInstallationManager());
        $dumper->setProjectDir(getcwd());
        $dumper->setVendorDir($composer->getConfig()->get('vendor-dir'));
        $dumper->setProjectPackage($composer->getPackage());
        $dumper->setInstalledPackages($repositoryManager->getLocalRepository()->getPackages());
        $dumper->setRepositoryBuilder(new RepositoryBuilder());

        $event->getIO()->write('<info>Generating resource repository</info>');

        $dumper->dumpRepository();
    }
}
