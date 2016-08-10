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
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Script\Event;
use Exception;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

/**
 * Implementation of the Puli plugin.
 *
 * This class is separate from the main {@link PuliPlugin} class so that it can
 * be loaded lazily after updating the sources of this package in the project
 * that requires the package.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPluginImpl
{
    /**
     * The version of the Puli plugin.
     */
    const VERSION = '@package_version@';

    /**
     * The minimum version of the Puli CLI.
     */
    const MIN_CLI_VERSION = '1.0.0-beta10';

    /**
     * The maximum version of the Puli CLI.
     */
    const MAX_CLI_VERSION = '1.999.99999';

    /**
     * The name of the installer.
     */
    const INSTALLER_NAME = 'composer';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var bool
     */
    private $isDev;

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
    private $runPreAutoloadDump = true;

    /**
     * @var bool
     */
    private $runPostAutoloadDump = true;

    /**
     * @var bool
     */
    private $runPostInstall = true;

    /**
     * @var bool
     */
    private $initialized = false;

    /**
     * @var string
     */
    private $autoloadFile;

    public function __construct(Event $event, PuliRunner $puliRunner = null)
    {
        $this->composer = $event->getComposer();
        $this->io = $event->getIO();
        $this->config = $this->composer->getConfig();
        $this->isDev = $event->isDevMode();
        $this->puliRunner = $puliRunner;
        $this->rootDir = Path::normalize(getcwd());

        $vendorDir = $this->config->get('vendor-dir');

        // On TravisCI, $vendorDir is a relative path. Probably an old Composer
        // build or something. Usually, $vendorDir should be absolute already.
        $vendorDir = Path::makeAbsolute($vendorDir, $this->rootDir);

        $this->autoloadFile = $vendorDir.'/autoload.php';
    }

    public function preAutoloadDump()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // This method is called twice. Run it only once.
        if (!$this->runPreAutoloadDump) {
            return;
        }

        $this->runPreAutoloadDump = false;

        try {
            $factoryClass = $this->getConfigKey('factory.in.class');
            $factoryFile = $this->getConfigKey('factory.in.file');
        } catch (PuliRunnerException $e) {
            $this->printWarning('Could not load Puli configuration', $e);

            return;
        }

        $factoryFile = Path::makeAbsolute($factoryFile, $this->rootDir);

        $autoload = $this->composer->getPackage()->getAutoload();
        $autoload['classmap'][] = $factoryFile;

        $this->composer->getPackage()->setAutoload($autoload);

        if (!file_exists($factoryFile)) {
            $filesystem = new Filesystem();
            // Let Composer find the factory class with a temporary stub

            $namespace = explode('\\', ltrim($factoryClass, '\\'));
            $className = array_pop($namespace);

            if (count($namespace)) {
                $stub = '<?php namespace '.implode('\\', $namespace).'; class '.$className.' {}';
            } else {
                $stub = '<?php class '.$className.' {}';
            }

            $filesystem->dumpFile($factoryFile, $stub);
        }
    }

    public function postAutoloadDump()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // This method is called twice. Run it only once.
        if (!$this->runPostAutoloadDump) {
            return;
        }

        $this->runPostAutoloadDump = false;

        try {
            $factoryClass = $this->getConfigKey('factory.in.class');
        } catch (PuliRunnerException $e) {
            $this->printWarning('Could not load Puli configuration', $e);

            return;
        }

        $this->insertFactoryClassConstant($this->autoloadFile, $factoryClass);
        $this->setBootstrapFile($this->autoloadFile);
    }

    /**
     * Updates the Puli repository after Composer installations/updates.
     */
    public function postInstall()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // This method is called twice. Run it only once.
        if (!$this->runPostInstall) {
            return;
        }

        $this->runPostInstall = false;

        $this->io->write('<info>Synchronizing Puli with Composer</info>');

        $rootPackage = $this->composer->getPackage();
        $composerPackages = $this->loadComposerPackages();
        $prodPackageNames = $this->filterProdPackageNames($composerPackages, $rootPackage);
        $env = $this->isDev ? PuliPackage::ENV_DEV : PuliPackage::ENV_PROD;

        try {
            $puliPackages = $this->loadPuliPackages();
        } catch (PuliRunnerException $e) {
            $this->printWarning('Could not load Puli packages', $e);

            return;
        }

        // Don't remove non-existing packages in production environment
        // Removed packages could be dev dependencies (i.e. "require-dev"
        // of the root package or "require" of another dev dependency), and
        // we can't find out whether they are since Composer doesn't load them
        if (PuliPackage::ENV_PROD !== $env) {
            $this->removeRemovedPackages($composerPackages, $puliPackages);
        }

        $this->installNewPackages($composerPackages, $prodPackageNames, $puliPackages);

        // Don't print warnings for non-existing packages in production
        if (PuliPackage::ENV_PROD !== $env) {
            $this->checkForNotFoundErrors($puliPackages);
        }

        $this->checkForNotLoadableErrors($puliPackages);
        $this->adoptComposerName($puliPackages);
        $this->removePuliDir();
        $this->buildPuli();
    }

    private function initialize()
    {
        if (!file_exists($this->autoloadFile)) {
            $filesystem = new Filesystem();
            // Avoid problems if using the runner before autoload.php has been
            // generated
            $filesystem->dumpFile($this->autoloadFile, '');
        }

        $this->initialized = true;

        // Keep the manually set runner
        if (null === $this->puliRunner) {
            try {
                // Add Composer's bin directory in case the "puli" executable is
                // installed with Composer
                $this->puliRunner = new PuliRunner($this->config->get('bin-dir'));
            } catch (RuntimeException $e) {
                $this->printWarning('Plugin initialization failed', $e);
                $this->runPreAutoloadDump = false;
                $this->runPostAutoloadDump = false;
                $this->runPostInstall = false;
            }
        }

        // Use the runner to verify if Puli has the right version
        try {
            $this->verifyPuliVersion();
        } catch (RuntimeException $e) {
            $this->printWarning('Version check failed', $e);
            $this->runPreAutoloadDump = false;
            $this->runPostAutoloadDump = false;
            $this->runPostInstall = false;
        }
    }

    /**
     * @param PackageInterface[] $composerPackages
     * @param bool[]             $prodPackageNames
     * @param PuliPackage[]      $puliPackages
     */
    private function installNewPackages(array $composerPackages, array $prodPackageNames, array &$puliPackages)
    {
        $installationManager = $this->composer->getInstallationManager();

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
                    $this->io->write(sprintf(
                        'Reinstalling <info>%s</info> (<comment>%s</comment>) in <comment>%s</comment>',
                        $packageName,
                        Path::makeRelative($installPath, $this->rootDir),
                        $env
                    ));

                    try {
                        $this->removePackage($packageName);
                    } catch (PuliRunnerException $e) {
                        $this->printPackageWarning('Could not remove package "%s" (at "%s")', $packageName, $installPath, $e);

                        continue;
                    }
                }
            } else {
                $this->io->write(sprintf(
                    'Installing <info>%s</info> (<comment>%s</comment>) in <comment>%s</comment>',
                    $packageName,
                    Path::makeRelative($installPath, $this->rootDir),
                    $env
                ));
            }

            try {
                $this->installPackage($installPath, $packageName, $env);
            } catch (PuliRunnerException $e) {
                $this->printPackageWarning('Could not install package "%s" (at "%s")', $packageName, $installPath, $e);

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
     */
    private function removeRemovedPackages(array $composerPackages, array &$puliPackages)
    {
        /** @var PuliPackage[] $notFoundPackages */
        $notFoundPackages = array_filter($puliPackages, function (PuliPackage $package) {
            return PuliPackage::STATE_NOT_FOUND === $package->getState()
                && PuliPluginImpl::INSTALLER_NAME === $package->getInstallerName();
        });

        foreach ($notFoundPackages as $packageName => $package) {
            // Check whether package was only moved
            if (isset($composerPackages[$packageName])) {
                continue;
            }

            $this->io->write(sprintf(
                'Removing <info>%s</info> (<comment>%s</comment>)',
                $packageName,
                Path::makeRelative($package->getInstallPath(), $this->rootDir)
            ));

            try {
                $this->removePackage($packageName);
            } catch (PuliRunnerException $e) {
                $this->printPackageWarning('Could not remove package "%s" (at "%s")', $packageName, $package->getInstallPath(), $e);

                continue;
            }

            unset($puliPackages[$packageName]);
        }
    }

    private function checkForNotFoundErrors(array $puliPackages)
    {
        /** @var PuliPackage[] $notFoundPackages */
        $notFoundPackages = array_filter($puliPackages,
            function (PuliPackage $package) {
                return PuliPackage::STATE_NOT_FOUND === $package->getState()
                && PuliPluginImpl::INSTALLER_NAME === $package->getInstallerName();
            });

        foreach ($notFoundPackages as $package) {
            $this->printPackageWarning(
                'The package "%s" (at "%s") could not be found',
                $package->getName(),
                $package->getInstallPath()
            );
        }
    }

    private function checkForNotLoadableErrors(array $puliPackages)
    {
        /** @var PuliPackage[] $notLoadablePackages */
        $notLoadablePackages = array_filter($puliPackages, function (PuliPackage $package) {
            return PuliPackage::STATE_NOT_LOADABLE === $package->getState()
                && PuliPluginImpl::INSTALLER_NAME === $package->getInstallerName();
        });

        foreach ($notLoadablePackages as $package) {
            $this->printPackageWarning(
                'The package "%s" (at "%s") could not be loaded',
                $package->getName(),
                $package->getInstallPath()
            );
        }
    }

    private function adoptComposerName(array $puliPackages)
    {
        $rootDir = $this->rootDir;

        /** @var PuliPackage[] $rootPackages */
        $rootPackages = array_filter($puliPackages, function (PuliPackage $package) use ($rootDir) {
            return !$package->getInstallerName() && $rootDir === $package->getInstallPath();
        });

        if (0 === count($rootPackages)) {
            // This should never happen
            $this->printWarning('No root package could be found');

            return;
        }

        if (count($rootPackages) > 1) {
            // This should never happen
            $this->printWarning('More than one root package was found');

            return;
        }

        /** @var PuliPackage $rootPackage */
        $rootPackage = reset($rootPackages);
        $name = $rootPackage->getName();
        $newName = $this->composer->getPackage()->getName();

        // Rename the root package after changing the name in composer.json
        if ($name !== $newName) {
            try {
                $this->renamePackage($name, $newName);
            } catch (PuliRunnerException $e) {
                $this->printWarning(sprintf(
                    'Could not rename root package to "%s"',
                    $newName
                ), $e);
            }
        }
    }

    private function insertFactoryClassConstant($autoloadFile, $factoryClass)
    {
        if (!file_exists($autoloadFile)) {
            throw new PuliPluginException(sprintf(
                'Could not adjust autoloader: The file %s was not found.',
                $autoloadFile
            ));
        }

        $this->io->write('<info>Generating the "PULI_FACTORY_CLASS" constant</info>');

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

    private function setBootstrapFile($autoloadFile)
    {
        $bootstrapFile = $this->getConfigKey('bootstrap-file');

        // Don't change user-defined bootstrap files
        if (!empty($bootstrapFile)) {
            return;
        }

        $relAutoloadFile = Path::makeRelative($autoloadFile, $this->rootDir);

        $this->io->write(sprintf('<info>Setting "bootstrap-file" to "%s"</info>', $relAutoloadFile));

        $this->setConfigKey('bootstrap-file', $relAutoloadFile);
    }

    /**
     * Loads Composer's currently installed packages.
     *
     * @return PackageInterface[] The installed packages indexed by their names.
     */
    private function loadComposerPackages()
    {
        $repository = $this->composer->getRepositoryManager()->getLocalRepository();
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

    private function removePuliDir()
    {
        $relativePuliDir = rtrim($this->getConfigKey('puli-dir'), '/');

        $puliDir = Path::makeAbsolute($relativePuliDir, $this->rootDir);

        // Only remove existing sub-directories of the root directory
        if (!file_exists($puliDir) || 0 !== strpos($puliDir, $this->rootDir.'/')) {
            return;
        }

        $this->io->write(sprintf('<info>Deleting the "%s" directory</info>', $relativePuliDir));

        // Remove the .puli directory to prevent upgrade problems
        $filesystem = new Filesystem();
        $filesystem->remove($puliDir);
    }

    private function buildPuli()
    {
        $this->io->write('<info>Running "puli build"</info>');

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
     * @param                $message
     * @param Exception|null $exception
     */
    private function printWarning($message, Exception $exception = null)
    {
        if (!$exception) {
            $reasonPhrase = '';
        } elseif ($this->io->isVerbose()) {
            $reasonPhrase = $exception instanceof PuliRunnerException
                ? $exception->getFullError()
                : $exception->getMessage()."\n\n".$exception->getTraceAsString();
        } else {
            $reasonPhrase = $exception instanceof PuliRunnerException
                ? $exception->getShortError()
                : $exception->getMessage();
        }

        $this->io->writeError(sprintf(
            '<warning>Warning: %s%s</warning>',
            $message,
            $reasonPhrase ? ': '.$reasonPhrase : '.'
        ));
    }

    private function printPackageWarning($message, $packageName, $installPath, PuliRunnerException $exception = null)
    {
        $this->printWarning(sprintf(
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

        if (!preg_match('~^Puli version (\S+)$~', $versionString, $matches)) {
            throw new RuntimeException(sprintf(
                'Could not determine Puli version. "puli -V" returned: %s',
                $versionString
            ));
        }

        // the development build of the plugin is always considered compatible
        // with the development build of the CLI
        // Split strings to prevent replacement during release
        if ('@package_'.'version@' === self::VERSION && '@package_'.'version@' === $matches[1]) {
            return;
        }

        if (version_compare($matches[1], self::MIN_CLI_VERSION, '<')) {
            throw new RuntimeException(sprintf(
                'Found an unsupported version of the Puli CLI: %s. Please '.
                'upgrade to version %s or higher. You can also install the '.
                'puli/cli dependency at version %s in your project.',
                $matches[1],
                self::MIN_CLI_VERSION,
                self::MIN_CLI_VERSION
            ));
        }

        if (version_compare($matches[1], self::MAX_CLI_VERSION, '>')) {
            throw new RuntimeException(sprintf(
                'Found an unsupported version of the Puli CLI: %s. Please '.
                'downgrade to a lower version than %s. You can also install '.
                'the puli/cli dependency in your project.',
                $matches[1],
                self::MAX_CLI_VERSION
            ));
        }
    }
}
