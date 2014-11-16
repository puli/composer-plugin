Changelog
=========

* 1.0.0-alpha2 (@release_date@)

 * renamed `RepositoryLoader` to `RepositoryBuilder`
 * renamed `OverrideConflictException` to `ResourceConflictException`
 * refactored logic from `PuliPlugin` to `RepositoryDumper` which is tested now
 * install paths of other packages can now be referenced using the syntax
   "@vendor/package:path"
 * renamed "resources" configuration key to "puli"
 * renamed "export" configuration key to "resources"
 * removed `PathMatcher`; its logic was moved to `Puli\Util\Path`

* 1.0.0-alpha1 (2014-02-05)

 * first alpha release
