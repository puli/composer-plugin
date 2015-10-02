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
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use PHPUnit_Framework_MockObject_MockObject;
use PHPUnit_Framework_TestCase;
use Puli\ComposerPlugin\PuliPlugin;
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
 */
class PuliPluginTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var PuliPlugin
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

        $this->rootPackage->setRequires(array(
            'vendor/package1' => new Link('vendor/root', 'vendor/package1'),
            'vendor/package2' => new Link('vendor/root', 'vendor/package2'),
        ));

        $this->localRepository = new TestLocalRepository(array(
            new Package('vendor/package1', '1.0', '1.0'),
            new Package('vendor/package2', '1.0', '1.0'),
        ));

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

        $this->plugin = new PuliPlugin($this->puliRunner);
    }

    protected function tearDown()
    {
        chdir($this->previousWd);

        $filesystem = new Filesystem();
        $filesystem->remove($this->tempDir);
    }

    public function testActivate()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $dispatcher->expects($this->once())
            ->method('addSubscriber')
            ->with($this->plugin);

        $this->composer->setEventDispatcher($dispatcher);

        $this->plugin->activate($this->composer, $this->io);
    }

    public function getInstallEventNames()
    {
        return array(
            array(ScriptEvents::POST_INSTALL_CMD),
            array(ScriptEvents::POST_UPDATE_CMD),
        );
    }

    /**
     * @dataProvider getInstallEventNames
     */
    public function testInstallNewPuliPackages($eventName)
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey($eventName, $listeners);

        $listener = $listeners[$eventName];
        $event = new CommandEvent($eventName, $this->composer, $this->io);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for updated Puli packages</info>');
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
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package2',
                'package_name' => 'vendor/package2',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->$listener($event);
    }

    /**
     * @dataProvider getInstallEventNames
     * @depends testInstallNewPuliPackages
     */
    public function testEventListenersOnlyProcessedOnFirstCall($eventName)
    {
        // Execute normal test
        $this->testInstallNewPuliPackages($eventName);

        // Now fire again
        $event = new CommandEvent($eventName, $this->composer, $this->io);
        $listeners = PuliPlugin::getSubscribedEvents();
        $listener = $listeners[$eventName];

        $this->plugin->$listener($event);
    }

    public function testInstallNewPuliPackagesInDifferentEnvironments()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

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
            ->with('<info>Looking for updated Puli packages</info>');
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
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package2',
                'package_name' => 'vendor/package2',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer% --dev', array(
                'path' => $this->tempDir.'/package3',
                'package_name' => 'vendor/package3',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(4))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer% --dev', array(
                'path' => $this->tempDir.'/package4',
                'package_name' => 'vendor/package4',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(5))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    // meta packages have no install path

    public function testDoNotInstallPackagesWithoutInstallPath()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
        ));

        $this->installPaths['vendor/package1'] = '';

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testResolveAliasPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $package = new Package('vendor/package1', '1.0', '1.0');

        $this->localRepository->setPackages(array(
            // Package is not listed in installed packages
            new AliasPackage($package, '1.0', '1.0'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testInstallAliasedPackageOnlyOnce()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $package = new Package('vendor/package1', '1.0', '1.0');

        $this->localRepository->setPackages(array(
            // This time the package is returned here as well
            $package,
            new AliasPackage($package, '1.0', '1.0'),
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>vendor/package1</info> (<comment>package1</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testWarnIfInstallFails()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not install package "vendor/package1" (at ./package1): UnsupportedVersionException: Cannot read package file /home/bernhard/Entwicklung/Web/puli/cli/puli.json at version 5.0. The highest readable version is 1.0. Please upgrade Puli.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
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
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testWarnIfOverwritingPackagesInstalledByOtherInstaller()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        // User installed "vendor/package1"
        // Package added to Composer with different path, but Composer shouldn't
        // touch the existing package
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package2';

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
        ));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not install package "vendor/package1" (at ./package2): NameConflictException: A package with the name "vendor/package1" exists already.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;spock;{$this->tempDir}/package1;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
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
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testWarnIfPackageLoadingFails()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->installPaths['vendor/package1'] = $this->tempDir.'/not-loadable';

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not load Puli packages: FileNotFoundException: The file foobar does not exist.</warning>');

        $this->puliRunner->expects($this->once())
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

        $this->plugin->postInstall($event);
    }

    public function testWarnIfPackageInstalledByComposerNotLoadable()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: The package "vendor/package1" (at ./package1) could not be loaded.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;not-loadable;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testDoNotWarnIfPackageInstalledByUserNotLoadable()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;spock;{$this->tempDir}/package1;not-loadable;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testWarnIfPackageInstalledByComposerNotFoundInDevEnvironment()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io, true);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: The package "vendor/package1" (at ./package1) could not be found.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;not-found;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testDoNotWarnIfPackageInstalledByComposerNotFoundInProdEnvironment()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io, false);

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;not-found;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testDoNotWarnIfPackageInstalledByUserNotFound()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;spock;{$this->tempDir}/package1;not-found;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testReinstallPackagesWithInstallPathMovedToSubPath()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        // Package was moved to sub-path (PSR-0 -> PSR-4)
        // Such a package is not recognized as removed, because the path still
        // exists. Hence we need to explicitly reinstall it.
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1/sub/path';

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Reinstalling <info>vendor/package1</info> (<comment>package1/sub/path</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1/sub/path',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testReinstallPackagesWithInstallPathMovedToParentPath()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        // Package was moved to parent path (PSR-4 -> PSR-0)
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1';

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Reinstalling <info>vendor/package1</info> (<comment>package1</comment>) in <comment>prod</comment>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1/sub/path;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testReinstallPackagesWithChangedEnvironment()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

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
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer% --dev', array(
                'path' => $this->tempDir.'/package1',
                'package_name' => 'vendor/package1',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testDoNotReinstallExistingPuliPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --install %path% %package_name% --installer %installer%', array(
                'path' => $this->tempDir.'/package2',
                'package_name' => 'vendor/package2',
                'installer' => 'composer',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testWarnIfRemoveFailsDuringReinstall()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        // Package was moved to parent path (PSR-4 -> PSR-0)
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1';

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not remove package "vendor/package1" (at ./package1): Exception: The exception</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1/sub/path;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
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
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testWarnIfInstallFailsDuringReinstall()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        // Package was moved to parent path (PSR-4 -> PSR-0)
        $this->installPaths['vendor/package1'] = $this->tempDir.'/package1';

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not install package "vendor/package1" (at ./package1): Exception: The exception</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1/sub/path;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package1',
            ));
        $this->puliRunner->expects($this->at(2))
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
        $this->puliRunner->expects($this->at(3))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testRemoveRemovedPackagesInDevEnvironment()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io, true);

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            // no more package2
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Removing <info>vendor/package2</info> (<comment>package2</comment>)');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;not-found;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --delete %package_name%', array(
                'package_name' => 'vendor/package2',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testDoNotRemoveRemovedPackagesInProdEnvironment()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io, false);

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            // no more package2
        ));

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;not-found;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testDoNotRemovePackagesFromOtherInstaller()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io, true);

        $this->localRepository->setPackages(array());

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;spock;{$this->tempDir}/package1;not-found;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testWarnIfRemoveFails()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io, true);

        $this->localRepository->setPackages(array(
            new Package('vendor/package1', '1.0', '1.0'),
            // no more package2
        ));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Removing <info>vendor/package2</info> (<comment>package2</comment>)');

        $this->io->expects($this->exactly(2))
            ->method('writeError')
            ->withConsecutive(
                array('<warning>Warning: Could not remove package "vendor/package2" (at ./package2): Exception: The exception</warning>'),
                array('<warning>Warning: The package "vendor/package2" (at ./package2) could not be found.</warning>')
            );

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n".
                "vendor/package1;composer;{$this->tempDir}/package1;enabled;prod\n".
                "vendor/package2;composer;{$this->tempDir}/package2;not-found;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
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
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testCopyComposerPackageNameToPuli()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->localRepository->setPackages(array());

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/previous;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('package --rename %old_name% %new_name%', array(
                'old_name' => 'vendor/previous',
                'new_name' => 'vendor/root',
            ));
        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testDoNotCopyComposerPackageNameToPuliIfUnchanged()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->localRepository->setPackages(array());

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/root;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('build');

        $this->plugin->postInstall($event);
    }

    public function testWarnIfRenameFails()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->localRepository->setPackages(array());

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not rename root package to "vendor/root": Exception: Some exception.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('package --list --format %format%', array(
                'format' => '%name%;%installer%;%install_path%;%state%;%env%',
            ))
            ->willReturn(
                "vendor/previous;;{$this->tempDir};enabled;prod\n"
            );
        $this->puliRunner->expects($this->at(1))
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

        $this->plugin->postInstall($event);
    }

    public function testInsertFactoryClassIntoClassMap()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Generating PULI_FACTORY_CLASS constant</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Registering Puli\\MyFactory with the class-map autoloader</info>');

        $this->io->expects($this->never())
            ->method('writeError');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.class',
            ))
            ->willReturn("Puli\\MyFactory\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.file',
            ))
            ->willReturn("My/Factory.php\n");

        $this->plugin->$listener($event);

        $this->assertFileExists($this->tempDir.'/the-vendor/composer/autoload_classmap.php');

        $classMap = require $this->tempDir.'/the-vendor/composer/autoload_classmap.php';

        $this->assertInternalType('array', $classMap);
        $this->assertArrayHasKey('Puli\\MyFactory', $classMap);
        $this->assertSame($this->tempDir.'/My/Factory.php', Path::canonicalize($classMap['Puli\\MyFactory']));
    }

    public function testWarnIfFactoryClassCannotBeRead()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not load Puli configuration: Exception: Some exception.</warning>');

        $this->puliRunner->expects($this->once())
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

        $this->plugin->$listener($event);
    }

    public function testWarnIfFactoryFileCannotBeRead()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('<warning>Warning: Could not load Puli configuration: Exception: Some exception.</warning>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.class',
            ))
            ->willReturn("Puli\\MyFactory\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.file',
            ))
            ->willThrowException(new PuliRunnerException(
                "config 'factory.in.file' --parsed",
                1,
                'Exception: Some exception.',
                'Exception trace...'
            ));

        $this->plugin->$listener($event);
    }

    /**
     * @expectedException \Puli\ComposerPlugin\PuliPluginException
     * @expectedExceptionMessage autoload_classmap.php
     */
    public function testFailIfClassMapFileNotFound()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->never())
            ->method('writeError');

        unlink($this->tempDir.'/the-vendor/composer/autoload_classmap.php');

        $this->plugin->$listener($event);
    }

    public function testInsertFactoryConstantIntoAutoload()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Generating PULI_FACTORY_CLASS constant</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Registering Puli\\MyFactory with the class-map autoloader</info>');

        $this->puliRunner->expects($this->at(0))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.class',
            ))
            ->willReturn("Puli\\MyFactory\n");
        $this->puliRunner->expects($this->at(1))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'factory.in.file',
            ))
            ->willReturn("My/Factory.php\n");

        $this->plugin->$listener($event);

        $this->assertFileExists($this->tempDir.'/the-vendor/autoload.php');

        require $this->tempDir.'/the-vendor/autoload.php';

        $this->assertTrue(defined('PULI_FACTORY_CLASS'));
        $this->assertSame('Puli\\MyFactory', PULI_FACTORY_CLASS);
    }

    /**
     * @expectedException \Puli\ComposerPlugin\PuliPluginException
     * @expectedExceptionMessage autoload.php
     */
    public function testFailIfAutoloadFileNotFound()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->never())
            ->method('write');

        unlink($this->tempDir.'/the-vendor/autoload.php');

        $this->plugin->$listener($event);
    }

    public function testSetBootstrapFileToAutoloadFile()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Setting "bootstrap-file" to "the-vendor/autoload.php"</info>');

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

        $this->plugin->$listener($event);
    }

    public function testDoNotSetBootstrapFileIfAlreadySet()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->exactly(2))
            ->method('write');

        $this->puliRunner->expects($this->at(2))
            ->method('run')
            ->with('config %key% --parsed', array(
                'key' => 'bootstrap-file',
            ))
            ->willReturn("my/bootstrap-file.php\n");
        $this->puliRunner->expects($this->exactly(3))
            ->method('run');

        $this->plugin->$listener($event);
    }

    public function testRunPostAutoloadDumpOnlyOnce()
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey(ScriptEvents::POST_AUTOLOAD_DUMP, $listeners);

        $listener = $listeners[ScriptEvents::POST_AUTOLOAD_DUMP];
        $event = new CommandEvent(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->io->expects($this->exactly(3))
            ->method('write');

        $this->plugin->$listener($event);
        $this->plugin->$listener($event);
    }
}
