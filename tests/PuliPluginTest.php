<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Repository\RepositoryManager;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use Puli\Extension\Composer\PuliPlugin;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPluginTest extends \PHPUnit_Framework_TestCase
{
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

    private $localRepository;

    private $repositoryManager;

    private $installationManager;

    /**
     * @var Config
     */
    private $config;

    private $projectPackage;

    private $installedPackages;

    protected function setUp()
    {
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

        $this->projectPackage = $this->getMock('Composer\Package\RootPackageInterface');
        $this->installedPackages = array(
            $this->getMock('Composer\Package\PackageInterface'),
            $this->getMock('Composer\Package\PackageInterface'),
        );

        $this->localRepository->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue($this->installedPackages));

        $this->composer = new Composer();
        $this->composer->setRepositoryManager($this->repositoryManager);
        $this->composer->setInstallationManager($this->installationManager);
        $this->composer->setConfig($this->config);
        $this->composer->setPackage($this->projectPackage);
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
    public function testEventListeners($eventName)
    {
        $event = new CommandEvent($eventName, $this->composer, $this->io);
        $listeners = PuliPlugin::getSubscribedEvents();

        $this->assertArrayHasKey($eventName, $listeners);

        $listener = $listeners[$eventName];

        $this->config->merge(array(
            'config' => array(
                'vendor-dir' => 'VENDOR/DIR',
            ),
        ));

        $this->dumper->expects($this->once())
            ->method('setVendorDir')
            ->with('VENDOR/DIR');

        $this->dumper->expects($this->once())
            ->method('setProjectPackage')
            ->with($this->projectPackage);

        $this->dumper->expects($this->once())
            ->method('setInstalledPackages')
            ->with($this->installedPackages);

        $this->dumper->expects($this->once())
            ->method('setRepositoryBuilder')
            ->with($this->isInstanceOf('Puli\Extension\Composer\RepositoryBuilder\RepositoryBuilder'));

        $this->io->expects($this->once())
            ->method('write')
            ->with('<info>Generating resource repository</info>');

        $this->plugin->$listener($event);
    }

    /**
     * @dataProvider provideEventNames
     * @depends testEventListeners
     */
    public function testEventListenersOnlyProcessedOnFirstCall($eventName)
    {
        // Execute normal test
        $this->testEventListeners($eventName);

        // Now fire again
        $event = new CommandEvent($eventName, $this->composer, $this->io);
        $listeners = PuliPlugin::getSubscribedEvents();
        $listener = $listeners[$eventName];

        $this->plugin->$listener($event);
    }

}
