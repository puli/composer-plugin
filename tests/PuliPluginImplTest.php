<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use PHPUnit_Framework_Assert;
use PHPUnit_Framework_MockObject_MockObject;
use PHPUnit_Framework_TestCase;
use Puli\ComposerPlugin\PuliPluginImpl;
use Puli\ComposerPlugin\PuliRunner;
use Puli\ComposerPlugin\PuliRunnerException;
use Puli\ComposerPlugin\Tests\Fixtures\TestLocalRepository;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\Glob\Test\TestUtil;
use Webmozart\PathUtil\Path;

/**
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @runTestsInSeparateProcesses
 */
class PuliPluginImplTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var PuliPluginImpl
     */
    private $plugin;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|IOInterface
     */
    private $io;

    /**
     * @var TestLocalRepository
     */
    private $localRepository;

    /**
     * @var RepositoryManager
     */
    private $repositoryManager;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|InstallationManager
     */
    private $installationManager;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|PuliRunner
     */
    private $puliRunner;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var RootPackage
     */
    private $rootPackage;

    private $tempDir;

    private $previousWd;

    private $installPaths;

    public function getInstallPath(Package $package)
    {
        if (isset($this->installPaths[$package->getName()])) {
            return $this->installPaths[$package->getName()];
        }

        return $this->tempDir.'/'.basename($package->getName());
    }

    protected function setUp()
    {
        $this->tempDir = TestUtil::makeTempDir('puli-composer-plugin', __CLASS__);

        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/Fixtures/root', $this->tempDir);

        $this->io = $this->getMock('Composer\IO\IOInterface');
        $this->config = new Config(false, $this->tempDir);
        $this->config->merge(array('config' => array('vendor-dir' => 'the-vendor')));

        $this->installationManager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->installationManager->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(array($this, 'getInstallPath')));

        $this->rootPackage = new RootPackage('vendor/root', '1.0', '1.0');

        $this->localRepository = new TestLocalRepository();

        $this->repositoryManager = new RepositoryManager($this->io, $this->config);
        $this->repositoryManager->setLocalRepository($this->localRepository);
        $this->installPaths = array();

        $this->composer = new Composer();
        $this->composer->setRepositoryManager($this->repositoryManager);
        $this->composer->setInstallationManager($this->installationManager);
        $this->composer->setConfig($this->config);
        $this->composer->setPackage($this->rootPackage);

        $this->puliRunner = $this->getMockBuilder('Puli\ComposerPlugin\PuliRunner')
            ->disableOriginalConstructor()
            ->getMock();

        $this->previousWd = getcwd();

        chdir($this->tempDir);

        $this->plugin = new PuliPluginImpl(
            new Event('event-name', $this->composer, $this->io),
            $this->puliRunner
        );
    }

    protected function tearDown()
    {
        chdir($this->previousWd);

        $filesystem = new Filesystem();
        $filesystem->remove($this->tempDir);
    }

    public function testInstallNewPuliPackages()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Synchronizing Puli with Composer</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>) in <comment>prod</comment>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('Installing <info>vendor/package2</info> (<comment>package2</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package2',
                'package_name' => 'vendor/package2',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(5))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    /**
     * @depends testInstallNewPuliPackages
     */
    public function testRunPostInstallOnlyOnce()
    {
        $this->io->expects($this->exactly(2))
            ->method('write');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->exactly(4))
            ->method('run');

        $this->plugin->postInstall();
        $this->plugin->postInstall();
    }

    public function testAbortWithWarningIfVersionTooLow()
    {
        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Version check failed: Found an unsupported version of the Puli CLI: 1.0.0-beta8. Please upgrade to version 1.0.0-beta10 or higher. You can also install the puli/cli dependency at version 1.0.0-beta10 in your project.</warning>');

        $this->puliRunner->expects($this->once())
            ->method('run')
            ->with('-V')
            ->willReturn("Puli version 1.0.0-beta8\n");

        $this->plugin->postInstall();
    }

    public function testAbortWithWarningIfVersionTooHigh()
    {
        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Version check failed: Found an unsupported version of the Puli CLI: 2.0.0-alpha1. Please downgrade to a lower version than 1.999.99999. You can also install the puli/cli dependency in your project.</warning>');

        $this->puliRunner->expects($this->once())
            ->method('run')
            ->with('-V')
            ->willReturn("Puli version 2.0.0-alpha1\n");

        $this->plugin->postInstall();
    }

    public function testContinueIfDevelopmentVersion()
    {
        if ('@package_'.'version@' !== PuliPluginImpl::VERSION) {
            $this->markTestSkipped('Only run for development version of Puli');
        }

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version @package_'."version@\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );

        $this->puliRunner->expects($this->atLeast(3))
            ->method('run');

        $this->plugin->postInstall();
    }

    public function testInstallNewPuliPackagesInDifferentEnvironments()
    {
        $this->localRepository->setPackages(array(
            $package1 = new Package('vendor/package1', '1.0', '1.0'),
            $package2 = new Package('vendor/package2', '1.0', '1.0'),
            $package3 = new Package('vendor/package3', '1.0', '1.0'),
            $package4 = new Package('vendor/package4', '1.0', '1.0'),
        ));

        // Check whether package is in "require"
        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        // Recursively resolve all "require" packages
        $package1->setRequires(array(
            'vendor/package2' => new Link('vendor/package1', 'vendor/package2'),
        ));

        // Ignore "require" blocks of "require-dev" dependencies
        $package3->setRequires(array(
            'vendor/package4' => new Link('vendor/package3', 'vendor/package4'),
        ));

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Synchronizing Puli with Composer</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>) in <comment>prod</comment>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('Installing <info>vendor/package2</info> (<comment>package2</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package2',
                'package_name' => 'vendor/package2',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer% --dev', array(
                'path' => $this->tempDir.'/package3',
                'package_name' => 'vendor/package3',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(5))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer% --dev', array(
                'path' => $this->tempDir.'/package4',
                'package_name' => 'vendor/package4',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(6))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(7))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    // meta packages have no install path

    public function testDoNotInstallPackagesWithoutInstallPath()
    {
        $this->installPaths['vendor/package1'] = '';

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testResolveAliasPackages()
    {
        $package = new Package('vendor/package1', '1.0', '1.0');

        $this->localRepository->setPackages(array(
            // Package is not listed in installed packages
            new AliasPackage($package, '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testInstallAliasedPackageOnlyOnce()
    {
        $package = new Package('vendor/package1', '1.0', '1.0');

        $this->localRepository->setPackages(array(
            // This time the package is returned here as well
            $package,
            new AliasPackage($package, '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testWarnIfInstallFails()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not install package "vendor/package1" (at "package1"): UnsupportedVersionException: Cannot read package file /home/bernhard/Entwicklung/Web/puli/cli/puli.json at version 5.0. The highest readable version is 1.0. Please upgrade Puli.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ))
            ->willThrowException(new PuliRunnerException(
                "/path/to/php /path/to/puli.phar package --install '{$this->tempDir}/package1' 'vendor/package1' --installer 'composer'",
                1,
                'UnsupportedVersionException: Cannot read package file /home/bernhard/Entwicklung/Web/puli/cli/puli.json at version 5.0. The highest readable version is 1.0. Please upgrade Puli.',
                'Exception trace...'
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testWarnIfOverwritingPackagesInstalledByOtherInstaller()
    {
        // User installed "vendor/package1"
        // Package added to Composer with different path, but Composer shouldn't
        // touch the existing package
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package2';

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not install package "vendor/package1" (at "package2"): NameConflictException: A package with the name "vendor/package1" exists already.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;spock;{$this->tempDir}/package1;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package2',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ))
            ->willThrowException(new PuliRunnerException(
                "/path/to/php /path/to/puli.phar package --install '{$this->tempDir}/package2' 'vendor/package1' --installer 'composer'",
                1,
                'NameConflictException: A package with the name "vendor/package1" exists already.',
                'Exception trace...'
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testWarnIfPackageLoadingFails()
    {
        $this->installPaths['vendor/package1'] = $this->tempDir.'/not-loadable';

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not load Puli packages: FileNotFoundException: The file foobar does not exist.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willThrowException(new PuliRunnerException(
                'package --list --format "%name%;%installer%;%install_path%;%state%;%env%"',
                1,
                'FileNotFoundException: The file foobar does not exist.',
                'Exception trace...'
            ));

        $this->plugin->postInstall();
    }

    public function testWarnIfPackageInstalledByComposerNotLoadable()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: The package "vendor/package1" (at "package1") could not be loaded.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;not-loadable;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testDoNotWarnIfPackageInstalledByUserNotLoadable()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;spock;{$this->tempDir}/package1;not-loadable;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testWarnIfPackageInstalledByComposerNotFoundInDevEnvironment()
    {
        $this->plugin = new PuliPluginImpl(
            new Event('event-name', $this->composer, $this->io, true),
            $this->puliRunner
        );

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: The package "vendor/package1" (at "package1") could not be found.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;not-found;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testDoNotWarnIfPackageInstalledByComposerNotFoundInProdEnvironment()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;not-found;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testDoNotWarnIfPackageInstalledByUserNotFound()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;spock;{$this->tempDir}/package1;not-found;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testReinstallPackagesWithInstallPathMovedToSubPath()
    {
        // Package was moved to sub-path (PSR-0 -> PSR-4)
        // Such a package is not recognized as removed, because the path still
        // exists. Hence we need to explicitly reinstall it.
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1/sub/path';

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Reinstalling <info>vendor/package1</info> (<comment>package1/sub/path</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1/sub/path',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(5))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testReinstallPackagesWithInstallPathMovedToParentPath()
    {
        // Package was moved to parent path (PSR-4 -> PSR-0)
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1';

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Reinstalling <info>vendor/package1</info> (<comment>package1</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1/sub/path;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(5))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testReinstallPackagesWithChangedEnvironment()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Reinstalling <info>vendor/package1</info> (<comment>package1</comment>) in <comment>dev</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer% --dev', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(5))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testDoNotReinstallExistingPuliPackages()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package2',
                'package_name' => 'vendor/package2',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testWarnIfRemoveFailsDuringReinstall()
    {
        // Package was moved to parent path (PSR-4 -> PSR-0)
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1';

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not remove package "vendor/package1" (at "package1"): Exception: The exception</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1/sub/path;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ))
            ->willThrowException(new PuliRunnerException(
                "package --delete 'vendor/package1'",
                1,
                'Exception: The exception',
                'Exception trace...'
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testWarnIfInstallFailsDuringReinstall()
    {
        // Package was moved to parent path (PSR-4 -> PSR-0)
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1';

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not install package "vendor/package1" (at "package1"): Exception: The exception</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1/sub/path;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ))
            ->willThrowException(new PuliRunnerException(
                "package --install '{$this->tempDir}/package1' 'vendor/package1' --installer 'composer'",
                1,
                'Exception: The exception',
                'Exception trace...'
            ));
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(5))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testRemoveRemovedPackagesInDevEnvironment()
    {
        $this->plugin = new PuliPluginImpl(
            new Event('event-name', $this->composer, $this->io, true),
            $this->puliRunner
        );

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            // no more package2
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Removing <info>vendor/package2</info> (<comment>package2</comment>)');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;not-found;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package2',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testDoNotRemoveRemovedPackagesInProdEnvironment()
    {
        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            // no more package2
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;not-found;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testDoNotRemovePackagesFromOtherInstaller()
    {
        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;spock;{$this->tempDir}/package1;not-found;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testWarnIfRemoveFails()
    {
        $this->plugin = new PuliPluginImpl(
            new Event('event-name', $this->composer, $this->io, true),
            $this->puliRunner
        );

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            // no more package2
        ));

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Removing <info>vendor/package2</info> (<comment>package2</comment>)');

        $this->io->expects($this->exactly(2))
            ->method('writeError')
            ->withConsecutive(
                array('<warning>Warning: Could not remove package "vendor/package2" (at "package2"): Exception: The exception</warning>'),
                array('<warning>Warning: The package "vendor/package2" (at "package2") could not be found.</warning>')
            );

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;not-found;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package2',
            ))
            ->willThrowException(new PuliRunnerException(
                "package --delete 'vendor/package2'",
                1,
                'Exception: The exception',
                'Exception trace...'
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testCopyComposerPackageNameToPuli()
    {
        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/previous;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --rename %old_name% %new_name%', array(
                'old_name' => 'vendor/previous',
                'new_name' => 'vendor/root',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testDoNotCopyComposerPackageNameToPuliIfUnchanged()
    {
        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall();
    }

    public function testWarnIfRenameFails()
    {
        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not rename root package to "vendor/root": Exception: Some exception.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/previous;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --rename %old_name% %new_name%', array(
                'old_name' => 'vendor/previous',
                'new_name' => 'vendor/root',
            ))
            ->willThrowException(new PuliRunnerException(
                "package --rename 'vendor/previous' 'vendor/root'",
                1,
                'Exception: Some exception.',
                'Exception trace...'
            ));

        $this->plugin->postInstall();
    }

    public function testRemovePuliDirBeforeBuildingIfExists()
    {
        $rootDir = $this->tempDir;
        $puliDir = $this->tempDir.'/.puli';
        $filesystem = new Filesystem();
        $filesystem->mkdir($puliDir);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Synchronizing Puli with Composer</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Deleting the ".puli" directory</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Running "puli build"</info>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build')
            ->willReturnCallback(function () use ($rootDir, $puliDir) {
                PHPUnit_Framework_Assert::assertFileExists($rootDir);
                PHPUnit_Framework_Assert::assertFileNotExists($puliDir);
            });

        $this->plugin->postInstall();
    }

    public function testDoNotRemovePuliDirBeforeBuildingIfNoneExists()
    {
        $rootDir = $this->tempDir;

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Synchronizing Puli with Composer</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Running "puli build"</info>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.puli');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build')
            ->willReturnCallback(function () use ($rootDir) {
                PHPUnit_Framework_Assert::assertFileExists($rootDir);
            });

        $this->plugin->postInstall();
    }

    public function testDoNotRemovePuliDirIfEqualToRootDirectory()
    {
        $rootDir = $this->tempDir;

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Synchronizing Puli with Composer</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Running "puli build"</info>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('.');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build')
            ->willReturnCallback(function () use ($rootDir) {
                PHPUnit_Framework_Assert::assertFileExists($rootDir);
            });

        $this->plugin->postInstall();
    }

    public function testDoNotRemovePuliDirIfParentOfRootDirectory()
    {
        $rootDir = $this->tempDir;

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Synchronizing Puli with Composer</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Running "puli build"</info>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'puli-dir',
            ))
            ->willReturn('../..');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build')
            ->willReturnCallback(function () use ($rootDir) {
                PHPUnit_Framework_Assert::assertFileExists($rootDir);
            });

        $this->plugin->postInstall();
    }

    public function testInsertFactoryClassIntoClassMap()
    {
        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");

        $this->rootPackage->setAutoload(array('classmap' => array('src')));

        $this->plugin->preAutoloadDump();

        $this->assertSame(array('classmap' => array('src', $this->tempDir)), $this->rootPackage->getAutoload());
    }

    public function testCreatesStubFactoryClassWithNamespaceIfDoesNotExist()
    {
        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.class',
            ))
            ->willReturn("Puli\\MyFactory\n");
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.file',
            ))
            ->willReturn(".puli/factory.class\n");

        $this->plugin->preAutoloadDump();

        $this->assertStringEqualsFile($this->tempDir.'/.puli/factory.class', '<?php namespace Puli; class MyFactory {}');
    }

    public function testCreatesStubFactoryClassWithoutNamespaceIfDoesNotExist()
    {
        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.class',
            ))
            ->willReturn("MyFactory\n");
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.file',
            ))
            ->willReturn(".puli/factory.class\n");

        $this->plugin->preAutoloadDump();

        $this->assertStringEqualsFile($this->tempDir.'/.puli/factory.class', '<?php class MyFactory {}');
    }

    public function testWarnIfFactoryClassCannotBeRead()
    {
        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not load Puli configuration: Exception: Some exception.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.class',
            ))
            ->willThrowException(new PuliRunnerException(
                "config 'factory.in.class' --parsed",
                1,
                'Exception: Some exception.',
                'Exception trace...'
            ));

        $this->plugin->postAutoloadDump();
    }

    public function testInsertFactoryConstantIntoAutoload()
    {
        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Generating the "PULI_FACTORY_CLASS" constant</info>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.class',
            ))
            ->willReturn("Puli\\MyFactory\n");

        $this->plugin->postAutoloadDump();

        $this->assertFileExists($this->tempDir.'/the-vendor/autoload.php');

        require $this->tempDir.'/the-vendor/autoload.php';

        $this->assertTrue(defined('PULI_FACTORY_CLASS'));
        $this->assertSame('Puli\\MyFactory', PULI_FACTORY_CLASS);
    }

    public function testSetBootstrapFileToAutoloadFile()
    {
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Setting "bootstrap-file" to "the-vendor/autoload.php"</info>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'bootstrap-file',
            ))
            ->willReturn('null');
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('config %key% %value%', array(
                'key' => 'bootstrap-file',
                'value' => 'the-vendor/autoload.php',
            ));

        $this->plugin->postAutoloadDump();
    }

    public function testDoNotSetBootstrapFileIfAlreadySet()
    {
        $this->io->expects($this->exactly(1))
            ->method('write');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'bootstrap-file',
            ))
            ->willReturn("my/bootstrap-file.php\n");
        $this->puliRunner->expects($this->exactly(3))
            ->method('run');

        $this->plugin->postAutoloadDump();
    }

    public function testRunPostAutoloadDumpOnlyOnce()
    {
        $this->io->expects($this->exactly(2))
            ->method('write');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('-V')
            ->willReturn('Puli version '.PuliPluginImpl::MIN_CLI_VERSION."\n");

        $this->plugin->postAutoloadDump();
        $this->plugin->postAutoloadDump();
    }
}
