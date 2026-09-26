# Development & testing

## Requirements

* PHP **8.1+**
* Composer **2**
* `git` **2.32+** (the integration tests isolate git with `GIT_CONFIG_GLOBAL`)

## Setup

```bash
composer install
```

`vendor/`, `composer.lock` and `.cache/` are git-ignored.

## Quality assurance

| Command                           | Description |
|-----------------------------------|-------------|
| `composer ci:php:cs`              | Coding style check (PHP CS Fixer, `@PER-CS`, dry-run). |
| `composer fix:cs`                 | Apply coding style fixes. |
| `composer ci:php:stan`            | Static analysis (PHPStan, level `max`, `src` and `tests`). |
| `composer ci:tests:unit`          | Unit tests. |
| `composer ci:tests:integration`   | Integration tests (real composer operations and git). |
| `composer ci:tests`               | Both test suites. |
| `composer ci`                     | Everything above, in that order. |

The CI workflow (`.github/workflows/ci.yml`) runs the QA job on PHP 8.1 and
both test suites on PHP 8.1 – 8.5 with lowest and highest dependencies. The
lowest jobs set `COMPOSER_NO_SECURITY_BLOCKING=1`: composer 2.9+ does not
resolve package versions with security advisories, which would lift
`composer/composer` from 2.3 (the supported minimum) to the newest release.
To reproduce locally:

```bash
COMPOSER_NO_SECURITY_BLOCKING=1 composer update --prefer-lowest
composer ci:tests
```

## Project layout

```
.
├── src/
│   ├── Plugin.php                         # entry point (activate, capabilities)
│   ├── Command/                           # checkouts:clone, checkouts:status, CommandProvider
│   ├── Configuration/ConfigurationLoader.php
│   ├── Exception/InvalidConfigurationException.php
│   ├── Git/                               # git process wrapper
│   ├── Lock/                              # flock() based clone lock
│   ├── Manifest/                          # value objects and strict parser
│   ├── Repository/                        # path repository registration
│   └── Status/                            # checkout vs. installed comparison
├── tests/
│   ├── Fixtures/checkouts/                # static checkouts + manifest (unit tests)
│   ├── Unit/
│   └── Integration/                       # IntegrationTestCase + scenarios
├── docs/
├── .github/workflows/ci.yml
├── composer.json
├── phpunit.xml.dist
├── phpstan.neon.dist
└── .php-cs-fixer.dist.php
```

## Testing strategy

* **Unit** – manifest and configuration validation (every rule, all errors at
  once), repository registration against a real `RepositoryManager`
  (prepend order, repository config, pinned version, root requirements,
  stability flags, notices), lock contention (two handles in one process and
  a second PHP process), status states.
* **Integration** – `IntegrationTestCase` creates a workspace per test in
  `.cache/tests/integration/<class>/<test>/` with git repositories acting as
  remotes, a manifest, checkouts and a root project. It runs composer's real
  `Factory` and `Installer` in-process, with packagist.org disabled and an
  isolated environment (`COMPOSER_HOME`, cache, git configuration). Scenarios:
  * `InstallTest` – present and missing checkouts, version pinning satisfying
    `~1.19.0@dev` from another checkout (plus the failing stock control
    case), relative symlinks, `"require": false`, a kept root constraint;
  * `CloneCommandTest` – cloning, skipping a non-existing remote and a
    missing branch, `--strict`, existing directories left untouched, package
    selection, parent directories, lock timeout;
  * `StatusCommandTest` – every state and exit code, table and JSON output;
  * `GitCheckoutTest` – the non-interactive environment, verified with a fake
    `ssh` executable, and branch/dirty detection;
  * `PluginLifecycleTest` – the plugin installed from this directory and
    activated by composer's plugin manager: bootstrap run, commands through
    the `CommandProvider`, clone, update, status.

Composer silences expected warnings by lowering `error_reporting()` and relies
on its own error handler to honour that; `IntegrationTestCase` installs an
equivalent handler so PHPUnit does not report them.

Nothing is written outside the package directory: no `/tmp`, no
`sys_get_temp_dir()`.

## See also

* [Contributing](../CONTRIBUTING.md)
* [How it works (architecture)](architecture.md)
