Composer Puli Plugin
====================

This plugin integrates the [Puli library] into Composer. With this plugin,
managing and accessing the files (*resources*) of your Composer packages
becomes a breeze.

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
// => /path/to/vendor/acme/demo/assets/css/style.css
```

[Puli library]: https://github.com/webmozart/puli
