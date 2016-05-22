<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * A Puli plugin for Composer.
 *
 * The plugin updates the Puli package repository based on the Composer
 * packages whenever `composer install` or `composer update` is executed.
 *
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var PuliPluginImpl
     */
    private $impl;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'listen',
            ScriptEvents::POST_UPDATE_CMD => 'listen',
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'listen',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'listen',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getEventDispatcher()->addSubscriber($this);
    }

    /**
     * Listens to Composer events.
     *
     * This method is very minimalist on purpose. We want to load the actual
     * implementation only after updating the Composer packages so that we get
     * the updated version (if available).
     *
     * @param Event $event The Composer event.
     */
    public function listen(Event $event)
    {
        // Plugin has been uninstalled
        if (!file_exists(__FILE__) || !file_exists(__DIR__.'/PuliPluginImpl.php')) {
            return;
        }

        // Load the implementation only after updating Composer so that we get
        // the new version of the plugin when a new one was installed
        if (null === $this->impl) {
            $this->impl = new PuliPluginImpl($event);
        }

        switch ($event->getName()) {
            case ScriptEvents::PRE_AUTOLOAD_DUMP:
                $this->impl->preAutoloadDump();
                break;
            case ScriptEvents::POST_AUTOLOAD_DUMP:
                $this->impl->postAutoloadDump();
                break;

            case ScriptEvents::POST_INSTALL_CMD:
            case ScriptEvents::POST_UPDATE_CMD:
                $this->impl->postInstall();
                break;
        }
    }

    public function setPluginImpl(PuliPluginImpl $impl)
    {
        $this->impl = $impl;
    }
}
