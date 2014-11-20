<?php

/*
 * This file is part of the Puli Composer Plugin.
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
use Composer\Package\AliasPackage;
use Composer\Package\Package;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use Puli\Extension\Composer\PuliPlugin;
use Puli\PackageManager\Config\Reader\ConfigJsonReader;
use Puli\PackageManager\PackageManager;
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
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $io;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|WritableRepositoryInterface
     */
    private $localRepository;

    private $repositoryManager;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|InstallationManager
     */
    private $installationManager;

    /**
     * @var Config
     */
    private $config;

    private $projectPackage;

    private $installedPackages;

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

        $this->localRepository = $this->getMock('Composer\Repository\WritableRepositoryInterface');
        $this->repositoryManager = new RepositoryManager($this->io, $this->config);
        $this->repositoryManager->setLocalRepository($this->localRepository);

        $this->installationManager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        $tempDir = $this->tempDir;
        $this->installationManager->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function (Package $package) use ($tempDir) {
                return $tempDir.'/'.$package->getName();
            }));

        $this->projectPackage = $this->getMock('Composer\Package\RootPackageInterface');
        $this->installedPackages = array(
            new Package('package1', '1.0', '1.0'),
            new Package('package2', '1.0', '1.0'),
            new Package('non-puli-package', '1.0', '1.0'),
        );

        $this->localRepository->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue($this->installedPackages));

        $this->composer = new Composer();
        $this->composer->setRepositoryManager($this->repositoryManager);
        $this->composer->setInstallationManager($this->installationManager);
        $this->composer->setConfig($this->config);
        $this->composer->setPackage($this->projectPackage);

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
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('Installing <info>package2</info> (<comment>package2</comment>)');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->$listener($event);

        $this->assertFileExists($this->tempDir.'/resource-repository.php');

        $repo = include $this->tempDir.'/resource-repository.php';

        $this->assertInstanceOf('Puli\Repository\ResourceRepositoryInterface', $repo);
        $this->assertSame($this->tempDir.'/res/file', $repo->get('/root/file')->getLocalPath());
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

        copy(__DIR__.'/Fixtures/root-preinstalled/packages.json', $this->tempDir.'/packages.json');

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->postInstall($event);
    }

    public function testResolveAliasPackages()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy(__DIR__.'/Fixtures/root-preinstalled/packages.json', $this->tempDir.'/packages.json');

        $package = new Package('package1', '1.0', '1.0');

        $this->installedPackages = array(
            // Package is not listed in installed packages
            new AliasPackage($package, '1.0', '1.0'),
        );

        $this->localRepository->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue($this->installedPackages));

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->postInstall($event);
    }

    public function testInstallAliasedPackageOnlyOnce()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy(__DIR__.'/Fixtures/root-preinstalled/packages.json', $this->tempDir.'/packages.json');

        $package = new Package('package1', '1.0', '1.0');

        $this->installedPackages = array(
            // This time the package is returned here as well
            $package,
            new AliasPackage($package, '1.0', '1.0'),
        );

        $this->localRepository->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue($this->installedPackages));

        $this->io->expects($this->at(0))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->plugin->postInstall($event);
    }

    public function testInstallPluginIfNecessary()
    {
        $reader = new ConfigJsonReader();
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy(__DIR__.'/Fixtures/root-no-plugin/puli.json', $this->tempDir.'/puli.json');

        $this->io->expects($this->at(0))
            ->method('askConfirmation')
            ->with("<question>The Composer plugin for Puli is not installed. Install now? (yes/no)</question> [<comment>yes</comment>]\n")
            ->will($this->returnValue(true));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('Installing <info>Puli\Extension\Composer\ComposerPlugin</info>');
        $this->io->expects($this->at(2))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(3))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(4))
            ->method('write')
            ->with('Installing <info>package2</info> (<comment>package2</comment>)');
        $this->io->expects($this->at(5))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        // Configuration does not contain plugin
        $globalConfig = $reader->readGlobalConfig($this->tempHome.'/config.json');

        $this->assertFalse($globalConfig->hasPluginClass(self::PLUGIN_CLASS));

        $this->plugin->postInstall($event);

        // Global config contains plugin now
        $globalConfig = $reader->readGlobalConfig($this->tempHome.'/config.json');

        $this->assertTrue($globalConfig->hasPluginClass(self::PLUGIN_CLASS));
    }

    public function testAbortIfPluginInstallationNotDesired()
    {
        $reader = new ConfigJsonReader();
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        copy(__DIR__.'/Fixtures/root-no-plugin/puli.json', $this->tempDir.'/puli.json');

        $this->io->expects($this->once())
            ->method('askConfirmation')
            ->with("<question>The Composer plugin for Puli is not installed. Install now? (yes/no)</question> [<comment>yes</comment>]\n")
            ->will($this->returnValue(false));

        $this->io->expects($this->never())
            ->method('write');

        $this->plugin->postInstall($event);

        // Global config was not changed
        $globalConfig = $reader->readGlobalConfig($this->tempHome.'/config.json');

        $this->assertFalse($globalConfig->hasPluginClass(self::PLUGIN_CLASS));
    }

    public function testInitializeProjectIfNecessary()
    {
        $event = new CommandEvent(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        unlink($this->tempDir.'/puli.json');

        $this->io->expects($this->at(0))
            ->method('askConfirmation')
            ->with("<question>The project does not have Puli support. Add Puli support now? (yes/no)</question> [<comment>yes</comment>]\n")
            ->will($this->returnValue(true));

        $this->io->expects($this->at(1))
            ->method('write')
            ->with('<info>Initializing Puli project</info>');

        $this->io->expects($this->at(2))
            ->method('askConfirmation')
            ->with("<question>The Composer plugin for Puli is not installed. Install now? (yes/no)</question> [<comment>yes</comment>]\n")
            ->will($this->returnValue(true));

        $this->io->expects($this->at(3))
            ->method('write')
            ->with('Installing <info>Puli\Extension\Composer\ComposerPlugin</info>');
        $this->io->expects($this->at(4))
            ->method('write')
            ->with('<info>Looking for new Puli packages</info>');
        $this->io->expects($this->at(5))
            ->method('write')
            ->with('Installing <info>package1</info> (<comment>package1</comment>)');
        $this->io->expects($this->at(6))
            ->method('write')
            ->with('Installing <info>package2</info> (<comment>package2</comment>)');
        $this->io->expects($this->at(7))
            ->method('write')
            ->with('<info>Generating Puli resource repository</info>');

        $this->assertFalse(PackageManager::isPuliProject($this->tempDir));

        $this->plugin->postInstall($event);

        $this->assertTrue(PackageManager::isPuliProject($this->tempDir));
    }
}
