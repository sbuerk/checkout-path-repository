# sbuerk/checkout-path-repository

[![CI](https://github.com/sbuerk/checkout-path-repository/actions/workflows/ci.yml/badge.svg)](https://github.com/sbuerk/checkout-path-repository/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/sbuerk/checkout-path-repository)](https://packagist.org/packages/sbuerk/checkout-path-repository)
[![PHP version](https://img.shields.io/packagist/dependency-v/sbuerk/checkout-path-repository/php)](https://packagist.org/packages/sbuerk/checkout-path-repository)
[![License](https://img.shields.io/packagist/l/sbuerk/checkout-path-repository)](LICENSE)

A [Composer](https://getcomposer.org/) plugin for development setups that work
on many packages at once: it installs a set of **local git checkouts** listed
in a manifest through [`path` repositories](https://getcomposer.org/doc/05-repositories.md#path)
– and simply leaves out the checkouts that are not there.

* **Manifest driven** – one JSON file lists every package with its git `url`,
  `branch` and the `version` it should be installed as.
* **Optional checkouts** – a checkout that is missing (for example a private
  repository you have no access to) is skipped with a notice instead of
  breaking `composer update`.
* **Pinned versions** – each checkout is installed as the manifest version,
  independent of the branch that is checked out, so constraints like
  `~1.19.0@dev` between the checkouts resolve.
* **No hand-maintained requirements** – present checkouts become root
  requirements automatically; `composer.json` is never rewritten.
* **Commands** – `composer checkouts:clone` clones what is missing (without
  prompting, skipping inaccessible remotes), `composer checkouts:status` tells
  scripts whether a `composer update` is due (exit code `3`).

## Quick start

`packages/checkouts.json`:

```json
{
    "packages": {
        "vendor/library": {
            "url": "git@github.com:vendor/library.git",
            "branch": "main",
            "version": "1.19.x-dev"
        },
        "vendor/extension": {
            "url": "git@github.com:vendor/extension.git",
            "branch": "5",
            "version": "5.1.x-dev"
        }
    }
}
```

Root `composer.json` of the project that installs them:

```json
{
    "require": {
        "sbuerk/checkout-path-repository": "@dev"
    },
    "extra": {
        "sbuerk/checkout-path-repository": {
            "manifest": "../packages/checkouts.json"
        }
    },
    "config": {
        "allow-plugins": {
            "sbuerk/checkout-path-repository": true
        }
    }
}
```

```bash
composer update                    # installs the plugin itself
composer checkouts:clone           # clones missing checkouts next to the manifest
composer checkouts:status || composer update   # exit code 3: update needed
```

> [!IMPORTANT]
> Composer creates repositories before plugins are activated. The run that
> installs the plugin cannot register the checkouts yet – the next
> `composer update` does. `composer checkouts:status` exits with `3` in that
> situation, see [docs/limitations.md](docs/limitations.md).

## Requirements

* PHP **8.1+**
* Composer **2.3+** (`composer-plugin-api: ^2.3`)
* `git` for the `checkouts:*` commands

## Documentation

Details live in [`docs/`](docs/Index.md):

* [Installation](docs/installation.md)
* [Configuration & manifest](docs/configuration.md)
* [Commands](docs/commands.md)
* [How it works (architecture)](docs/architecture.md)
* [Limitations](docs/limitations.md)
* [Development & testing](docs/development.md)

## Contributing

Contributions are welcome – please read [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Released under the [GNU General Public License v2.0 or later](LICENSE).
