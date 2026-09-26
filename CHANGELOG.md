# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Composer plugin registering the local git checkouts of a manifest as
  prepended `path` repositories (relative symlinks) in `activate()`, with the
  manifest version pinned through `options.versions`.
- Configuration through `extra."sbuerk/checkout-path-repository"` in the root
  `composer.json`: an external `manifest` file or inline `packages` with an
  optional `directory`. Strict validation reporting all problems at once;
  top-level manifest keys other than `packages` are ignored.
- In-memory root requirements (`name => version`) for present checkouts, with
  a per-package `"require": false` opt-out, and the `dev` stability flag for
  every present checkout.
- A single notice for checkouts that are not present (listed with `-v`).
- `composer checkouts:clone [--strict] [<package>...]`: clones missing
  checkouts after a non-interactive `git ls-remote` access check, never
  modifies existing directories, serialises concurrent runs with a `flock()`
  on `<manifest directory>/.checkouts.lock`.
- `composer checkouts:status [--format=table|json]`: compares the checkouts
  with `vendor/composer/installed.json`; exit code `3`
  (`StatusCommand::EXIT_OUT_OF_SYNC`) when `composer update` is needed.
- Unit and integration test suites, documentation and a CI workflow.
