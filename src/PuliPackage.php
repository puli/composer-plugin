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

use Webmozart\Assert\Assert;

/**
 * A Puli package.
 *
 * This class acts as data transfer object (DTO) for results delivered by the
 * "puli package" command.
 *
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPackage
{
    /**
     * State: The package is enabled.
     */
    const STATE_ENABLED = 'enabled';

    /**
     * State: The package was not found on the filesystem.
     */
    const STATE_NOT_FOUND = 'not-found';

    /**
     * State: The package could not be loaded.
     */
    const STATE_NOT_LOADABLE = 'not-loadable';

    private static $states = array(
        self::STATE_ENABLED,
        self::STATE_NOT_FOUND,
        self::STATE_NOT_LOADABLE,
    );

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $installerName;

    /**
     * @var string
     */
    private $installPath;

    /**
     * @var string
     */
    private $state;

    /**
     * Creates a new package DTO.
     *
     * @param string $name          The package name.
     * @param string $installerName The name of the installer.
     * @param string $installPath   The absolute install path.
     * @param string $state         One of the STATE_* constants in this class.
     */
    public function __construct($name, $installerName, $installPath, $state)
    {
        Assert::stringNotEmpty($name, 'The package name must be a non-empty string. Got: %s');
        Assert::string($installerName, 'The installer name must be a string. Got: %s');
        Assert::stringNotEmpty($installPath, 'The install path must be a non-empty string. Got: %s');
        Assert::oneOf($state, self::$states, 'The package state must be one of %2$s. Got: %s');

        $this->name = $name;
        $this->installerName = $installerName;
        $this->installPath = $installPath;
        $this->state = $state;
    }

    /**
     * Returns the package name.
     *
     * @return string The package name.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns the name of the package installer.
     *
     * @return string The installer name.
     */
    public function getInstallerName()
    {
        return $this->installerName;
    }

    /**
     * Returns the absolute path to where the package is installed.
     *
     * @return string The install path.
     */
    public function getInstallPath()
    {
        return $this->installPath;
    }

    /**
     * Returns the state of the package.
     *
     * @return string One of the STATE_* constants in this class.
     */
    public function getState()
    {
        return $this->state;
    }
}
