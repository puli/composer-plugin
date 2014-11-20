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
use Puli\PackageManager\Event\JsonEvent;
use Puli\PackageManager\Event\PackageEvents;

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

    public function testEventRegistration()
    {
        $dispatcher = $this->getMock('Symfony\Component\EventDispatcher\EventDispatcherInterface');
        $manager = $this->getMockBuilder('Puli\PackageManager\PackageManager')
            ->disableOriginalConstructor()
            ->getMock();

        $dispatcher->expects($this->at(0))
            ->method('addListener')
            ->with(PackageEvents::PACKAGE_JSON_LOADED, array($this->plugin, 'addComposerNameToJson'));
        $dispatcher->expects($this->at(1))
            ->method('addListener')
            ->with(PackageEvents::PACKAGE_JSON_GENERATED, array($this->plugin, 'removeComposerNameFromJson'));

        $this->plugin->activate($manager, $dispatcher);
    }

    public function testComposerNameAddedToJson()
    {
        $jsonData = new \stdClass();
        $event = new JsonEvent(__DIR__.'/Fixtures/root/puli.json', $jsonData);

        $this->plugin->addComposerNameToJson($event);

        $jsonData = $event->getJsonData();

        $this->assertInternalType('object', $jsonData);
        $this->assertObjectHasAttribute('name', $jsonData);
        $this->assertSame('root', $jsonData->name);
    }

    public function testNoNameAddedToJsonIfNoComposerJson()
    {
        $jsonData = new \stdClass();
        $event = new JsonEvent(__DIR__.'/Fixtures/root-no-composer/puli.json', $jsonData);

        $this->plugin->addComposerNameToJson($event);

        $jsonData = $event->getJsonData();

        $this->assertInternalType('object', $jsonData);
        $this->assertObjectNotHasAttribute('name', $jsonData);
    }

    /**
     * @expectedException \Puli\Extension\Composer\NameConflictException
     * @expectedExceptionMessage Fixtures/root
     */
    public function testAddNameFailsIfDifferentNames()
    {
        $jsonData = new \stdClass();
        $jsonData->name = 'package-name';
        $event = new JsonEvent(__DIR__.'/Fixtures/root/puli.json', $jsonData);

        $this->plugin->addComposerNameToJson($event);
    }

    public function testComposerNameRemovedFromJson()
    {
        $jsonData = new \stdClass();
        $jsonData->name = 'root';
        $event = new JsonEvent(__DIR__.'/Fixtures/root/puli.json', $jsonData);

        $this->plugin->removeComposerNameFromJson($event);

        $jsonData = $event->getJsonData();

        $this->assertInternalType('object', $jsonData);
        $this->assertObjectNotHasAttribute('name', $jsonData);
    }

    public function testNoNameRemovedFromJsonIfNoComposerJson()
    {
        $jsonData = new \stdClass();
        $jsonData->name = 'root';
        $event = new JsonEvent(__DIR__.'/Fixtures/root-no-composer/puli.json', $jsonData);

        $this->plugin->removeComposerNameFromJson($event);

        $jsonData = $event->getJsonData();

        $this->assertInternalType('object', $jsonData);
        $this->assertObjectHasAttribute('name', $jsonData);
        $this->assertSame('root', $jsonData->name);
    }

    /**
     * @expectedException \Puli\Extension\Composer\NameConflictException
     * @expectedExceptionMessage Fixtures/root
     */
    public function testRemoveNameFailsIfDifferentNames()
    {
        $jsonData = new \stdClass();
        $jsonData->name = 'package-name';
        $event = new JsonEvent(__DIR__.'/Fixtures/root/puli.json', $jsonData);

        $this->plugin->removeComposerNameFromJson($event);
    }
}
