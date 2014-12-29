<?php

/*
 * This file is part of the puli/composer-plugin package.
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
use Puli\RepositoryManager\ManagerFactory;
use Puli\RepositoryManager\Package\PackageManager;
use Puli\RepositoryManager\Repository\RepositoryManager;
use Webmozart\PathUtil\Path;

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
     * The name of the installer.
     */
    const INSTALLER_NAME = 'Composer';

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
        $packageManager = ManagerFactory::createPackageManager($environment);

        $this->removeRemovedPackages($packageManager, $io, $event->getComposer());
        $this->installNewPackages($packageManager, $io, $event->getComposer());

        $repoManager = ManagerFactory::createRepositoryManager($environment, $packageManager);

        $this->generateResourceRepository($repoManager, $io);
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

            $packageManager->installPackage($installPath, $package->getName(), self::INSTALLER_NAME);
        }
    }

    private function removeRemovedPackages(PackageManager $packageManager, IOInterface $io, Composer $composer)
    {
        $io->write('<info>Looking for removed Puli packages</info>');

        $repositoryManager = $composer->getRepositoryManager();
        $packages = $repositoryManager->getLocalRepository()->getPackages();
        $packageNames = array();
        $rootDir = $packageManager->getRootPackage()->getInstallPath();

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            $packageNames[$package->getName()] = true;
        }

        foreach ($packageManager->getPackagesByInstaller(self::INSTALLER_NAME) as $package) {
            if (!isset($packageNames[$package->getName()])) {
                $installPath = $package->getInstallPath();

                $io->write(sprintf(
                    'Removing <info>%s</info> (<comment>%s</comment>)',
                    $package->getName(),
                    Path::makeRelative($installPath, $rootDir)
                ));

                $packageManager->removePackage($package->getName());
            }
        }
    }

    private function generateResourceRepository(RepositoryManager $repositoryManager, IOInterface $io)
    {
        $io->write('<info>Building Puli resource repository</info>');

        $repositoryManager->buildRepository();
    }
}
