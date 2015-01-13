<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\CommandEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Exception;
use Puli\ComposerPlugin\Logger\IOLogger;
use Puli\RepositoryManager\Config\Config;
use Puli\RepositoryManager\Discovery\DiscoveryManager;
use Puli\RepositoryManager\Environment\ProjectEnvironment;
use Puli\RepositoryManager\ManagerFactory;
use Puli\RepositoryManager\Package\Package;
use Puli\RepositoryManager\Package\PackageFile\RootPackageFileManager;
use Puli\RepositoryManager\Package\PackageManager;
use Puli\RepositoryManager\Package\PackageState;
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
    const INSTALLER_NAME = 'composer';

    /**
     * @var ManagerFactory
     */
    private $managerFactory;

    /**
     * @var ProjectEnvironment
     */
    private $projectEnvironment;

    /**
     * @var bool
     */
    private $runPostInstall = true;

    /**
     * @var bool
     */
    private $runPostAutoloadDump = true;

    /**
     * Creates the plugin.
     */
    public function __construct()
    {
        $this->managerFactory = new ManagerFactory();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'postInstall',
            ScriptEvents::POST_UPDATE_CMD => 'postInstall',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'postAutoloadDump',
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
        if (!$this->runPostInstall) {
            return;
        }

        $this->runPostInstall = false;

        $io = $event->getIO();
        $environment = $this->getProjectEnvironment();

        $logger = new IOLogger($io);
        $packageManager = $this->managerFactory->createPackageManager($environment);

        $io->write('<info>Looking for updated Puli packages</info>');

        $composerPackages = $this->loadComposerPackages($event->getComposer());
        $this->removeRemovedPackages($composerPackages, $packageManager, $io);
        $this->reinstallMovedPackages($composerPackages, $packageManager, $io, $event->getComposer());
        $this->installNewPackages($composerPackages, $packageManager, $io, $event->getComposer());
        $this->checkForLoadErrors($packageManager, $io);

        $packageFileManager = $this->managerFactory->createRootPackageFileManager($environment);
        $repoManager = $this->managerFactory->createRepositoryManager($environment, $packageManager);
        $discoveryManager = $this->managerFactory->createDiscoveryManager($environment, $packageManager, $logger);

        $this->copyComposerName($packageFileManager, $event->getComposer());
        $this->buildRepository($repoManager, $io);
        $this->buildDiscovery($discoveryManager, $io);
    }

    public function postAutoloadDump(Event $event)
    {
        // This method is called twice. Run it only once.
        if (!$this->runPostAutoloadDump) {
            return;
        }

        $this->runPostAutoloadDump = false;

        $io = $event->getIO();
        $rootDir = getcwd();
        $environment = $this->getProjectEnvironment();
        $puliConfig = $environment->getConfig();
        $compConfig = $event->getComposer()->getConfig();
        $vendorDir = $compConfig->get('vendor-dir');

        // On TravisCI, $vendorDir is a relative path. Probably an old Composer
        // build or something. Usually, $vendorDir should be absolute already.
        $vendorDir = Path::makeAbsolute($vendorDir, $rootDir);

        $autoloadFile = $vendorDir.'/autoload.php';
        $classMapFile = $vendorDir.'/composer/autoload_classmap.php';

        $factoryClass = $puliConfig->get(Config::FACTORY_CLASS);
        $factoryFile = Path::makeAbsolute($puliConfig->get(Config::FACTORY_FILE), $rootDir);

        $this->insertFactoryClassConstant($io, $autoloadFile, $factoryClass);
        $this->insertFactoryClassMap($io, $classMapFile, $vendorDir, $factoryClass, $factoryFile);
    }

    private function installNewPackages(array $composerPackages, PackageManager $packageManager, IOInterface $io, Composer $composer)
    {
        $installationManager = $composer->getInstallationManager();
        $rootDir = $packageManager->getEnvironment()->getRootDirectory();

        foreach ($composerPackages as $packageName => $package) {
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
                $packageName,
                Path::makeRelative($installPath, $rootDir)
            ));

            $this->installPackage($packageManager, $installPath, $packageName, $io);
        }
    }

    private function reinstallMovedPackages(array $composerPackages, PackageManager $packageManager, IOInterface $io, Composer $composer)
    {
        $installationManager = $composer->getInstallationManager();
        $rootDir = $packageManager->getEnvironment()->getRootDirectory();

        foreach ($composerPackages as $packageName => $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            $installPath = $installationManager->getInstallPath($package);

            // We are only interested in existing packages
            if (!$packageManager->hasPackage($packageName)) {
                continue;
            }

            // Check whether the install path has changed
            if ($installPath === $packageManager->getPackage($packageName)->getInstallPath()) {
                continue;
            }

            // TODO package must have been installed by Composer

            $io->write(sprintf(
                'Reinstalling <info>%s</info> (<comment>%s</comment>)',
                $packageName,
                Path::makeRelative($installPath, $rootDir)
            ));

            $this->removePackage($packageManager, $packageName);
            $this->installPackage($packageManager, $installPath, $packageName, $io);
        }
    }

    private function removeRemovedPackages(array $composerPackages, PackageManager $packageManager, IOInterface $io)
    {
        $rootDir = $packageManager->getEnvironment()->getRootDirectory();

        foreach ($packageManager->getPackagesByInstaller(self::INSTALLER_NAME, PackageState::NOT_FOUND) as $packageName => $package) {
            // Check whether package was only moved
            if (isset($composerPackages[$packageName])) {
                continue;
            }

            $installPath = $package->getInstallPath();

            $io->write(sprintf(
                'Removing <info>%s</info> (<comment>%s</comment>)',
                $packageName,
                Path::makeRelative($installPath, $rootDir)
            ));

            $this->removePackage($packageManager, $packageName);
        }
    }

    private function checkForLoadErrors(PackageManager $packageManager, IOInterface $io)
    {
        $rootDir = $packageManager->getEnvironment()->getRootDirectory();

        foreach ($packageManager->getPackages(PackageState::NOT_FOUND) as $package) {
            $this->printPackageWarning(
                $io,
                'Could not load package "%s"',
                $package->getName(),
                $package->getInstallPath(),
                $package->getLoadError(),
                $rootDir
            );
        }

        foreach ($packageManager->getPackages(PackageState::NOT_LOADABLE) as $package) {
            $this->printPackageWarning(
                $io,
                'Could not load package "%s"',
                $package->getName(),
                $package->getInstallPath(),
                $package->getLoadError(),
                $rootDir
            );
        }
    }

    private function copyComposerName(RootPackageFileManager $packageFileManager, Composer $composer)
    {
        $packageFileManager->setPackageName($composer->getPackage()->getName());
    }

    private function buildRepository(RepositoryManager $repositoryManager, IOInterface $io)
    {
        $io->write('<info>Building Puli resource repository</info>');

        $repositoryManager->clearRepository();
        $repositoryManager->buildRepository();
    }

    private function buildDiscovery(DiscoveryManager $discoveryManager, IOInterface $io)
    {
        $io->write('<info>Building Puli resource discovery</info>');

        $discoveryManager->clearDiscovery();
        $discoveryManager->buildDiscovery();
    }

    private function insertFactoryClassConstant(IOInterface $io, $autoloadFile, $factoryClass)
    {
        if (!file_exists($autoloadFile)) {
            throw new PuliPluginException(sprintf(
                'Could not adjust autoloader: The file %s was not found.',
                $autoloadFile
            ));
        }

        $io->write('<info>Generating PULI_FACTORY_CLASS constant</info>');

        $contents = file_get_contents($autoloadFile);
        $escFactoryClass = var_export($factoryClass, true);
        $constant = "define('PULI_FACTORY_CLASS', $escFactoryClass);\n\n";

        // Regex modifiers:
        // "m": \s matches newlines
        // "D": $ matches at EOF only
        // Translation: insert before the last "return" in the file
        $contents = preg_replace('/\n(?=return [^;]+;\s*$)/mD', "\n".$constant,
            $contents);

        file_put_contents($autoloadFile, $contents);
    }

    private function insertFactoryClassMap(IOInterface $io, $classMapFile, $vendorDir, $factoryClass, $factoryFile)
    {
        if (!file_exists($classMapFile)) {
            throw new PuliPluginException(sprintf(
                'Could not adjust autoloader: The file %s was not found.',
                $classMapFile
            ));
        }

        $io->write("<info>Registering $factoryClass with the class-map autoloader</info>");

        $relFactoryFile = Path::makeRelative($factoryFile, $vendorDir);
        $escFactoryClass = var_export($factoryClass, true);
        $escFactoryFile = var_export('/'.$relFactoryFile, true);
        $classMap = "\n    $escFactoryClass => \$vendorDir . $escFactoryFile,";

        $contents = file_get_contents($classMapFile);

        // Regex modifiers:
        // "m": \s matches newlines
        // "D": $ matches at EOF only
        // Translation: insert before the last ");" in the file
        $contents = preg_replace('/\n(?=\);\s*$)/mD', "\n".$classMap, $contents);

        file_put_contents($classMapFile, $contents);
    }

    /**
     * Returns Puli's project environment.
     *
     * @return ProjectEnvironment The project environment.
     */
    private function getProjectEnvironment()
    {
        if (!$this->projectEnvironment) {
            $this->projectEnvironment = $this->managerFactory->createProjectEnvironment(getcwd());
        }

        return $this->projectEnvironment;
    }

    /**
     * Loads Composer's currently installed packages.
     *
     * @param Composer $composer The Composer instance.
     *
     * @return PackageInterface[] The installed packages indexed by their names.
     */
    private function loadComposerPackages(Composer $composer)
    {
        $repository = $composer->getRepositoryManager()->getLocalRepository();
        $packages = array();

        foreach ($repository->getPackages() as $package) {
            $packages[$package->getName()] = $package;
        }

        return $packages;
    }

    private function installPackage(PackageManager $packageManager, $installPath, $packageName, IOInterface $io)
    {
        try {
            $packageManager->installPackage($installPath, $packageName, self::INSTALLER_NAME);
        } catch (Exception $e) {
            $this->printPackageWarning(
                $io,
                'Could not install package "%s"',
                $packageName,
                $installPath,
                $e,
                $packageManager->getEnvironment()->getRootDirectory()
            );
        }
    }

    private function removePackage(PackageManager $packageManager, $packageName)
    {
        $packageManager->removePackage($packageName);
    }

    private function getShortClassName($className)
    {
        $pos = strrpos($className, '\\');

        return false === $pos ? $className : substr($className, $pos + 1);
    }

    private function printPackageWarning(IOInterface $io, $message, $packageName, $installPath, Exception $error, $rootDir)
    {
        $io->write(sprintf(
            '<warning>Warning: %s (at %s): %s: %s</warning>',
            sprintf($message, $packageName),
            Path::makeRelative($installPath, $rootDir),
            $this->getShortClassName(get_class($error)),
            str_replace($rootDir.'/', '', $error->getMessage())
        ));
    }

}
