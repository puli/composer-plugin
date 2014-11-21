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
use Puli\PackageManager\Event\PackageConfigEvent;
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
        $dispatcher->addListener(PackageEvents::LOAD_PACKAGE_CONFIG, array($this, 'addComposerName'));
        $dispatcher->addListener(PackageEvents::SAVE_PACKAGE_CONFIG, array($this, 'removeComposerName'));
    }

    public function addComposerName(PackageConfigEvent $event)
    {
        $config = $event->getPackageConfig();
        $packageRoot = Path::getDirectory($config->getPath());
        $packageName = $config->getPackageName();

        // We can't do anything without a composer.json
        if (!file_exists($packageRoot.'/composer.json')) {
            return;
        }

        // Read the package name
        $decoder = new JsonDecoder();
        $composerData = $decoder->decodeFile($packageRoot.'/composer.json');

        // If the names are different, we have a problem
        if (null !== $packageName && $packageName !== $composerData->name) {
            throw $this->createNameConflictException($packageRoot, $packageName, $composerData->name);
        }

        $config->setPackageName($composerData->name);
    }

    public function removeComposerName(PackageConfigEvent $event)
    {
        $config = $event->getPackageConfig();
        $packageRoot = Path::getDirectory($config->getPath());
        $packageName = $config->getPackageName();

        // We can't do anything without a composer.json
        if (!file_exists($packageRoot.'/composer.json')) {
            return;
        }

        // Read the package name
        $decoder = new JsonDecoder();
        $composerData = $decoder->decodeFile($packageRoot.'/composer.json');

        // If the names are different, we have a problem
        if (null !== $packageName && $packageName !== $composerData->name) {
            throw $this->createNameConflictException($packageRoot, $packageName, $composerData->name);
        }

        $config->setPackageName(null);
    }

    private function createNameConflictException($packageRoot, $jsonName, $composerName)
    {
        return new NameConflictException(sprintf(
            'In %s: puli.json sets the package name to "%s", composer.json to '.
            '"%s". Which is correct? You should remove the name from '.
            'puli.json to remove the conflict.',
            $packageRoot,
            $jsonName,
            $composerName
        ));
    }
}
