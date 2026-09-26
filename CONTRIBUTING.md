# Contributing

Thanks for considering a contribution! This project is held to the same quality
bar as TYPO3 Core work: correctness first, small and reviewable changes, and
standards-compliant commits.

## Getting started

1. Clone the repository.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Create a topic branch.

See [docs/development.md](docs/development.md) for the project layout and the
testing strategy.

## Before you open a pull request

Run the full QA suite locally and make sure it is green:

```bash
composer ci
```

This runs, in order:

* `composer ci:php:cs` – coding style (PHP CS Fixer). Auto-fix with
  `composer fix:cs`.
* `composer ci:php:stan` – static analysis (PHPStan, level `max`).
* `composer ci:tests:unit` – unit tests.
* `composer ci:tests:integration` – integration tests; they need `git`.

New behaviour must be covered by tests – behaviour that depends on composer
internals by an integration test running the real composer classes. The CI
pipeline runs the tests on PHP 8.1 – 8.5 with the lowest and highest
dependencies.

## Coding standards

* Target **PHP 8.1+** and **composer 2.3+** (`composer-plugin-api: ^2.3`); do
  not use newer APIs. The lowest-dependency CI jobs run against
  `composer/composer` 2.3.
* `declare(strict_types=1);` in every PHP file.
* Services are **stateless**; dependencies are passed to the constructor.
* Nothing is written to `/tmp` or `sys_get_temp_dir()` – tests work below
  `.cache/tests/`.
* Keep `activate()` cheap and local: no network, no git.

## Commit messages

Commit messages follow the
[TYPO3 Core commit message rules](https://docs.typo3.org/m/typo3/guide-contributionworkflow/main/en-us/Appendix/CommitMessage.html):

* A subject line prefixed with a tag and **no longer than 52 characters**, e.g.
  `[FEATURE] …`, `[BUGFIX] …`, `[TASK] …`, `[DOCS] …`.
* A blank line, then a body wrapped at **72 characters** explaining *what* and
  *why*.
* Footer keywords where applicable.

As this project currently has no issue tracker, the `Resolves:` / `Releases:`
footer lines are omitted.

### Example

```
[BUGFIX] Keep root constraint of required checkouts

A root package requiring a checkout package itself must keep its own
constraint, the manifest version is only used when the root package
does not mention the package.
```

## Reporting issues

When reporting a bug, please include your PHP, Composer and git versions, the
plugin configuration of your root `composer.json`, the manifest (urls may be
redacted), and the exact command with its output (`-v`).
