# Installation

## Requirements

* PHP **8.1+** – the floor of TYPO3 v12 projects this plugin was built for.
* Composer **2.3+** (`composer-plugin-api: ^2.3`), see
  [architecture](architecture.md#composer-plugin-api-constraint) for why.
* `git` on the `PATH` for the `checkouts:*` commands.

## Add the plugin

```bash
composer require --dev sbuerk/checkout-path-repository
```

or, as a local package in a mono repository, through a `path` repository:

```json
{
    "repositories": {
        "packages-dev": { "type": "path", "url": "../packages-dev/*" }
    },
    "require": {
        "sbuerk/checkout-path-repository": "@dev"
    },
    "config": {
        "allow-plugins": {
            "sbuerk/checkout-path-repository": true
        }
    }
}
```

`allow-plugins` is required: the plugin declares `plugin-optional: false`, so a
non-interactive composer run fails instead of silently running without it (the
checkouts would just not be installed, which is hard to notice). This also
applies when the plugin is pulled in by another package: the consuming root
project has to allow it explicitly.

Then configure the manifest, see [configuration](configuration.md).

## Do not require the checkouts statically

Leave the packages of the manifest out of the static `require` section. The
plugin adds a requirement for every checkout that is present; a static
requirement for a checkout that is missing makes resolution fail, which is
exactly what the plugin avoids. If the root package does require a checkout
package itself, its own constraint is kept.

## Bootstrap sequence of a fresh project

Composer creates the repositories before it activates plugins, and the run
that installs the plugin has resolved its dependencies already. A fresh
project therefore needs:

```bash
composer update                  # 1. installs the plugin (checkouts not yet registered)
composer checkouts:clone         # 2. clones missing checkouts (optional)
composer checkouts:status \
    || composer update           # 3. exit code 3 -> install the checkouts
```

Scripts can always run the last line: it is a no-op when everything is in
sync. The same line covers checkouts cloned later on.

## No plugin ordering hints

The package declares `plugin-optional: false` only. Composer's ordering hints
`plugin-modifies-downloads` and `plugin-modifies-install-path` are not set:
the plugin changes neither downloads nor install paths, and installing it
first in the run that installs it would not help – resolution has happened by
then (see the bootstrap sequence above).

## IDE: exclude the plugin's own tooling

When the plugin is installed from a local directory (a `path` repository with
`symlink: true`), `vendor/sbuerk/checkout-path-repository` points at that
directory – including its development `vendor/` (composer, PHPUnit, PHP CS
Fixer) once `composer install` ran there. Exclude
`<plugin directory>/vendor` and `<plugin directory>/.cache` in the IDE, next
to the other excluded build directories, to avoid duplicate class indexing.

## See also

* [Configuration & manifest](configuration.md)
* [Commands](commands.md)
* [Limitations](limitations.md)
