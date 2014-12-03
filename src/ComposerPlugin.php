<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer;

use Puli\RepositoryManager\Environment\ProjectEnvironment;
use Puli\RepositoryManager\Event\PackageFileEvent;
use Puli\RepositoryManager\ManagerEvents;
use Puli\RepositoryManager\Package\PackageFile\PackageFile;
use Puli\RepositoryManager\Plugin\PluginInterface;
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
    const VERSION = '1.0.0-alpha2';

    const RELEASE_DATE = '2014-12-03';

    /**
     * Activates the plugin.
     *
     * @param ProjectEnvironment $environment The project environment.
     */
    public function activate(ProjectEnvironment $environment)
    {
        $dispatcher = $environment->getEventDispatcher();

        $dispatcher->addListener(ManagerEvents::LOAD_PACKAGE_FILE, array($this, 'handleLoadPackageFile'));
        $dispatcher->addListener(ManagerEvents::SAVE_PACKAGE_FILE, array($this, 'handleSavePackageFile'));

        // The project configuration is already loaded. Fix it.
        $this->addComposerName($environment->getRootPackageFile());
    }

    public function handleLoadPackageFile(PackageFileEvent $event)
    {
        $this->addComposerName($event->getPackageFile());
    }

    public function handleSavePackageFile(PackageFileEvent $event)
    {
        $this->removeComposerName($event->getPackageFile());
    }

    private function addComposerName(PackageFile $packageFile)
    {
        $packageRoot = Path::getDirectory($packageFile->getPath());
        $packageName = $packageFile->getPackageName();

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

        $packageFile->setPackageName($composerData->name);
    }

    private function removeComposerName(PackageFile $packageFile)
    {
        $packageRoot = Path::getDirectory($packageFile->getPath());
        $packageName = $packageFile->getPackageName();

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

        $packageFile->setPackageName(null);
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
