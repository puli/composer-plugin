Composer Puli Plugin
====================

This plugin integrates the [Puli library] into Composer. With this plugin,
managing and accessing the files (*resources*) of your Composer packages
becomes a breeze. Whenever you install or update your Composer packages, the
plugin generates a resource locator for you which lets you access the resources
of those packages:

```php
$locator = require __DIR__.'/vendor/resource-locator.php';

echo $locator->get('/acme/blog/css/style.css')->getPath();
// => /path/to/project/vendor/acme/blog/assets/css/style.css
```

You can also register a stream wrapper to use the locator with PHP's file
functions:

```php
use Webmozart\Puli\StreamWrapper\ResourceStreamWrapper;

ResourceStreamWrapper::register('resource', $locator);

echo file_get_contents('resource:///acme/blog/css/style.css');
```

This document teaches you how to use the Puli plugin in practice.

Installation
------------

You can install the plugin by adding it to your composer.json:

```json
{
    "require": {
        "webmozart/composer-puli-plugin": "dev-master"
    },
    "minimum-stability": "alpha"
}
```

The "minimum-stability" setting is required because this plugin depends on the
[Puli library], which is not yet available in a stable version.

Mapping Resources
-----------------

Map any file or directory that you want to access through Puli in the
"resources" key of your composer.json:

```json
{
    "name": "acme/blog",
    "extra": {
        "resources": {
            "export": {
                "/acme/blog": "resources",
                "/acme/blog/css": "assets/css"
            }
        }
    }
}
```

As soon as you run `composer install` or `composer update`, a resource locator
will be built that takes the resource definitions of all installed packages
into account. Include the locator and you're ready to go:

```php
require_once __DIR__.'/vendor/autoload.php';

$locator = require __DIR__.'/vendor/resource-locator.php';

echo $locator->get('/acme/blog/css/style.css')->getPath();
// => /path/to/project/vendor/acme/blog/assets/css/style.css
```

Check the [Puli documentation] if you want to learn more about the API of the
resource locator.

Tagging Resources
-----------------

You can tag mapped resources in order to indicate that they support specific
features. For example, assume that all XLIFF translation files in the
"acme/blog" package should be registered with the `\Acme\Translator` class.
You can tag resources by adding them to the "tag" key in composer.json:

```php
{
    "name": "acme/blog",
    "extra": {
        "resources": {
            "export": {
                "/acme/blog": "resources",
            },
            "tag": {
                "/acme/blog/translations/*.xlf": "acme/translator/xlf"
            }
        }
    }
}
```

The tagged resources can be retrieved with the `getByTag()` method of the
resource locator:

```php
foreach ($locator->getByTag('acme/translator/xlf') as $resource) {
    echo $resource->getPath();
}
```

If you are the developer of the `\Acme\Translator` class, you can implement
[`ResourceDiscoveringInterface`] to let it discover its resources by itself:

```php
namespace Acme;

use Webmozart\Puli\ResourceDiscoveringInterface;

class Translator implements ResourceDiscoveringInterface
{
    // ...

    public function discoverResources(ResourceLocatorInterface $locator)
    {
        foreach ($locator->getByTag('acme/translator/xlf') as $resource) {
            // register $resource->getPath()...
        }
    }
}
```

When you create the translator, call `discoverResources()` and pass the locator:

```php
$translator = new Translator('en');
$translator->discoverResources($locator);
```

If you use a Dependency Injection Container, you can let the container call
this method automatically on services that implement
[`ResourceDiscoveringInterface`].

Overriding Resources
--------------------

Each package can override the resources of another package. To do so, add the
path you want to override to the "override" key:

```json
{
    "name": "acme/blog-extension",
    "require": {
        "acme/blog": "*"
    },
    "extra": {
        "resources": {
            "override": {
                "/acme/blog/css": "assets/css"
            }
        }
    }
}
```

The resources in the "acme/blog-extension" package are now preferred over those
in the "acme/blog" package. If a resource was not found in the overriding
package, the resource from the original package will be returned instead.

You can get all paths for an overridden resource using the
`getAlternativePaths()` method. The paths are returned in the order in which
they were overridden, with the original path coming first:

```php
print_r($locator->get('/acme/blog/css/style.css')->getAlternativePaths());
// Array
// (
//     [0] => /path/to/project/vendor/acme/blog/assets/css/style.css
//     [1] => /path/to/project/vendor/acme/blog-extension/assets/css/style.css
// )
```

Override Conflicts
------------------

If multiple packages try to override the same path, an
[`OverrideConflictException`] will be thrown and the overrides will be ignored.
The reason for this behavior is that Puli can't know in which order the
overrides should be applied.

You can fix this problem by adding the key "override-order" to the root
composer.json file of your project. In this key, you can define the order in
which packages should override a path in the repository:

```json
{
    "name": "vendor/application",
    "require": {
        "acme/blog": "*",
        "acme/blog-extension": "*"
    },
    "extra": {
        "resources": {
            "override": {
                "/acme/blog/css": "resources/acme/blog/css",
            },
            "override-order": {
                "/acme/blog/css": ["acme/blog-extension", "vendor/application"]
            }
        }
    }
}
```

In this example, the application requires the package "acme/blog" and another
package "acme/blog-extension" which overrides the "/acme/blog/css" directory.
To complicate things, the application overrides this path as well. Through
the "override-order" key, you can tell Puli that the overrides in
"vendor/application" should be preferred over those in "acme/blog-extension".

If you query the path of the file style.css again, and if that file exists in
all three packages, you will get a result like this:

```php
echo $locator->get('/acme/blog/css/style.css')->getPath();
// => /path/to/project/resources/acme/blog/css/style.css

print_r($locator->get('/acme/blog/css/style.css')->getAlternativePaths());
// Array
// (
//     [0] => /path/to/project/vendor/acme/blog/assets/css/style.css
//     [1] => /path/to/project/vendor/acme/blog-extension/assets/css/style.css
//     [2] => /path/to/project/resources/acme/blog/css/style.css
// )
```

[Puli library]: https://github.com/webmozart/puli
[Puli documentation]: https://github.com/webmozart/puli/blob/master/README.md
[`OverrideConflictException`]: src/RepositoryLoader/OverrideConflictException.php
[`ResourceDiscoveringInterface`]: https://github.com/webmozart/puli/blob/master/src/ResourceDiscoveringInterface.php
