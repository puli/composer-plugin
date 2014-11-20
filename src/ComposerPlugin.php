<?php

/*
 * This file is part of the Puli Composer Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer;

use Puli\Json\JsonDecoder;
use Puli\PackageManager\Event\JsonEvent;
use Puli\PackageManager\Event\PackageEvents;
use Puli\PackageManager\PackageManager;
use Puli\PackageManager\Plugin\PluginInterface;
use Puli\Util\Path;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A Composer plugin for the Puli package manager.
 *
 * This plugin parses the package name in composer.json and registers it with
 * Puli so that the name definition does not have to be duplicated in puli.json.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ComposerPlugin implements PluginInterface
{
    const VERSION = '@package_version@';

    const RELEASE_DATE = '@release_date@';

    /**
     * Activates the plugin.
     *
     * @param PackageManager           $manager    The package manager.
     * @param EventDispatcherInterface $dispatcher The manager's event dispatcher.
     */
    public function activate(PackageManager $manager, EventDispatcherInterface $dispatcher)
    {
        $dispatcher->addListener(PackageEvents::PACKAGE_JSON_LOADED, array($this, 'addComposerNameToJson'));
        $dispatcher->addListener(PackageEvents::PACKAGE_JSON_GENERATED, array($this, 'removeComposerNameFromJson'));
    }

    public function addComposerNameToJson(JsonEvent $event)
    {
        $jsonData = $event->getJsonData();
        $packageRoot = Path::getDirectory($event->getJsonPath());

        // We can't do anything without a composer.json
        if (!file_exists($packageRoot.'/composer.json')) {
            return;
        }

        // Read the package name
        $decoder = new JsonDecoder();
        $composerData = $decoder->decodeFile($packageRoot.'/composer.json');

        // If the names are different, we have a problem
        if (isset($jsonData->name) && $jsonData->name !== $composerData->name) {
            throw $this->createNameConflictException($packageRoot, $jsonData->name, $composerData->name);
        }

        $jsonData->name = $composerData->name;

        $event->setJsonData($jsonData);
    }

    public function removeComposerNameFromJson(JsonEvent $event)
    {
        $packageRoot = Path::getDirectory($event->getJsonPath());
        $jsonData = $event->getJsonData();

        // We can't do anything without a composer.json
        if (!file_exists($packageRoot.'/composer.json')) {
            return;
        }

        // Read the package name
        $decoder = new JsonDecoder();
        $composerData = $decoder->decodeFile($packageRoot.'/composer.json');

        // If the names are different, we have a problem
        if (isset($jsonData->name) && $jsonData->name !== $composerData->name) {
            throw $this->createNameConflictException($packageRoot, $jsonData->name, $composerData->name);
        }

        unset($jsonData->name);

        $event->setJsonData($jsonData);
    }

    private function createNameConflictException($packageRoot, $jsonName, $composerName)
    {
        return new NameConflictException(sprintf(
            'In %s: %s sets the package name to "%s", composer.json to '.
            '"%s". Which is correct? You should remove the name from '.
            '%s to remove the conflict.',
            $packageRoot,
            PackageManager::PACKAGE_CONFIG,
            $jsonName,
            $composerName,
            PackageManager::PACKAGE_CONFIG
        ));
    }
}
