Composer Resource Integration
=============================

Currently, dealing with file resources (templates, CSS, JS, images, config files, translation files etc.) in PHP has several challenges:

* The developer must know the absolute file system path to each resource.
* Many frameworks offer aliases or some kind of resource identifier to mask the absolute paths. These mechanisms aren't interoperable.
* It is often useful to override/replace resources (template files, CSS files etc.) provided by some package. Again, each framework does so in a different way.
* When using multiple packages that use each other's resources, the end user must wire the packages together manually.
* Usually, some resources (CSS, JS, images) must be put into publically visible directories.

I'm trying to find a solution which lets Composer (or a tool hooking into Composer) solve these problems.

The origin of this Gist lies in [my blog post about uniform resource location](http://webmozarts.com/2013/06/19/the-power-of-uniform-resource-location-in-php/).

Resource Identifiers
--------------------

Some examples for resource identifiers in different frameworks:

### Symfony

Notation 1 (usable for config files, templates, assets):

    @AcmeBlogBundle/Resources/config/config.yml
    @AcmeBlogBundle/Resources/css/style.css

Notation 2 (usable for templates):

    AcmeBlogBundle:ControllerName:template.html.twig

Notation 3 (usable for assets):

    bundles/acme_blog/css/style.css

### Yii

Path aliases:

    Yii::setAlias('@app', '/path/to/app')
    Yii::setAlias('@app/css', '/path/to/css');

    Yii::getAlias('@app/css/style.css');
    // => /path/to/css/style.css

### CakePHP

Template identifiers:

    Contacts.contact
    // => /app/Plugin/Contacts/View/Layout/contact.ctp

### Unified Notation

I propose to use file system paths as identifiers. Each Composer package exports its resources into the (virtual) path

    /vendor/package

or a subdirectory thereof. For example:

    {
        "name": "acme/blog",
        "resources": {
            "export": {
                "": "resources",
                "css": "assets/css"
            },
        },
    }

The resources can then be queried as:

    $repository->getPath('/acme/blog/config/config.yml');
    // => /path/to/acme/blog/resources/config/config.yml

    $repository->getPath('/acme/blog/css/style.css');
    // => /path/to/acme/blog/assets/css/style.css

A stream wrapper can be registered to have access to resources without needing the repository:

    ResourceStreamWrapper::register($repository);

    Yaml::parse('resource:///acme/blog/config/config.yml');

Resource Overrides
------------------

Some examples for how frameworks support overriding of resources:

### Symfony

    @AcmeBlogBundle/Resources/views/layout.html.twig

is usually located in

    /vendor/path/to/acme/blog-bundle/Resources/views/layout.html.twig

but can be overridden in

    /app/Resources/AcmeBlogBundle/views/layout.html.twig

A bundle can override another bundle's resources by using [bundle inheritance](http://symfony.com/doc/current/cookbook/bundles/inheritance.html).

### CakePHP

The identifier

    Contacts.contact

is usually located in

    /app/Plugin/Contacts/View/Layout/contact.ctp

but can be overridden in

    /app/View/Plugin/Contacts/Layout/contact.ctp

### Unified Notation

I propose to handle resource overriding centrally. Each package is able to override another packages resources. For example, the root package:

    {
        "resources": {
            "export": {
                "": "resources"
            },
            "override": {
                "acme-blog": "/acme/blog"
            }
        }
    }

Now the files in `resources/acme-blog/` override the files in `vendor/acme/blog/resources`.

Resource Wiring
---------------

When using multiple packages that use each other's resources, a lot of work has to be done to register the resources of one package with the classes of another. For example:

    $translator = new Translator('en');
    $translator->addResource('xlf', '/path/to/acme/foo/resources/trans/messages.en.xlf');
    $translator->addResource('xlf', '/path/to/acme/bar/resources/trans/messages.en.xlf');

Also look at [this real-life example](https://github.com/bschussek/standalone-forms/blob/2.1%2Btwig/src/setup.php), which is naturally much more complex.

I propose to use resource tags to do this wiring automatically:

    {
        "name": "acme/blog",
        "resources": {
            "export": {
                "": "resources"
            },
            "tag": {
                "*.xlf": "symfony/translator/xlf"
            }
        }
    }

Then we can do:

    $translator = new Translator('en', $repository);

which internally does

    foreach ($repository->getTaggedPaths('symfony/translator/xlf') as $resource) {
        $this->addResource('xlf', $resource);
    }

Alternatively, we could use MIME types as tags.

Resource Installation
---------------------

For installing resources (i.e. assets) in publically visible directories, we have two options (that can co-exist):

Option 1: We tag the resources so that another installed package copies them.

    {
        "name": "acme/blog",
        "resources": {
            "export": {
                "": "resources"
            },
            "tag": {
                "*.css": "assetic/css"
            }
        }
    }

Option 2: We allow the root package to define target paths for publically visible resources.

    {
        "resources": {
            "public-path": "public",
            "publish": {
                "/*.css": "public/assets"
            }
        }
    }

The resource `/acme/blog/css/style.css` would then be copied/linked to `public/assets/acme/blog/css/style.css`. The path relative to the public directory can be queried from the repository:

    $repository->getPublicPath('/acme/blog/css/style.css');
    // => assets/acme/blog/css/style.css

As you can see, we didn't use a path relative to the package's resources here (like `*.css`), but a path relative to the root of the resource repository (`/*.css`).

Edge Cases
----------

All of the above solutions obviously have edge cases which need to be considered. Nevertheless, I think we could start with this simple solution to see which of these edge cases really need to be solved, and how they can be solved.
