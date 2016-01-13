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
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use PHPUnit_Framework_MockObject_MockObject;
use PHPUnit_Framework_TestCase;
use Puli\ComposerPlugin\PuliPlugin;
use Puli\ComposerPlugin\PuliPluginImpl;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\Glob\Test\TestUtil;

/**
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @runTestsInSeparateProcesses
 */
class PuliPluginTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var PuliPlugin
     */
    private $plugin;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|Composer
     */
    private $composer;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|IOInterface
     */
    private $io;

    /**
     * @var PHPUnit_Framework_MockObject_MockObject|PuliPluginImpl
     */
    private $impl;

    /**
     * @var string
     */
    private $tempDir;

    /**
     * @var string
     */
    private $pluginClassFile;

    /**
     * @var string
     */
    private $pluginImplClassFile;

    protected function setUp()
    {
        $this->tempDir = TestUtil::makeTempDir('puli-composer-plugin', __CLASS__);
        $this->pluginClassFile = $this->tempDir.'/PuliPlugin.php';
        $this->pluginImplClassFile = $this->tempDir.'/PuliPluginImpl.php';

        // Copy to a location where we can safely delete it
        copy(__DIR__.'/../src/PuliPlugin.php', $this->pluginClassFile);
        copy(__DIR__.'/../src/PuliPluginImpl.php', $this->pluginImplClassFile);

        // Use custom file so that we can safely delete it
        // This is needed in tests that check that the plugin does not create
        // errors after uninstall
        require $this->pluginClassFile;
        require $this->pluginImplClassFile;

        $this->composer = $this->getMockBuilder('Composer\Composer')
            ->disableOriginalConstructor()
            ->getMock();
        $this->io = $this->getMock('Composer\IO\IOInterface');
        $this->impl = $this->getMockBuilder('Puli\ComposerPlugin\PuliPluginImpl')
            ->disableOriginalConstructor()
            ->getMock();
        $this->plugin = new PuliPlugin();
        $this->plugin->setPluginImpl($this->impl);
    }

    protected function tearDown()
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->tempDir);
    }

    public function testActivate()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $this->composer->expects($this->once())
            ->method('getEventDispatcher')
            ->willReturn($dispatcher);

        $dispatcher->expects($this->once())
            ->method('addSubscriber')
            ->with($this->plugin);

        $this->plugin->activate($this->composer, $this->io);
    }

    public function testRunPostInstall()
    {
        $event = new Event(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->impl->expects($this->once())
            ->method('postInstall');

        $this->plugin->listen($event);
    }

    public function testDoNotRunPostInstallAfterUninstall()
    {
        $event = new Event(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->impl->expects($this->never())
            ->method('postInstall');

        $filesystem = new Filesystem();
        $filesystem->remove($this->pluginClassFile);

        $this->plugin->listen($event);
    }

    public function testDoNotRunPostInstallAfterRemovingImplementation()
    {
        $event = new Event(ScriptEvents::POST_INSTALL_CMD, $this->composer, $this->io);

        $this->impl->expects($this->never())
            ->method('postInstall');

        $filesystem = new Filesystem();
        $filesystem->remove($this->pluginImplClassFile);

        $this->plugin->listen($event);
    }

    public function testRunPostUpdate()
    {
        $event = new Event(ScriptEvents::POST_UPDATE_CMD, $this->composer, $this->io);

        $this->impl->expects($this->once())
            ->method('postInstall');

        $this->plugin->listen($event);
    }

    public function testDoNotRunPostUpdateAfterUninstall()
    {
        $event = new Event(ScriptEvents::POST_UPDATE_CMD, $this->composer, $this->io);

        $this->impl->expects($this->never())
            ->method('postInstall');

        $filesystem = new Filesystem();
        $filesystem->remove($this->pluginClassFile);

        $this->plugin->listen($event);
    }

    public function testDoNotRunPostUpdateAfterRemovingImplementation()
    {
        $event = new Event(ScriptEvents::POST_UPDATE_CMD, $this->composer, $this->io);

        $this->impl->expects($this->never())
            ->method('postInstall');

        $filesystem = new Filesystem();
        $filesystem->remove($this->pluginImplClassFile);

        $this->plugin->listen($event);
    }

    public function testRunPostAutoloadDump()
    {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->impl->expects($this->once())
            ->method('postAutoloadDump');

        $this->plugin->listen($event);
    }

    public function testDoNotRunPostAutoloadDumpAfterUninstall()
    {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->impl->expects($this->never())
            ->method('postAutoloadDump');

        $filesystem = new Filesystem();
        $filesystem->remove($this->pluginImplClassFile);

        $this->plugin->listen($event);
    }

    public function testDoNotRunPostAutoloadDumpAfterRemovingImplementation()
    {
        $event = new Event(ScriptEvents::POST_AUTOLOAD_DUMP, $this->composer, $this->io);

        $this->impl->expects($this->never())
            ->method('postAutoloadDump');

        $filesystem = new Filesystem();
        $filesystem->remove($this->pluginImplClassFile);

        $this->plugin->listen($event);
    }
}
