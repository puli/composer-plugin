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
use RuntimeException;
use Webmozart\PathUtil\Path;

/**
 * A Puli plugin for Composer.
 *
 * The plugin updates the Puli package repository based on the Composer
 * packages whenever `composer install` or `composer update` is executed.
 *
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The name of the installer.
     */
    const INSTALLER_NAME = 'composer';

    /**
     * @var PuliRunner
     */
    private $puliRunner;

    /**
     * @var string
     */
    private $rootDir;

    /**
     * @var bool
     */
    private $runPostInstall = true;

    /**
     * @var bool
     */
    private $runPostAutoloadDump = true;

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

    public function __construct(PuliRunner $puliRunner = null)
    {
        $this->puliRunner = $puliRunner;
        $this->rootDir = getcwd();
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        if (!$this->puliRunner) {
            try {
                // Add Composer's bin directory in case the "puli" executable is
                // installed with Composer
                $this->puliRunner = new PuliRunner($composer->getConfig()->get('bin-dir'));
            } catch (RuntimeException $e) {
                $io->writeError('<warn>'.$e->getMessage().'</warn>');

                // Don't activate the plugin if Puli cannot be run
                return;
            }
        }

        $composer->getEventDispatcher()->addSubscriber($this);
    }

    public function postAutoloadDump(Event $event)
    {
        // This method is called twice. Run it only once.
        if (!$this->runPostAutoloadDump) {
            return;
        }

        $this->runPostAutoloadDump = false;

        $io = $event->getIO();

        $compConfig = $event->getComposer()->getConfig();
        $vendorDir = $compConfig->get('vendor-dir');

        // On TravisCI, $vendorDir is a relative path. Probably an old Composer
        // build or something. Usually, $vendorDir should be absolute already.
        $vendorDir = Path::makeAbsolute($vendorDir, $this->rootDir);

        $autoloadFile = $vendorDir.'/autoload.php';
        $classMapFile = $vendorDir.'/composer/autoload_classmap.php';

        try {
            $factoryClass = $this->getConfigKey('factory.in.class');
            $factoryFile = $this->getConfigKey('factory.in.file');
        } catch (PuliRunnerException $e) {
            $this->printWarning($io, 'Could not load Puli configuration', $e);

            return;
        }

        $factoryFile = Path::makeAbsolute($factoryFile, $this->rootDir);

        $this->insertFactoryClassConstant($io, $autoloadFile, $factoryClass);
        $this->insertFactoryClassMap($io, $classMapFile, $vendorDir, $factoryClass, $factoryFile);
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

        $io->write('<info>Looking for updated Puli packages</info>');

        $composerPackages = $this->loadComposerPackages($event->getComposer());

        try {
            $puliPackages = $this->loadPuliPackages();
        } catch (PuliRunnerException $e) {
            $this->printWarning($io, 'Could not load Puli packages', $e);

            return;
        }

        $this->removeRemovedPackages($composerPackages, $puliPackages, $io);
        $this->installNewPackages($composerPackages, $puliPackages, $io, $event->getComposer());
        $this->checkForLoadErrors($puliPackages, $io);
        $this->adoptComposerName($puliPackages, $io, $event->getComposer());
        $this->buildPuli($io);
    }

    /**
     * @param PackageInterface[] $composerPackages
     * @param PuliPackage[]      $puliPackages
     * @param IOInterface        $io
     * @param Composer           $composer
     */
    private function installNewPackages(array $composerPackages, array &$puliPackages, IOInterface $io, Composer $composer)
    {
        $installationManager = $composer->getInstallationManager();

        foreach ($composerPackages as $packageName => $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            $installPath = $installationManager->getInstallPath($package);

            // Skip meta packages
            if ('' === $installPath) {
                continue;
            }

            if (isset($puliPackages[$packageName])) {
                // Only proceed if the install path has changed
                if ($installPath === $puliPackages[$packageName]->getInstallPath()) {
                    continue;
                }

                // Only remove packages installed by Composer
                if (self::INSTALLER_NAME === $puliPackages[$packageName]->getInstallerName()) {
                    $io->write(sprintf(
                        'Reinstalling <info>%s</info> (<comment>%s</comment>)',
                        $packageName,
                        Path::makeRelative($installPath, $this->rootDir)
                    ));

                    try {
                        $this->removePackage($packageName);
                    } catch (PuliRunnerException $e) {
                        $this->printPackageWarning($io, 'Could not remove package "%s" (at ./%s)', $packageName, $installPath, $e);

                        continue;
                    }
                }
            } else {
                $io->write(sprintf(
                    'Installing <info>%s</info> (<comment>%s</comment>)',
                    $packageName,
                    Path::makeRelative($installPath, $this->rootDir)
                ));
            }

            try {
                $this->installPackage($installPath, $packageName, $io);
            } catch (PuliRunnerException $e) {
                $this->printPackageWarning($io, 'Could not install package "%s" (at ./%s)', $packageName, $installPath, $e);

                continue;
            }

            $puliPackages[$packageName] = new PuliPackage(
                $packageName,
                self::INSTALLER_NAME,
                $installPath,
                PuliPackage::STATE_ENABLED
            );
        }
    }

    /**
     * @param PackageInterface[] $composerPackages
     * @param PuliPackage[]      $puliPackages
     * @param IOInterface        $io
     */
    private function removeRemovedPackages(array $composerPackages, array &$puliPackages, IOInterface $io)
    {
        /** @var PuliPackage[] $notFoundPackages */
        $notFoundPackages = array_filter($puliPackages, function (PuliPackage $package) {
            return PuliPackage::STATE_NOT_FOUND === $package->getState()
                && PuliPlugin::INSTALLER_NAME === $package->getInstallerName();
        });

        foreach ($notFoundPackages as $packageName => $package) {
            // Check whether package was only moved
            if (isset($composerPackages[$packageName])) {
                continue;
            }

            $io->write(sprintf(
                'Removing <info>%s</info> (<comment>%s</comment>)',
                $packageName,
                Path::makeRelative($package->getInstallPath(), $this->rootDir)
            ));

            try {
                $this->removePackage($packageName);
            } catch (PuliRunnerException $e) {
                $this->printPackageWarning($io, 'Could not remove package "%s" (at ./%s)', $packageName, $package->getInstallPath(), $e);

                continue;
            }

            unset($puliPackages[$packageName]);
        }
    }

    private function checkForLoadErrors(array $puliPackages, IOInterface $io)
    {
        /** @var PuliPackage[] $notFoundPackages */
        $notFoundPackages = array_filter($puliPackages, function (PuliPackage $package) {
            return PuliPackage::STATE_NOT_FOUND === $package->getState()
                && PuliPlugin::INSTALLER_NAME === $package->getInstallerName();
        });

        foreach ($notFoundPackages as $package) {
            $this->printPackageWarning(
                $io,
                'The package "%s" (at ./%s) could not be found',
                $package->getName(),
                $package->getInstallPath()
            );
        }

        /** @var PuliPackage[] $notLoadablePackages */
        $notLoadablePackages = array_filter($puliPackages, function (PuliPackage $package) {
            return PuliPackage::STATE_NOT_LOADABLE === $package->getState()
                && PuliPlugin::INSTALLER_NAME === $package->getInstallerName();
        });

        foreach ($notLoadablePackages as $package) {
            $this->printPackageWarning(
                $io,
                'The package "%s" (at ./%s) could not be loaded',
                $package->getName(),
                $package->getInstallPath()
            );
        }
    }

    private function adoptComposerName(array $puliPackages, IOInterface $io, Composer $composer)
    {
        $rootDir = $this->rootDir;

        /** @var PuliPackage[] $rootPackages */
        $rootPackages = array_filter($puliPackages, function (PuliPackage $package) use ($rootDir) {
            return !$package->getInstallerName() && $rootDir === $package->getInstallPath();
        });

        if (0 === count($rootPackages)) {
            // This should never happen
            $this->printWarning($io, 'No root package could be found');

            return;
        }

        if (count($rootPackages) > 1) {
            // This should never happen
            $this->printWarning($io, 'More than one root package was found');

            return;
        }

        /** @var PuliPackage $rootPackage */
        $rootPackage = reset($rootPackages);
        $name = $rootPackage->getName();
        $newName = $composer->getPackage()->getName();

        // Rename the root package after changing the name in composer.json
        if ($name !== $newName) {
            try {
                $this->renamePackage($name, $newName);
            } catch (PuliRunnerException $e) {
                $this->printWarning($io, sprintf(
                    'Could not rename root package to "%s"',
                    $newName
                ), $e);
            }
        }
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
        $constant = "if (!defined('PULI_FACTORY_CLASS')) {\n";
        $constant .= "    define('PULI_FACTORY_CLASS', $escFactoryClass);\n";
        $constant .= "}\n\n";

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
            /* @var PackageInterface $package */
            $packages[$package->getName()] = $package;
        }

        return $packages;
    }

    private function getConfigKey($key)
    {
        return trim($this->puliRunner->run(sprintf(
            'config %s --parsed',
            escapeshellarg($key)
        )));
    }

    /**
     * @return PuliPackage[]
     */
    private function loadPuliPackages()
    {
        $packages = array();

        $output = $this->puliRunner->run(
            'package --list --format \'%name%;%installer%;%install_path%;%state%\''
        );

        foreach (explode("\n", $output) as $packageLine) {
            if (!$packageLine) {
                continue;
            }

            $packageParts = explode(';', $packageLine);

            $packages[$packageParts[0]] = new PuliPackage(
                $packageParts[0],
                $packageParts[1],
                $packageParts[2],
                $packageParts[3]
            );
        }

        return $packages;
    }

    private function installPackage($installPath, $packageName)
    {
        $this->puliRunner->run(sprintf(
            'package --add %s %s --installer %s',
            escapeshellarg($installPath),
            escapeshellarg($packageName),
            escapeshellarg(self::INSTALLER_NAME)
        ));
    }

    private function removePackage($packageName)
    {
        $this->puliRunner->run(sprintf(
            'package --delete %s',
            escapeshellarg($packageName)
        ));
    }

    private function buildPuli(IOInterface $io)
    {
        $io->write('<info>Running "puli build"</info>');

        $this->puliRunner->run('build');
    }

    private function renamePackage($name, $newName)
    {
        $this->puliRunner->run(sprintf(
            'package --rename %s %s',
            escapeshellarg($name),
            escapeshellarg($newName)
        ));
    }

    private function printWarning(IOInterface $io, $message, PuliRunnerException $exception = null)
    {
        if (!$exception) {
            $reasonPhrase = '';
        } elseif ($io->isVerbose()) {
            $reasonPhrase = $exception->getFullError();
        } else {
            $reasonPhrase = $exception->getShortError();
        }

        $io->writeError(sprintf(
            '<warning>Warning: %s%s</warning>',
            $message,
            $reasonPhrase ? ': '.$reasonPhrase : '.'
        ));
    }

    private function printPackageWarning(IOInterface $io, $message, $packageName, $installPath, PuliRunnerException $exception = null)
    {
        $this->printWarning($io, sprintf(
            $message,
            $packageName,
            Path::makeRelative($installPath, $this->rootDir)
        ), $exception);
    }
}
