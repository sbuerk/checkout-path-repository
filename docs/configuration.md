# Configuration & manifest

The plugin is configured in the `extra` section of the **root**
`composer.json`, below the key `sbuerk/checkout-path-repository`. Without that
key the plugin does nothing (and the commands fail with a hint).

There are two mutually exclusive shapes.

## External manifest (recommended)

```json
{
    "extra": {
        "sbuerk/checkout-path-repository": {
            "manifest": "../packages/checkouts.json"
        }
    }
}
```

* `manifest` – path to the manifest file, relative to the directory of the
  root `composer.json` (absolute paths work, but are not portable).
* Checkout paths inside the manifest are relative to the **manifest
  directory**, and the clone lock file is created there as well
  (`.checkouts.lock`).

A separate file lets several composer projects share one set of checkouts –
for example one development instance per framework version, all installing
the same checkouts – and gives tooling (test runners, setup scripts) one place
to read the package list from.

## Inline definitions

```json
{
    "extra": {
        "sbuerk/checkout-path-repository": {
            "directory": "packages",
            "packages": {
                "vendor/library": { "url": "...", "branch": "main", "version": "1.x-dev" }
            }
        }
    }
}
```

* `directory` – directory the checkout paths are relative to, relative to the
  root `composer.json` directory. Optional, defaults to that directory.
* `packages` – the same map as in a manifest file.

`manifest` and `packages` cannot be combined, and `directory` is only allowed
with `packages`. Other keys are rejected.

## Manifest schema

```json
{
    "line": { "id": "1", "cores": [12, 13] },
    "packages": {
        "web-vision/deepl-base": {
            "url": "git@github.com:web-vision/deepl-base.git",
            "branch": "1",
            "version": "1.0.x-dev",
            "path": "deepl-base",
            "require": true
        }
    }
}
```

Top level:

| Key        | Required | Meaning                                                                 |
|------------|----------|-------------------------------------------------------------------------|
| `packages` | yes      | Object: package name => definition. May be empty. Order is kept.         |
| any other  | no       | Ignored by the plugin, free for tooling (e.g. `line` above).             |

Per package (the key is the composer package name, lower case):

| Key       | Required | Type    | Default                   | Meaning |
|-----------|----------|---------|---------------------------|---------|
| `url`     | yes      | string  | –                         | Git url `checkouts:clone` clones from (ssh, https, local path, `file://`). |
| `branch`  | yes      | string  | –                         | Branch `checkouts:clone` checks out, and the "expected branch" of `checkouts:status`. |
| `version` | yes      | string  | –                         | Version the path repository reports for the checkout (`options.versions`) and the constraint of the added root requirement, e.g. `1.19.x-dev`, `5.1.x-dev`, `dev-main`. |
| `path`    | no       | string  | last segment of the name  | Checkout directory, relative to the manifest directory. |
| `require` | no       | boolean | `true`                    | Add `name => version` to the root requirements when the checkout is present. |

### Why `version` is not taken from the branch

Composer derives the version of a path package from the checked-out branch:
branch `5` becomes `5.x-dev`, branch `main` becomes `dev-main`. Neither
satisfies constraints such as `~5.1.10@dev` or `~1.19.0@dev` that the
packages use among each other, and a feature branch checked out for a fix
would change the version again. The manifest version is fixed per checkout,
so resolution does not depend on what is checked out.

### `require: false`

The checkout is still registered (and wins over other sources) and gets the
`dev` stability flag, but it is not required by the root package. Use it for
packages that are only needed when another package requires them, or that are
kept around for occasional work.

### Validation

The plugin validates configuration and manifest strictly and reports **all**
problems at once. It fails the composer run on:

* a missing manifest file or invalid JSON;
* a manifest that is not an object or has no `packages` object;
* a package name that is not a valid, lower-case `vendor/name`;
* a definition that is not an object, has unknown keys, or misses `url`,
  `branch` or `version`;
* a `url` or `branch` starting with `-` or a `branch` containing whitespace
  (they are passed to git as arguments);
* a `version` composer cannot parse as a version (constraints like `^5.1` are
  not versions);
* an absolute `path`, a `path` pointing to the manifest directory itself, or
  two packages sharing one path;
* a non-boolean `require`.

Why strict: the manifest is shared between machines and drives git clones.
A typo in a package entry would otherwise just drop a checkout without
anybody noticing.

Paths must be relative because the same manifest is used on different
machines and inside containers.

## See also

* [Commands](commands.md)
* [How it works (architecture)](architecture.md)
* [Limitations](limitations.md)
