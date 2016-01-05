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
     * The minimum version of the Puli CLI.
     */
    const MIN_CLI_VERSION = '1.0.0-beta9';

    /**
     * The maximum version of the Puli CLI.
     */
    const MAX_CLI_VERSION = '1.999.99999';

    /**
     * The name of the installer.
     */
    const INSTALLER_NAME = 'composer';

    /**
     * @var bool
     */
    private $initialized = false;

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
        $this->rootDir = Path::normalize(getcwd());
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getEventDispatcher()->addSubscriber($this);
    }

    public function postAutoloadDump(Event $event)
    {
        // Plugin has been uninstalled
        if (!file_exists(__FILE__)) {
            return;
        }

        if (!$this->initialized) {
            $this->initialize($event->getComposer(), $event->getIO());
        }

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
        $this->setBootstrapFile($io, $autoloadFile);
    }

    /**
     * Updates the Puli repository after Composer installations/updates.
     *
     * @param CommandEvent $event The Composer event.
     */
    public function postInstall(CommandEvent $event)
    {
        // Plugin has been uninstalled
        if (!file_exists(__FILE__)) {
            return;
        }

        if (!$this->initialized) {
            $this->initialize($event->getComposer(), $event->getIO());
        }

        // This method is called twice. Run it only once.
        if (!$this->runPostInstall) {
            return;
        }

        $this->runPostInstall = false;

        $io = $event->getIO();

        $io->write('<info>Looking for updated Puli packages</info>');

        $rootPackage = $event->getComposer()->getPackage();
        $composerPackages = $this->loadComposerPackages($event->getComposer());
        $prodPackageNames = $this->filterProdPackageNames($composerPackages, $rootPackage);
        $env = $event->isDevMode() ? PuliPackage::ENV_DEV : PuliPackage::ENV_PROD;

        try {
            $puliPackages = $this->loadPuliPackages();
        } catch (PuliRunnerException $e) {
            $this->printWarning($io, 'Could not load Puli packages', $e);

            return;
        }

        // Don't remove non-existing packages in production environment
        // Removed packages could be dev dependencies (i.e. "require-dev"
        // of the root package or "require" of another dev dependency), and
        // we can't find out whether they are since Composer doesn't load them
        if (PuliPackage::ENV_PROD !== $env) {
            $this->removeRemovedPackages($composerPackages, $puliPackages, $io);
        }

        $this->installNewPackages($composerPackages, $prodPackageNames, $puliPackages, $io, $event->getComposer());

        // Don't print warnings for non-existing packages in production
        if (PuliPackage::ENV_PROD !== $env) {
            $this->checkForNotFoundErrors($puliPackages, $io);
        }

        $this->checkForNotLoadableErrors($puliPackages, $io);
        $this->adoptComposerName($puliPackages, $io, $event->getComposer());
        $this->buildPuli($io);
    }

    /**
     * @param Composer    $composer
     * @param IOInterface $io
     */
    private function initialize(Composer $composer, IOInterface $io)
    {
        // This method must be run after all packages are installed, otherwise
        // it could be that the puli/cli is not yet installed and hence the
        // CLI is not available

        // Previously, this was called in activate(), which is called
        // immediately after installing the plugin, but potentially before
        // installing the CLI

        $this->initialized = true;

        // Keep the manually set runner
        if (!$this->puliRunner) {
            try {
                // Add Composer's bin directory in case the "puli" executable is
                // installed with Composer
                $this->puliRunner = new PuliRunner($composer->getConfig()->get('bin-dir'));
            } catch (RuntimeException $e) {
                $this->printWarning($io, 'Plugin initialization failed', $e);
                $this->runPostAutoloadDump = false;
                $this->runPostInstall = false;
            }
        }

        // Use the runner to verify if Puli has the right version
        try {
            $this->verifyPuliVersion();
        } catch (RuntimeException $e) {
            $this->printWarning($io, 'Version check failed', $e);
            $this->runPostAutoloadDump = false;
            $this->runPostInstall = false;
        }
    }

    /**
     * @param PackageInterface[] $composerPackages
     * @param bool[]             $prodPackageNames
     * @param PuliPackage[]      $puliPackages
     * @param IOInterface        $io
     * @param Composer           $composer
     */
    private function installNewPackages(array $composerPackages, array $prodPackageNames, array &$puliPackages, IOInterface $io, Composer $composer)
    {
        $installationManager = $composer->getInstallationManager();

        foreach ($composerPackages as $packageName => $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            // We need to normalize the system-dependent paths returned by Composer
            $installPath = Path::normalize($installationManager->getInstallPath($package));
            $env = isset($prodPackageNames[$packageName]) ? PuliPackage::ENV_PROD : PuliPackage::ENV_DEV;

            // Skip meta packages
            if ('' === $installPath) {
                continue;
            }

            if (isset($puliPackages[$packageName])) {
                $puliPackage = $puliPackages[$packageName];

                // Only proceed if the install path or environment has changed
                if ($installPath === $puliPackage->getInstallPath() && $env === $puliPackage->getEnvironment()) {
                    continue;
                }

                // Only remove packages installed by Composer
                if (self::INSTALLER_NAME === $puliPackage->getInstallerName()) {
                    $io->write(sprintf(
                        'Reinstalling <info>%s</info> (<comment>%s</comment>) in <comment>%s</comment>',
                        $packageName,
                        Path::makeRelative($installPath, $this->rootDir),
                        $env
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
                    'Installing <info>%s</info> (<comment>%s</comment>) in <comment>%s</comment>',
                    $packageName,
                    Path::makeRelative($installPath, $this->rootDir),
                    $env
                ));
            }

            try {
                $this->installPackage($installPath, $packageName, $env);
            } catch (PuliRunnerException $e) {
                $this->printPackageWarning($io, 'Could not install package "%s" (at ./%s)', $packageName, $installPath, $e);

                continue;
            }

            $puliPackages[$packageName] = new PuliPackage(
                $packageName,
                self::INSTALLER_NAME,
                $installPath,
                PuliPackage::STATE_ENABLED,
                $env
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

    private function checkForNotFoundErrors(array $puliPackages, IOInterface $io)
    {
        /** @var PuliPackage[] $notFoundPackages */
        $notFoundPackages = array_filter($puliPackages,
            function (PuliPackage $package) {
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
    }

    private function checkForNotLoadableErrors(array $puliPackages, IOInterface $io)
    {
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
        $constant .= sprintf("    define('PULI_FACTORY_CLASS', %s);\n", $escFactoryClass);
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

        $io->write(sprintf('<info>Registering %s with the class-map autoloader</info>', $factoryClass));

        $relFactoryFile = Path::makeRelative($factoryFile, $vendorDir);
        $escFactoryClass = var_export($factoryClass, true);
        $escFactoryFile = var_export('/'.$relFactoryFile, true);
        $classMap = sprintf("\n    %s => \$vendorDir . %s,", $escFactoryClass, $escFactoryFile);

        $contents = file_get_contents($classMapFile);

        // Regex modifiers:
        // "m": \s matches newlines
        // "D": $ matches at EOF only
        // Translation: insert before the last ");" in the file
        $contents = preg_replace('/\n(?=\);\s*$)/mD', "\n".$classMap, $contents);

        file_put_contents($classMapFile, $contents);
    }

    private function setBootstrapFile(IOInterface $io, $autoloadFile)
    {
        $bootstrapFile = $this->getConfigKey('bootstrap-file');

        // Don't change user-defined bootstrap files
        if (!empty($bootstrapFile)) {
            return;
        }

        $relAutoloadFile = Path::makeRelative($autoloadFile, $this->rootDir);

        $io->write(sprintf('<info>Setting "bootstrap-file" to "%s"</info>', $relAutoloadFile));

        $this->setConfigKey('bootstrap-file', $relAutoloadFile);
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
        $value = trim($this->puliRunner->run('config %key% --parsed', array(
            'key' => $key,
        )));

        switch ($value) {
            case 'null':
                return null;
            case 'true':
                return true;
            case 'false':
                return false;
            default:
                return $value;
        }
    }

    private function setConfigKey($key, $value)
    {
        $this->puliRunner->run('config %key% %value%', array(
            'key' => $key,
            'value' => $value,
        ));
    }

    /**
     * @return PuliPackage[]
     */
    private function loadPuliPackages()
    {
        $packages = array();

        $output = $this->puliRunner->run('package --list --format %format%', array(
            'format' => '%name%;%installer%;%install_path%;%state%;%env%',
        ));

        // PuliRunner replaces \r\n by \n for those Windows boxes
        foreach (explode("\n", $output) as $packageLine) {
            if (!$packageLine) {
                continue;
            }

            $packageParts = explode(';', $packageLine);

            $packages[$packageParts[0]] = new PuliPackage(
                $packageParts[0],
                $packageParts[1],
                $packageParts[2],
                $packageParts[3],
                $packageParts[4]
            );
        }

        return $packages;
    }

    private function installPackage($installPath, $packageName, $env)
    {
        $env = PuliPackage::ENV_DEV === $env ? ' --dev' : '';

        $this->puliRunner->run('package --install %path% %package_name% --installer %installer%'.$env, array(
            'path' => $installPath,
            'package_name' => $packageName,
            'installer' => self::INSTALLER_NAME,
        ));
    }

    private function removePackage($packageName)
    {
        $this->puliRunner->run('package --delete %package_name%', array(
            'package_name' => $packageName,
        ));
    }

    private function buildPuli(IOInterface $io)
    {
        $io->write('<info>Running "puli build"</info>');

        $this->puliRunner->run('build');
    }

    private function renamePackage($name, $newName)
    {
        $this->puliRunner->run('package --rename %old_name% %new_name%', array(
            'old_name' => $name,
            'new_name' => $newName,
        ));
    }

    /**
     * @param IOInterface    $io
     * @param                $message
     * @param Exception|null $exception
     */
    private function printWarning(IOInterface $io, $message, Exception $exception = null)
    {
        if (!$exception) {
            $reasonPhrase = '';
        } elseif ($io->isVerbose()) {
            $reasonPhrase = $exception instanceof PuliRunnerException
                ? $exception->getFullError()
                : $exception->getMessage()."\n\n".$exception->getTraceAsString();
        } else {
            $reasonPhrase = $exception instanceof PuliRunnerException
                ? $exception->getShortError()
                : $exception->getMessage();
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

    private function filterProdPackageNames(array $composerPackages, PackageInterface $package, array &$result = array())
    {
        // Resolve aliases
        if ($package instanceof AliasPackage) {
            $package = $package->getAliasOf();
        }

        // Package was processed already
        if (isset($result[$package->getName()])) {
            return $result;
        }

        $result[$package->getName()] = true;

        // Recursively filter package names
        foreach ($package->getRequires() as $packageName => $link) {
            if (isset($composerPackages[$packageName])) {
                $this->filterProdPackageNames($composerPackages, $composerPackages[$packageName], $result);
            }
        }

        return $result;
    }

    private function verifyPuliVersion()
    {
        $versionString = $this->puliRunner->run('-V');

        if (!preg_match('~\d+\.\d+\.\d+(-\w+)?~', $versionString, $matches)) {
            throw new RuntimeException(sprintf(
                'Could not determine Puli version. "puli -V" returned: %s',
                $versionString
            ));
        }

        if (version_compare($matches[0], self::MIN_CLI_VERSION, '<')) {
            throw new RuntimeException(sprintf(
                'Found an unsupported version of the Puli CLI: %s. Please '.
                'upgrade to version %s or higher. You can also install the '.
                'puli/cli dependency at version %s in your project.',
                $matches[0],
                self::MIN_CLI_VERSION,
                self::MIN_CLI_VERSION
            ));
        }

        if (version_compare($matches[0], self::MAX_CLI_VERSION, '>')) {
            throw new RuntimeException(sprintf(
                'Found an unsupported version of the Puli CLI: %s. Please '.
                'downgrade to a lower version than %s. You can also install '.
                'the puli/cli dependency in your project.',
                $matches[0],
                self::MAX_CLI_VERSION
            ));
        }
    }
}
