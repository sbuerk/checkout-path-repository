# Limitations

## 1. The bootstrap run does not see the checkouts

Repositories and the root package are created before plugins are activated,
and the `composer update` that installs the plugin has finished resolution
before the plugin exists. That first run installs the plugin only – the
checkouts follow with the next `composer update`. The plugin is activated at
the end of the first run, so it already prints its notices there, but that is
too late for resolution.

Handle it in scripts with the status command, which exits with `3` right after
the bootstrap run:

```bash
composer update
composer checkouts:status || composer update
```

(`PluginLifecycleTest` runs exactly this sequence.)

This is also why the checkout packages must not be required statically: a
static requirement would have to be resolved in the bootstrap run, before the
plugin could register the checkouts.

## 2. Lock files and optional checkouts

The set of installed packages depends on which checkouts are present on the
machine, so a `composer.lock` is machine specific:

* **Checkout added since the lock was written** (cloned, or access granted):
  the plugin adds its requirement, and `composer install` rejects the lock
  with exit code `4` ("Required package ... is not present in the lock
  file"). Run `composer update`; `checkouts:status` reports `not-installed`.
* **Checkout removed since the lock was written**: the lock still contains a
  `path` package whose source is gone, and `composer install` fails with
  `Source path "../packages/..." is not found`. Run `composer update`;
  `checkouts:status` reports `stale`.

Do not commit the `composer.lock` of a project that uses optional checkouts,
or accept that `composer update` is the command to run there.

## 3. New clones need a new composer run

`checkouts:clone` does not install anything. Repositories are registered when
composer starts, so the process running the command cannot see directories
it created. Run `composer update` afterwards.

## 4. An invalid manifest breaks every composer command

The configuration is validated in `activate()`, which runs for every command.
A broken manifest therefore fails `composer show` and `composer remove` as
well – deliberately, a silently ignored manifest would drop checkouts without
notice. Fix the manifest, or run a single command with `--no-plugins`.

## 5. `--no-plugins` disables everything

With `--no-plugins` no checkout is registered or required. `composer install`
from an existing lock file still installs the locked checkouts; an update
removes them.

## 6. Interaction with other `path` repository plugins

The version pin uses the stock `options.versions` option. A plugin that
determines path package versions with higher precedence replaces it – for
example `sbuerk/extended-path-repository`, which ranks
`extra."typo3/cms".version` of a package above `options.versions` (see its
`docs/version-detection.md`). The root requirement (the manifest version) may
then no longer match the reported version. Do not combine the two for the
same packages, or set the manifest `version` accordingly.

## 7. Non-interactive git and ssh host keys

`checkouts:clone` runs ssh with `BatchMode=yes`. In a fresh environment
without a `known_hosts` entry for the git host (a new container, for
example) ssh refuses the unknown host key and the remote is reported as not
accessible. Add the host key, or set `GIT_SSH_COMMAND` explicitly, e.g.
`ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new` if trust on first
use is acceptable for you – an explicit `GIT_SSH_COMMAND` is used unchanged.

## 8. Locking across machines

The clone lock is an advisory `flock()`. It works between processes sharing a
kernel, including containers bind mounting the same host directory on Linux.
It is not reliable on network file systems or across the host/VM boundary of
some container runtimes (e.g. file sharing of Docker Desktop on macOS).

## 9. Working directory

`path` repository urls are resolved against the working directory, and so are
the urls the plugin registers. Run composer from the project directory (or with
`--working-dir`), not with `COMPOSER=path/to/composer.json` from elsewhere,
otherwise the relative urls in the lock file differ.

## 10. Platform

Developed and tested on Linux. Windows is untested: checkouts are installed as
symlinks (composer falls back to junctions or copies there), and the test of
the non-interactive ssh environment is skipped.

## See also

* [Installation](installation.md#bootstrap-sequence-of-a-fresh-project)
* [Commands](commands.md)
* [How it works (architecture)](architecture.md)
