# Documentation

`sbuerk/checkout-path-repository` is a Composer plugin that installs local git
checkouts listed in a manifest through `path` repositories, pins their
versions, requires them from the root package and skips the ones that are not
present.

## For everyone

* [Installation](installation.md) – adding the plugin and the bootstrap
  sequence of a fresh project.
* [Configuration & manifest](configuration.md) – the root `composer.json`
  options and the manifest schema, including its validation rules.
* [Commands](commands.md) – `checkouts:clone` and `checkouts:status`, their
  options, output and exit codes.

## For contributors and integrators

* [How it works (architecture)](architecture.md) – why the plugin hooks into
  `activate()`, prepends its repositories, pins versions and never clones on
  its own; how `checkouts:status` decides what is installed from a checkout.
* [Limitations](limitations.md) – the bootstrap run, lock files and missing
  checkouts, and other trade-offs.
* [Development & testing](development.md) – toolchain, QA commands, project
  layout and the integration test setup.
* [Contributing](../CONTRIBUTING.md) – workflow, coding standards and commit
  message rules.

## At a glance

| Situation                                   | Stock `path` repository + root require     | `checkout-path-repository`                            |
|---------------------------------------------|--------------------------------------------|-------------------------------------------------------|
| Checkout directory missing                  | `url ... does not exist`, composer aborts  | notice, checkout skipped, no requirement added        |
| Checkout on branch `main`, other package requires `~1.19.0@dev` | `dev-main` does not match, update fails | installed as the manifest version (e.g. `1.19.x-dev`) |
| Public package also on packagist.org        | checkout wins (declared before packagist)  | checkout wins (repository is prepended)               |
| New checkout cloned                         | edit `composer.json`                       | `composer update`, requirement is added in memory     |
| "Do I need to run `composer update`?"       | –                                          | `composer checkouts:status` exit code `3`             |

## See also

* [README](../README.md)
* [CHANGELOG](../CHANGELOG.md)
