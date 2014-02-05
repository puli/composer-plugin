Composer Puli Plugin
====================

This plugin integrates the [Puli library] into Composer. With this plugin,
managing and accessing the files (*resources*) of your Composer packages
becomes a breeze.

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
    "name": "acme/demo",
    "extra": {
        "resources": {
            "export": {
                "/acme/demo": "resources",
                "/acme/demo/css": "assets/css"
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

echo $locator->get('/acme/demo/css/style.css')->getPath();
// => /path/to/project/vendor/acme/demo/assets/css/style.css
```

Overriding Resources
--------------------

Each package can override the resources of another package. To do so, add the
path you want to override to the "override" key:

```json
{
    "name": "acme/demo-extension",
    "require": {
        "acme/demo": "*"
    },
    "extra": {
        "resources": {
            "override": {
                "/acme/demo/css": "assets/css"
            }
        }
    }
}
```

The resources in the "acme/demo-extension" package are now preferred over those
in the "acme/demo" package. If a resource was not found in the overriding
package, the resource from the original package will be returned instead.

You can get all paths for an overridden resource using the
`getAlternativePaths()` method. The paths are returned in the order in which
they were overridden, with the original path coming first:

```php
print_r($locator->get('/acme/demo/css/style.css')->getAlternativePaths());
// Array
// (
//     [0] => /path/to/project/vendor/acme/demo/assets/css/style.css
//     [1] => /path/to/project/vendor/acme/demo-extension/assets/css/style.css
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
    "vendor/application",
    "require": {
        "acme/demo": "*",
        "acme/demo-extension": "*"
    },
    "extra": {
        "resources": {
            "override": {
                "/acme/demo/css": "resources/acme/demo/css",
            },
            "override-order": {
                "/acme/demo/css": ["acme/demo-extension", "vendor/application"]
            }
        }
    }
}
```

In this example, the application requires the package "acme/demo" and another
package "acme/demo-extension" which overrides the "/acme/demo/css" directory.
To complicate things, the application overrides this path as well. Through
the "override-order" key, you can tell Puli that the overrides in
"vendor/application" should be preferred over those in "acme/demo-extension".

If you query the path of the file style.css again, and if that file exists in
all three packages, you will get a result like this:

```php
echo $locator->get('/acme/demo/css/style.css')->getPath();
// => /path/to/project/resources/acme/demo/css/style.css

print_r($locator->get('/acme/demo/css/style.css')->getAlternativePaths());
// Array
// (
//     [0] => /path/to/project/vendor/acme/demo/assets/css/style.css
//     [1] => /path/to/project/vendor/acme/demo-extension/assets/css/style.css
//     [2] => /path/to/project/resources/acme/demo/css/style.css
// )
```

[Puli library]: https://github.com/webmozart/puli
[`OverrideConflictException`]: src/RepositoryLoader/OverrideConflictException.php
