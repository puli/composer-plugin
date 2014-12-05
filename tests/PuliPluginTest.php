<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\RepositoryManager;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use Puli\Extension\Composer\PuliPlugin;
use Puli\Extension\Composer\Tests\Fixtures\TestLocalRepository;
use Puli\RepositoryManager\Package\PackageFile\Reader\PackageJsonReader;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPluginTest extends \PHPUnit_Framework_TestCase
{
    const PLUGIN_CLASS = 'Puli\Extension\Composer\ComposerPlugin';

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $dumper;

    /**
     * @var PuliPlugin
     */
    private $plugin;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|IOInterface
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
     * @var \PHPUnit_Framework_MockObject_MockObject|InstallationManager
     */
    private $installationManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var RootPackage
     */
    private $rootPackage;

    private $tempDir;

    private $tempHome;

    private $previousWd;

    protected function setUp()
    {
        while (false === mkdir($this->tempDir = sys_get_temp_dir().'/puli-plugin/PuliPluginTest_root'.rand(10000, 99999), 0777, true)) {}
        while (false === mkdir($this->tempHome = sys_get_temp_dir().'/puli-plugin/PuliPluginTest_home'.rand(10000, 99999), 0777, true)) {}

        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/Fixtures/root', $this->tempDir);
        $filesystem->mirror(__DIR__.'/Fixtures/home', $this->tempHome);

        $this->dumper = $this->getMockBuilder('Puli\Extension\Composer\RepositoryDumper\RepositoryDumper')
            ->disableOriginalConstructor()
            ->getMock();

        $this->plugin = new PuliPlugin($this->dumper);
        $this->io = $this->getMock('Composer\IO\IOInterface');
        $this->config = new Config();

        $this->installationManager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        $tempDir = $this->tempDir;
        $this->installationManager->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function (Package $package) use ($tempDir) {
                return $tempDir.'/'.$package->getName();
            }));

        $this->rootPackage = $this->getMock('Composer\Package\RootPackageInterface');

        $this->localRepository = new TestLocalRepository(array(
            new Package('package1', '1.0', '1.0'),
            new Package('package2', '1.0', '1.0'),
        ));

        $this->repositoryManager = new RepositoryManager($this->io, $this->config);
        $this->repositoryManager->setLocalRepository($this->localRepository);

        $this->composer = new Composer();
        $this->composer->setRepositoryManager($this->repositoryManager);
        $this->composer->setInstallationManager($this->installationManager);
        $this->composer->setConfig($this->config);
        $this->composer->setPackage($this->rootPackage);

        $this->previousWd = getcwd();

        chdir($this->tempDir);
        putenv('PULI_HOME='.$this->tempHome);
    }

    protected function tearDown()
    {
        chdir($this->previousWd);
        putenv('PULI_HOME');

        $filesystem = new Filesystem();
        $filesystem->remove($this->tempDir);
        $filesystem->remove($this->tempHome);
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

    public function provideEventNames()
    {
        return array(
            array(ScriptEvents::POST_INSTALL_CMD),
            array(ScriptEvents::POST_UPDATE_CMD),
        );
    }

    /**
     * @dataProvider provideEventNames
     */
    public function testInstallNewPuliPackages($eventName)
    {
        $listeners = $this->plugin->getSubscribedEvents();

        $this->assertArrayHasKey($eventName, $listeners);

        $listener = $listeners[$eventName];
        $event = new CommandEvent($eventName, $this->composer, $this->io);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for removed Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('Installing <info>package2</info> (<comment>package2</comment>)');
        $this->io->expects($this->at(4))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->$listener($event);

        $this->assertFileExists($this->tempDir.'/resource-repository.php');

        $repo = include $this->tempDir.'/resource-repository.php';

        $this->assertInstanceOf('Puli\Repository\ResourceRepositoryInterface', $repo);
        $this->assertSame($this->tempDir.'/res/file', $repo->get('/root/file')->getLocalPath());

        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            $this->assertFileEquals($this->tempDir.'/packages-all-installed.json', $this->tempDir.'/packages.json');
        } else {
            $this->assertFileEquals($this->tempDir.'/packages-all-installed-ugly.json', $this->tempDir.'/packages.json');
        }
    }

    /**
     * @dataProvider provideEventNames
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

    public function testDoNotReinstallExistingPuliPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy($this->tempDir.'/packages-partially-installed.json', $this->tempDir.'/packages.json');

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for removed Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('Installing <info>package2</info> (<comment>package2</comment>)');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->postInstall($event);
    }

    public function testResolveAliasPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $package = new Package('package1', '1.0', '1.0');

        $this->localRepository->setPackages(array(
            // Package is not listed in installed packages
            new AliasPackage($package, '1.0', '1.0'),
        ));

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for removed Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->postInstall($event);
    }

    public function testInstallAliasedPackageOnlyOnce()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $package = new Package('package1', '1.0', '1.0');

        $this->localRepository->setPackages(array(
            // This time the package is returned here as well
            $package,
            new AliasPackage($package, '1.0', '1.0'),
        ));

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for removed Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->postInstall($event);
    }

    public function testRemoveRemovedPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy($this->tempDir.'/packages-partially-installed.json', $this->tempDir.'/packages.json');

        $this->localRepository->setPackages(array());

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for removed Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Removing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->postInstall($event);

        $this->assertFileExists($this->tempDir.'/resource-repository.php');

        $repo = include $this->tempDir.'/resource-repository.php';

        $this->assertInstanceOf('Puli\Repository\ResourceRepositoryInterface', $repo);
        $this->assertSame($this->tempDir.'/res/file', $repo->get('/root/file')->getLocalPath());
    }

    public function testDoNotRemovePackagesFromOtherInstaller()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy($this->tempDir.'/packages-other-installer.json', $this->tempDir.'/packages.json');

        $this->localRepository->setPackages(array());

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for removed Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->postInstall($event);

        $this->assertFileExists($this->tempDir.'/resource-repository.php');

        $repo = include $this->tempDir.'/resource-repository.php';

        $this->assertInstanceOf('Puli\Repository\ResourceRepositoryInterface', $repo);
        $this->assertSame($this->tempDir.'/res/file', $repo->get('/root/file')->getLocalPath());
    }
}
