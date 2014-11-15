Changelog
=========

* 1.0.0-alpha2 (@release_date@)

 * renamed `RepositoryLoader` to `RepositoryBuilder`
 * renamed `OverrideConflictException` to `ResourceConflictException`
 * refactored logic from `PuliPlugin` to `RepositoryDumper` which is tested now
 * resources are now defined directly in the "resources" key
 * the "tags" key was moved to "extra" and renamed to "resource-tags"
 * the "override" key was moved to "extra". Its value is now the name(s) of
   the overridden package
 * the "override-order" key was moved to "extra" and renamed to "package-order".
   Its value is a list of package names now
 * install paths of other packages can now be referenced using the syntax
   "@vendor/package:path"

* 1.0.0-alpha1 (2014-02-05)

 * first alpha release
