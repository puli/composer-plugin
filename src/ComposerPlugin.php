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

use Puli\RepositoryManager\Event\PackageConfigEvent;
use Puli\RepositoryManager\ManagerEvents;
use Puli\RepositoryManager\Package\Config\PackageConfig;
use Puli\RepositoryManager\Plugin\PluginInterface;
use Puli\RepositoryManager\Project\ProjectEnvironment;
use Webmozart\Json\JsonDecoder;
use Webmozart\PathUtil\Path;

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
     * @param ProjectEnvironment $environment The project environment.
     */
    public function activate(ProjectEnvironment $environment)
    {
        $dispatcher = $environment->getEventDispatcher();

        $dispatcher->addListener(ManagerEvents::LOAD_PACKAGE_CONFIG, array($this, 'handleLoadPackageConfig'));
        $dispatcher->addListener(ManagerEvents::SAVE_PACKAGE_CONFIG, array($this, 'handleSavePackageConfig'));

        // The project configuration is already loaded. Fix it.
        $this->addComposerName($environment->getRootPackageConfig());
    }

    public function handleLoadPackageConfig(PackageConfigEvent $event)
    {
        $this->addComposerName($event->getPackageConfig());
    }

    public function handleSavePackageConfig(PackageConfigEvent $event)
    {
        $this->removeComposerName($event->getPackageConfig());
    }

    private function addComposerName(PackageConfig $config)
    {
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

    private function removeComposerName(PackageConfig $config)
    {
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
