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
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPackage
{
    const STATE_ENABLED = 'enabled';

    const STATE_NOT_FOUND = 'not-found';

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
     * @param string $name
     * @param string $installerName
     * @param string $installPath
     * @param string $state
     */
    public function __construct($name, $installerName, $installPath, $state)
    {
        Assert::stringNotEmpty($name, 'The package name must be a non-empty string. Got: %s');
        Assert::stringNotEmpty($installerName, 'The installer name must be a non-empty string. Got: %s');
        Assert::stringNotEmpty($installPath, 'The install path must be a non-empty string. Got: %s');
        Assert::oneOf($state, self::$states, 'The package state must be one of %2$s. Got: %s');

        $this->name = $name;
        $this->installerName = $installerName;
        $this->installPath = $installPath;
        $this->state = $state;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getInstallerName()
    {
        return $this->installerName;
    }

    /**
     * @return string
     */
    public function getInstallPath()
    {
        return $this->installPath;
    }

    /**
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }
}
