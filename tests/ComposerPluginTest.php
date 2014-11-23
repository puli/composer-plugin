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

use Puli\Extension\Composer\ComposerPlugin;
use Puli\PackageManager\Config\GlobalConfig;
use Puli\PackageManager\Event\PackageConfigEvent;
use Puli\PackageManager\Event\PackageEvents;
use Puli\PackageManager\Package\Config\PackageConfig;
use Puli\PackageManager\Package\Config\RootPackageConfig;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ComposerPluginTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ComposerPlugin
     */
    private $plugin;

    protected function setUp()
    {
        $this->plugin = new ComposerPlugin();
    }

    public function testActivate()
    {
        $globalConfig = new GlobalConfig();
        $config = new RootPackageConfig($globalConfig, null, __DIR__.'/Fixtures/root/puli.json');
        $dispatcher = $this->getMock('Symfony\Component\EventDispatcher\EventDispatcherInterface');
        $manager = $this->getMockBuilder('Puli\PackageManager\PackageManager')
            ->disableOriginalConstructor()
            ->getMock();

        $dispatcher->expects($this->at(0))
            ->method('addListener')
            ->with(PackageEvents::LOAD_PACKAGE_CONFIG, array($this->plugin, 'handleLoadPackageConfig'));
        $dispatcher->expects($this->at(1))
            ->method('addListener')
            ->with(PackageEvents::SAVE_PACKAGE_CONFIG, array($this->plugin, 'handleSavePackageConfig'));

        $manager->expects($this->any())
            ->method('getRootPackageConfig')
            ->will($this->returnValue($config));

        $this->plugin->activate($manager, $dispatcher);

        $this->assertSame('root', $config->getPackageName());
    }

    public function testComposerNameAddedToConfig()
    {
        $config = new PackageConfig(null, __DIR__.'/Fixtures/root/puli.json');
        $event = new PackageConfigEvent($config);

        $this->plugin->handleLoadPackageConfig($event);

        $this->assertSame('root', $config->getPackageName());
    }

    public function testNoNameAddedToConfigIfNoComposerJson()
    {
        $config = new PackageConfig(null, __DIR__.'/Fixtures/root-no-composer/puli.json');
        $event = new PackageConfigEvent($config);

        $this->plugin->handleLoadPackageConfig($event);

        $this->assertNull($config->getPackageName());
    }

    /**
     * @expectedException \Puli\Extension\Composer\NameConflictException
     * @expectedExceptionMessage Fixtures/root
     */
    public function testAddNameFailsIfDifferentNames()
    {
        $config = new PackageConfig('package-name', __DIR__.'/Fixtures/root/puli.json');
        $event = new PackageConfigEvent($config);

        $this->plugin->handleLoadPackageConfig($event);
    }

    public function testComposerNameRemovedFromJson()
    {
        $config = new PackageConfig('root', __DIR__.'/Fixtures/root/puli.json');
        $event = new PackageConfigEvent($config);

        $this->plugin->handleSavePackageConfig($event);

        $this->assertNull($config->getPackageName());
    }

    public function testNoNameRemovedFromJsonIfNoComposerJson()
    {
        $config = new PackageConfig('root', __DIR__.'/Fixtures/root-no-composer/puli.json');
        $event = new PackageConfigEvent($config);

        $this->plugin->handleSavePackageConfig($event);

        $this->assertSame('root', $config->getPackageName());
    }

    /**
     * @expectedException \Puli\Extension\Composer\NameConflictException
     * @expectedExceptionMessage Fixtures/root
     */
    public function testRemoveNameFailsIfDifferentNames()
    {
        $config = new PackageConfig('package-name', __DIR__.'/Fixtures/root/puli.json');

        $event = new PackageConfigEvent($config);

        $this->plugin->handleSavePackageConfig($event);
    }
}
