<?php

/*
 * This file is part of the Puli Composer Plugin.
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
use Composer\Package\AliasPackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use Puli\PackageManager\Manager\PackageManager;
use Puli\PackageManager\Manager\ProjectConfigManager;
use Puli\PackageManager\ManagerFactory;
use Puli\Util\Path;

/**
 * A Puli plugin for Composer.
 *
 * The plugin updates the Puli package repository based on the Composer
 * packages whenever `composer install` or `composer update` is executed.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var bool
     */
    private $firstRun = true;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postInstall',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getEventDispatcher()->addSubscriber($this);
    }

    /**
     * Updates the Puli repository after Composer installations/updates.
     *
     * @param CommandEvent $event The Composer event.
     */
    public function postInstall(CommandEvent $event)
    {
        // This method is called twice. Run it only once.
        if (!$this->firstRun) {
            return;
        }

        $this->firstRun = false;

        $io = $event->getIO();
        $environment = ManagerFactory::createProjectEnvironment(getcwd());
        $configManager = ManagerFactory::createProjectConfigManager($environment);

        if (!$this->installComposerPlugin($configManager, $io)) {
            return;
        }

        // Reload environment with the installed plugin
        $environment = ManagerFactory::createProjectEnvironment(getcwd());
        $packageManager = ManagerFactory::createPackageManager($environment);

        $this->installNewPackages($packageManager, $io, $event->getComposer());

        // TODO uninstall removed packages

        $this->generateResourceRepository($packageManager, $io);
    }

    private function installComposerPlugin(ProjectConfigManager $configManager, IOInterface $io)
    {
        $pluginClass = __NAMESPACE__.'\ComposerPlugin';

        if ($configManager->isPluginClassInstalled($pluginClass)) {
            return true;
        }

        if (!$io->askConfirmation('<info>The Composer plugin for Puli is not installed. Install now? (yes/no)</info> [<comment>yes</comment>]: ', true)) {
            // No Puli support desired for now - quit
            return false;
        }

        $configManager->installPluginClass($pluginClass);

        $io->write(sprintf(
            'Wrote <comment>%s</comment>',
            $configManager->getEnvironment()->getRootPackageConfig()->getPath()
        ));

        return true;
    }

    private function installNewPackages(PackageManager $packageManager, IOInterface $io, Composer $composer)
    {
        $io->write('<info>Looking for new Puli packages</info>');

        $repositoryManager = $composer->getRepositoryManager();
        $installationManager = $composer->getInstallationManager();
        $packages = $repositoryManager->getLocalRepository()->getPackages();
        $rootDir = $packageManager->getRootPackage()->getInstallPath();

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            $installPath = $installationManager->getInstallPath($package);

            // Already installed?
            if ($packageManager->isPackageInstalled($installPath)) {
                continue;
            }

            $io->write(sprintf(
                'Installing <info>%s</info> (<comment>%s</comment>)',
                $package->getName(),
                Path::makeRelative($installPath, $rootDir)
            ));

            $packageManager->installPackage($installPath);
        }
    }

    private function generateResourceRepository(PackageManager $packageManager, IOInterface $io)
    {
        $io->write('<info>Generating Puli resource repository</info>');

        $packageManager->generateResourceRepository();
    }
}
