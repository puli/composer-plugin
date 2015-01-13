<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin\Logger;

use Composer\IO\IOInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs to a Composer {@link IOInterface} instance.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class IOLogger implements LoggerInterface
{
    /**
     * @var IOInterface
     */
    private $io;

    /**
     * Creates the logger.
     *
     * @param IOInterface $io The output to write to.
     */
    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * {@inheritdoc}
     */
    public function emergency($message, array $context = array())
    {
        $this->io->write("<fg=white;bg=red>Emergency: $message</fg=white;bg=red>");
    }

    /**
     * {@inheritdoc}
     */
    public function alert($message, array $context = array())
    {
        $this->io->write("<fg=white;bg=red>Alert: $message</fg=white;bg=red>");
    }

    /**
     * {@inheritdoc}
     */
    public function critical($message, array $context = array())
    {
        $this->io->write("<fg=white;bg=red>Critical: $message</fg=white;bg=red>");
    }

    /**
     * {@inheritdoc}
     */
    public function error($message, array $context = array())
    {
        $this->io->write("<fg=white;bg=red>Error: $message</fg=white;bg=red>");
    }

    /**
     * {@inheritdoc}
     */
    public function warning($message, array $context = array())
    {
        $this->io->write("<fg=black;bg=yellow>Warning: $message</fg=black;bg=yellow>");
    }

    /**
     * {@inheritdoc}
     */
    public function notice($message, array $context = array())
    {
        $this->io->write("Notice: $message");
    }

    /**
     * {@inheritdoc}
     */
    public function info($message, array $context = array())
    {
        $this->io->write("[INFO] $message");
    }

    /**
     * {@inheritdoc}
     */
    public function debug($message, array $context = array())
    {
        $this->io->write("[DEBUG] $message");
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = array())
    {
        $this->$level($message, $context);
    }
}
