# How it works (architecture)

## Where the plugin hooks in: `activate()`

Composer builds a project in this order (`Factory::createComposer()`):

1. the repository manager is created;
2. the root package is loaded – repositories declared in `composer.json` are
   instantiated and the **stability flags of the root requirements are
   computed** here;
3. local repository, installation and download managers are created;
4. installed plugins are loaded – **`activate()` runs here**;
5. `PluginEvents::INIT` is dispatched.

`update` builds its repository set from the repository manager later, in
`Installer::doUpdate()`. So `activate()` is the earliest point at which a
plugin can add repositories and requirements that dependency resolution sees –
and they are also visible to `show`, `why`, `outdated`, `require` and every
other command. The alternatives are worse:

* `pre-update-cmd` / `pre-install-cmd` are script events: skipped with
  `--no-scripts` and invisible to all other commands;
* `PluginEvents::PRE_POOL_CREATE` fires after the repository set exists and
  can only filter packages.

`activate()` therefore does only what is cheap and local: read the manifest,
check which checkout directories contain a `composer.json`, register them and
extend the in-memory root package.

### Errors and notices from `activate()`

An invalid configuration is written to the error output and then rethrown.
Writing it first matters: while composer collects the commands of plugins
(`Application::getPluginCommands()`), it creates the composer instance and
swallows exceptions. Without the write, `composer checkouts:status` with a
broken manifest would only report that there is no `checkouts` command. The
rethrow keeps every command failing, see [limitations](limitations.md#4-an-invalid-manifest-breaks-every-composer-command).

`activate()` runs for every command, so the notice about checkouts that are
not present is verbose there. `activate()` registers a listener for
`PluginEvents::COMMAND` on the composer instance it is given, which prints the
one-line notice for `install`, `update`, `require`, `remove` and `reinstall` – the
commands where it explains a missing package. A listener closure bound to the
instance keeps the plugin object free of state (the event itself carries
neither the composer instance nor the IO).

## Why no git operation in `activate()`

`activate()` runs on **every** composer invocation, including `show`,
`dump-autoload` and shell completion, and inside containers that often have
no ssh agent or run as another user. Cloning there would add network round
trips to every call, prompt or hang on credentials, and could not be turned
off. Cloning is an explicit command (`checkouts:clone`) instead; `activate()`
just reports what is missing.

## Why the repositories are prepended

In composer 2 all repositories are canonical: the first repository providing a
package wins, lower ones are not consulted for it. `RepositoryManager::addRepository()`
appends, i.e. after packagist.org – packagist would provide the public
packages and the checkouts would be ignored. `prependRepository()` puts the
checkouts before all other repositories, in manifest order.

## Why the version is pinned (`options.versions`)

A `path` repository determines a package version from `options.versions`
first, then (for packages in the root's own git repository) from
`COMPOSER_ROOT_VERSION`, then from the checked out branch. The branch is a
poor source for a set of checkouts:

* numeric branches become `N.x-dev` (branch `5` -> `5.x-dev`, which composer
  treats as `5.9999999.9999999.9999999-dev`), outside of `~5.1.10`;
* `main` becomes `dev-main`, which only matches through a `branch-alias`;
* a feature branch for a fix changes the version again.

The manifest `version` is passed as `options.versions` – a stock option of the
`path` repository, so no composer internals are replaced. The integration test
`InstallTest::stockPathRepositoriesCannotResolveTheFleetWithoutPinnedVersions`
shows the failing stock resolution for a package on `main` required as
`~1.19.0@dev`; the pinned setup resolves it.

## Root requirements and stability flags

For every present checkout with `"require": true` a requirement
`name => version` is added to the in-memory root package
(`RootPackageInterface::setRequires()`); `composer.json` is never written. A
requirement the root package declares itself is kept.

Stability flags are computed when the root package is loaded – before
`activate()` – so a requirement added later is not covered: `1.19.x-dev` would
be rejected by the default `minimum-stability: stable`. The plugin therefore
also sets the `dev` flag (`setStabilityFlags()`) for every present checkout.
Flags apply per package name, not per requirement, so checkouts with
`"require": false` get the flag, too: an `@dev` in the constraint of another,
non-root package does not lift `minimum-stability` (found by the integration
test `InstallTest::optedOutCheckoutIsInstalledOnlyWhenAnotherPackageRequiresIt`).

## Relative urls and symlinks

The repository url is the checkout path relative to the working directory –
composer resolves `path` urls against it – and the repository uses
`symlink: true` and `relative: true`. The result is a relative symlink
(`vendor/vendor/library -> ../../../packages/library/`) and a relative
`dist.url` in `composer.lock` / `installed.json`. Both stay valid when the
project is mounted at another absolute location, e.g. inside a container, as
long as the relative layout is the same.

## Installed "from the checkout"

`checkouts:status` has to predict whether `composer install` works with what
is on disk, so it compares every checkout with two sources:

* **`vendor/composer/installed.json`, read from disk.** Composer's own local
  repository cannot be used: `Factory::createComposer()` purges every package
  whose install path is not readable – which is exactly the dangling vendor
  symlink a removed checkout leaves behind. A status built on it reports a
  removed checkout as `missing` (in sync) while `composer install` fails.
* **The lock file**, if there is one – `composer install` works from it. A
  locked checkout package whose checkout is gone fails with `Source path ...
  is not found`; a present, required checkout that is not locked makes
  `install` reject the lock (exit code `4`).

A package record counts as "from the checkout" when its dist type is `path`
and either

* its install path resolves – following the vendor symlink – to the checkout
  directory (`realpath()` of both), or
* its `dist.url`, resolved against the root directory, is the checkout
  directory. This covers mirrored installs (composer falls back to copying
  when symlinks are not possible), checkouts that disappeared, and lock file
  entries.

The resulting state: a missing checkout that is still installed or locked from
its path is `stale`; a present checkout installed or locked from elsewhere is
`other-source`; a present, required checkout absent from `installed.json` or
from an existing lock is `not-installed`; a version that does not match the
manifest `version` (as a constraint) in either source is `version-mismatch`.

Packages installed or locked as `path` packages from a directory below the
checkout directory, but without a manifest entry, are listed too. A package
removed from the manifest is not registered any more, but the lock still
references its directory: when that directory is gone as well, `install`
fails (`orphaned`, out of sync). Only the checkout directory is scanned, so
`path` packages elsewhere in the project are never reported.

`ComposerBinaryTest` checks this with composer's real binary in a separate
process – an in-process test keeps PHP's stat cache and would still resolve a
removed checkout through the vendor symlink.

## Cloning into a temporary directory

`checkouts:clone` clones into a hidden sibling of the target,
`.<directory>.clone-<pid>`, and `rename()`s it into place when git is done.
Another project running `composer update` at the same time (the lock only
serialises clone runs) therefore never registers a half-written working tree,
and a clone killed hard (a container being stopped) leaves a temporary
directory instead of a directory that looks like a checkout. Leftovers are
removed by the next clone run, which holds the lock and so knows that no other
clone is in progress. The sibling is on the same file system, so the rename is
atomic. An empty target directory is replaced; any other existing directory is
left alone.

## Timeouts of remote git calls

ssh gets `-o ConnectTimeout=15` next to `BatchMode=yes`, so an unreachable
host fails within seconds instead of after composer's `process-timeout`
(300 seconds by default) – per package, which adds up when containers start.
The access check (`git ls-remote`) also runs with a process timeout of its own
(60 seconds, or composer's `process-timeout` if lower), which covers https
remotes without a connect timeout. The clone itself uses composer's
`process-timeout`; large first clones may need `COMPOSER_PROCESS_TIMEOUT`.

## Locking of `checkouts:clone`

Several projects can share one checkout directory and be set up at the same
time (for example two containers started in parallel). The command takes an
exclusive, advisory `flock()` on `<manifest directory>/.checkouts.lock`
before looking at any directory and holds it until it is done, so a second
run finds the first run's clones and leaves them alone. The operating system
releases the lock when a process dies, so there are no stale locks. The lock
file is not deleted after use on purpose: removing it while another process
waits for it would let a third process lock a new file at the same time.

## Composer plugin API constraint

The plugin requires `composer-plugin-api: ^2.3`:

* composer reports plugin API `2.3.0` for composer 2.3 – 2.5, `2.6.0` for
  2.6 – 2.8 and `2.9.0` from 2.9 on (`PluginInterface::PLUGIN_API_VERSION` of
  the release tags). There is no plugin API version for 2.4, 2.5, 2.7 or 2.8.
* The newest API used is `ProcessExecutor::execute()` with an array command
  (no shell, no escaping), added in composer 2.3. Stability flags use the
  `BasePackage::STABILITY_*` constants, which exist in all composer 2 versions
  (`BasePackage::STABILITIES` does not exist in 2.3).
* Verified: the complete unit and integration suite passes against
  `composer/composer` 2.3.0 (PHP 8.1 and 8.5) and 2.10.3 (PHP 8.1, 8.2, 8.5).
  The CI lowest-dependency jobs set `COMPOSER_NO_SECURITY_BLOCKING=1`, as
  composer 2.9+ refuses to resolve versions with security advisories and would
  otherwise test against the newest composer only.

`composer/semver` is used but not required: composer ships it, its classes are
part of the plugin API surface (`Link::getConstraint()`), and plugins always
run with composer's own copy. Requiring it would only add an unused copy to
the project's `vendor/`.

## Class overview

| Class | Responsibility |
|-------|----------------|
| `Plugin` | Entry point: `activate()` loads the configuration and delegates to the registrar; provides the command capability. |
| `Configuration\ConfigurationLoader` | Validates `extra."sbuerk/checkout-path-repository"`, reads the manifest file, resolves the root directory. |
| `Manifest\ManifestParser` | Strict manifest validation, produces the value objects. |
| `Manifest\Manifest`, `Manifest\CheckoutDefinition` | Immutable manifest and package definition (absolute paths resolved). |
| `Repository\RepositoryRegistrar` | Prepends path repositories, extends root requirements and stability flags, reports missing checkouts. |
| `Git\GitCheckout`, `Git\GitResult` | `git` calls through composer's `ProcessExecutor`, non-interactive environment. |
| `Lock\CheckoutLock`, `Lock\AcquiredLock` | `flock()` with timeout and wait notice. |
| `Status\StatusResolver`, `Status\CheckoutStatus` | Compares checkouts with `installed.json` and the lock file. |
| `Command\CloneCommand`, `Command\StatusCommand`, `Command\CommandProvider` | The `checkouts:*` commands. |

All services are stateless: dependencies are passed to the constructor
(nullable, defaulting to the real implementation – composer plugins have no
dependency injection container), everything else is passed per call.

## See also

* [Limitations](limitations.md)
* [Configuration & manifest](configuration.md)
* [Development & testing](development.md)
