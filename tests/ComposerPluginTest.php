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

use Puli\Extension\Composer\ComposerPlugin;
use Puli\RepositoryManager\Event\PackageFileEvent;
use Puli\RepositoryManager\ManagerEvents;
use Puli\RepositoryManager\Package\PackageFile\PackageFile;
use Puli\RepositoryManager\Package\PackageFile\RootPackageFile;

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
        $packageFile = new RootPackageFile(null, __DIR__.'/Fixtures/root/puli.json');
        $dispatcher = $this->getMock('Symfony\Component\EventDispatcher\EventDispatcherInterface');

        $environment = $this->getMockBuilder('Puli\RepositoryManager\Environment\ProjectEnvironment')
            ->disableOriginalConstructor()
            ->getMock();

        $environment->expects($this->any())
            ->method('getEventDispatcher')
            ->will($this->returnValue($dispatcher));
        $environment->expects($this->any())
            ->method('getRootPackageFile')
            ->will($this->returnValue($packageFile));

        $dispatcher->expects($this->at(0))
            ->method('addListener')
            ->with(ManagerEvents::LOAD_PACKAGE_FILE, array($this->plugin, 'handleLoadPackageFile'));
        $dispatcher->expects($this->at(1))
            ->method('addListener')
            ->with(ManagerEvents::SAVE_PACKAGE_FILE, array($this->plugin, 'handleSavePackageFile'));

        $this->plugin->activate($environment);

        $this->assertSame('root', $packageFile->getPackageName());
    }

    public function testComposerNameAddedToConfig()
    {
        $config = new PackageFile(null, __DIR__.'/Fixtures/root/puli.json');
        $event = new PackageFileEvent($config);

        $this->plugin->handleLoadPackageFile($event);

        $this->assertSame('root', $config->getPackageName());
    }

    public function testNoNameAddedToConfigIfNoComposerJson()
    {
        $config = new PackageFile(null, __DIR__.'/Fixtures/root-no-composer/puli.json');
        $event = new PackageFileEvent($config);

        $this->plugin->handleLoadPackageFile($event);

        $this->assertNull($config->getPackageName());
    }

    /**
     * @expectedException \Puli\Extension\Composer\NameConflictException
     * @expectedExceptionMessage Fixtures/root
     */
    public function testAddNameFailsIfDifferentNames()
    {
        $config = new PackageFile('package-name', __DIR__.'/Fixtures/root/puli.json');
        $event = new PackageFileEvent($config);

        $this->plugin->handleLoadPackageFile($event);
    }

    public function testComposerNameRemovedFromJson()
    {
        $config = new PackageFile('root', __DIR__.'/Fixtures/root/puli.json');
        $event = new PackageFileEvent($config);

        $this->plugin->handleSavePackageFile($event);

        $this->assertNull($config->getPackageName());
    }

    public function testNoNameRemovedFromJsonIfNoComposerJson()
    {
        $config = new PackageFile('root', __DIR__.'/Fixtures/root-no-composer/puli.json');
        $event = new PackageFileEvent($config);

        $this->plugin->handleSavePackageFile($event);

        $this->assertSame('root', $config->getPackageName());
    }

    /**
     * @expectedException \Puli\Extension\Composer\NameConflictException
     * @expectedExceptionMessage Fixtures/root
     */
    public function testRemoveNameFailsIfDifferentNames()
    {
        $config = new PackageFile('package-name', __DIR__.'/Fixtures/root/puli.json');

        $event = new PackageFileEvent($config);

        $this->plugin->handleSavePackageFile($event);
    }
}
