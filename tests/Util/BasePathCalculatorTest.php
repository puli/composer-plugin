<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\PuliPlugin\Tests\Util;

use Webmozart\Composer\PuliPlugin\Util\BasePathCalculator;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class BasePathCalculatorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var BasePathCalculator
     */
    private $calculator;

    protected function setUp()
    {
        $this->calculator = new BasePathCalculator();
    }

    public function testEqual()
    {
        $this->assertSame(
            '/base/path',
            $this->calculator->calculateCommonBasePath(
                '/base/path',
                '/base/path'
            )
        );
    }

    public function testEqualWindowsStyle()
    {
        $this->assertEquals(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                'C:/base/path'
            )
        );
    }

    public function testEqualWindowsStyleBackSlashes()
    {
        $this->assertEquals(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path',
                'C:\\base\\path'
            )
        );
    }

    public function testEqualWindowsStyleMixedSlashes()
    {
        $this->assertEquals(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                'C:\\base\\path'
            )
        );
    }

    public function testFirstTrailingSlash()
    {
        $this->assertSame(
            '/base/path',
            $this->calculator->calculateCommonBasePath(
                '/base/path/',
                '/base/path'
            )
        );
    }

    public function testFirstTrailingSlashWindowsStyle()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/',
                'C:/base/path'
            )
        );
    }

    public function testFirstTrailingSlashWindowsStyleBackSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path\\',
                'C:\\base\\path'
            )
        );
    }

    public function testFirstTrailingSlashWindowsStyleMixedSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/',
                'C:\\base\\path'
            )
        );
    }

    public function testSecondTrailingSlash()
    {
        $this->assertSame(
            '/base/path',
            $this->calculator->calculateCommonBasePath(
                '/base/path',
                '/base/path/'
            )
        );
    }

    public function testSecondTrailingSlashWindowsStyle()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                'C:/base/path/'
            )
        );
    }

    public function testSecondTrailingSlashWindowsStyleBackSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path',
                'C:\\base\\path\\'
            )
        );
    }

    public function testSecondTrailingSlashWindowsStyleMixedSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                'C:\\base\\path\\'
            )
        );
    }

    public function testFirstInSecond()
    {
        $this->assertSame(
            '/base/path',
            $this->calculator->calculateCommonBasePath(
                '/base/path/sub',
                '/base/path'
            )
        );
    }

    public function testFirstInSecondWindowsStyle()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/sub',
                'C:/base/path'
            )
        );
    }

    public function testFirstInSecondWindowsStyleBackSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path\\sub',
                'C:\\base\\path'
            )
        );
    }

    public function testFirstInSecondWindowsStyleMixedSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/sub',
                'C:\\base\\path'
            )
        );
    }

    public function testSecondInFirst()
    {
        $this->assertSame(
            '/base/path',
            $this->calculator->calculateCommonBasePath(
                '/base/path',
                '/base/path/sub'
            )
        );
    }

    public function testSecondInFirstWindowsStyle()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                'C:/base/path/sub'
            )
        );
    }

    public function testSecondInFirstWindowsStyleBackSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path',
                'C:\\base\\path\\sub'
            )
        );
    }

    public function testSecondInFirstWindowsStyleMixedSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                'C:\\base\\path\\sub'
            )
        );
    }

    public function testFirstIsPrefix()
    {
        $this->assertSame(
            '/base/path',
            $this->calculator->calculateCommonBasePath(
                '/base/path/sub',
                '/base/path/sub-but-different'
            )
        );
    }

    public function testFirstIsPrefixWindowsStyle()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/sub',
                'C:/base/path/sub-but-different'
            )
        );
    }

    public function testFirstIsPrefixWindowsStyleBackSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path\\sub',
                'C:\\base\\path\\sub-but-different'
            )
        );
    }

    public function testFirstIsPrefixWindowsStyleMixedSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/sub',
                'C:\\base\\path\\sub-but-different'
            )
        );
    }

    public function testSecondIsPrefix()
    {
        $this->assertSame(
            '/base/path',
            $this->calculator->calculateCommonBasePath(
                '/base/path/sub-but-different',
                '/base/path/sub'
            )
        );
    }

    public function testSecondIsPrefixWindowsStyle()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/sub-but-different',
                'C:/base/path/sub'
            )
        );
    }

    public function testSecondIsPrefixWindowsStyleBackSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path\\sub-but-different',
                'C:\\base\\path\\sub'
            )
        );
    }

    public function testSecondIsPrefixWindowsStyleMixedSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/sub-but-different',
                'C:\\base\\path\\sub'
            )
        );
    }

    public function testCommonBasePath()
    {
        $this->assertSame(
            '/base/path',
            $this->calculator->calculateCommonBasePath(
                '/base/path/first',
                '/base/path/second'
            )
        );
    }

    public function testCommonBasePathWindowsStyle()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/first',
                'C:/base/path/second'
            )
        );
    }

    public function testCommonBasePathWindowsStyleBackSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path\\first',
                'C:\\base\\path\\second'
            )
        );
    }

    public function testCommonBasePathWindowsStyleMixedSlashes()
    {
        $this->assertSame(
            'C:/base/path',
            $this->calculator->calculateCommonBasePath(
                'C:/base/path/first',
                'C:\\base\\path\\second'
            )
        );
    }

    public function testCommonRoot()
    {
        $this->assertSame(
            '/',
            $this->calculator->calculateCommonBasePath(
                '/first',
                '/second'
            )
        );
    }

    public function testCommonRootWindowsStyle()
    {
        $this->assertSame(
            'C:',
            $this->calculator->calculateCommonBasePath(
                'C:/first',
                'C:/second'
            )
        );
    }

    public function testCommonRootWindowsStyleBackSlashes()
    {
        $this->assertSame(
            'C:',
            $this->calculator->calculateCommonBasePath(
                'C:\\first',
                'C:\\second'
            )
        );
    }

    public function testCommonRootWindowsStyleMixedSlashes()
    {
        $this->assertSame(
            'C:',
            $this->calculator->calculateCommonBasePath(
                'C:/first',
                'C:\\second'
            )
        );
    }

    public function testNoCommonBasePathFirstWindowsStyle()
    {
        $this->assertNull(
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                '/base/path'
            )
        );
    }

    public function testNoCommonBasePathFirstWindowsStyleBackSlashes()
    {
        $this->assertNull(
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path',
                '/base/path'
            )
        );
    }

    public function testNoCommonBasePathSecondWindowsStyle()
    {
        $this->assertNull(
            $this->calculator->calculateCommonBasePath(
                '/base/path',
                'C:/base/path'
            )
        );
    }

    public function testNoCommonBasePathSecondWindowsStyleBackSlashes()
    {
        $this->assertNull(
            $this->calculator->calculateCommonBasePath(
                '/base/path',
                'C:\\base\\path'
            )
        );
    }

    public function testDifferentPartitionsWindowsStyle()
    {
        $this->assertNull(
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                'D:/base/path'
            )
        );
    }

    public function testDifferentPartitionsWindowsStyleBackSlashes()
    {
        $this->assertNull(
            $this->calculator->calculateCommonBasePath(
                'C:\\base\\path',
                'D:\\base\\path'
            )
        );
    }

    public function testDifferentPartitionsWindowsStyleMixedSlashes()
    {
        $this->assertNull(
            $this->calculator->calculateCommonBasePath(
                'C:/base/path',
                'D:\\base\\path'
            )
        );
    }
}
