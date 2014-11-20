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
use Puli\PackageManager\PackageManager;
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
        $rootDir = getcwd();

        // Add Puli support if necessary
        if (!PackageManager::isPuliProject($rootDir)) {
            if (!$io->askConfirmation('<info>The project does not have Puli support. Add Puli support now? (yes/no)</info> [<comment>yes</comment>]: ', true)) {
                // No Puli support desired for now - quit
                return;
            }

            PackageManager::initializePuliProject($rootDir);
            $io->write('Wrote <comment>puli.json</comment>');
        }

        $packageManager = PackageManager::createDefault($rootDir);
        $pluginClass = __NAMESPACE__.'\ComposerPlugin';

        // Enable the Puli plugin so that we can load the package names from
        // Composer
        if (!$packageManager->isPluginClassInstalled($pluginClass)) {
            if (!$io->askConfirmation('<info>The Composer plugin for Puli is not installed. Install now? (yes/no)</info> [<comment>yes</comment>]: ', true)) {
                // No Puli support desired for now - quit
                return;
            }

            // Install plugin
            $packageManager->installPluginClass($pluginClass, true);
            $io->write(sprintf(
                'Wrote <comment>%s</comment>',
                PackageManager::getHomeDirectory().'/'.PackageManager::GLOBAL_CONFIG
            ));

            // Restart package manager to load plugin
            $packageManager = PackageManager::createDefault(getcwd());
        }

        // Install new Composer packages
        $io->write('<info>Looking for new Puli packages</info>');
        $this->installNewPackages($event, $packageManager);

        // TODO uninstall removed packages

        // Refresh Puli resource repository
        $io->write('<info>Generating Puli resource repository</info>');
        $packageManager->generateResourceRepository();
    }

    private function installNewPackages(CommandEvent $event, PackageManager $packageManager)
    {
        // Install other packages
        $repositoryManager = $event->getComposer()->getRepositoryManager();
        $installationManager = $event->getComposer()->getInstallationManager();
        $packages = $repositoryManager->getLocalRepository()->getPackages();
        $rootDir = $packageManager->getRootPackage()->getInstallPath();

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            $installPath = $installationManager->getInstallPath($package);

            // Puli support?
            if (!file_exists($installPath.'/'.PackageManager::PACKAGE_CONFIG)) {
                continue;
            }

            // Already installed?
            if ($packageManager->isPackageInstalled($installPath)) {
                continue;
            }

            $event->getIO()->write(sprintf(
                'Installing <info>%s</info> (<comment>%s</comment>)',
                $package->getName(),
                Path::makeRelative($installPath, $rootDir)
            ));

            $packageManager->installPackage($installPath);
        }
    }
}
