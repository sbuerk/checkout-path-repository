# Commands

Both commands are provided through composer's `CommandProvider` capability and
are available once the plugin is installed.

## `checkouts:clone`

```
composer checkouts:clone [--strict] [<package>...]
```

Clones every checkout of the manifest (or only the given packages) whose
directory does not exist:

```
git clone --branch <branch> -- <url> <path>
```

* **Existing directories are never modified** – no fetch, pull, branch switch
  or re-clone, whatever their content. A checkout is yours once it exists.
* **Access check first**: `git ls-remote --exit-code --heads <url>
  refs/heads/<branch>`. A remote that cannot be reached without interaction –
  a private repository without access, no ssh agent, a missing branch – is
  skipped with a notice.
* **Never prompts**: remote git calls run with `GIT_TERMINAL_PROMPT=0` and,
  unless `GIT_SSH_COMMAND` or `GIT_SSH` is set already,
  `GIT_SSH_COMMAND="ssh -o BatchMode=yes"`. Both are set for the call only.
  To customise ssh (for example `-o StrictHostKeyChecking=accept-new` in a
  fresh container without `known_hosts`), set `GIT_SSH_COMMAND` yourself – it
  is used as is.
* **Serialised**: an exclusive `flock()` on `<manifest directory>/.checkouts.lock`
  is held for the whole run. A second run waits (with a notice) and then finds
  the directories cloned by the first one. It gives up after 600 seconds.
* Missing parent directories of a checkout path are created.
* Unknown package names fail the command before anything is cloned; names are
  case-insensitive.

The new checkouts are not installed by the command: repositories are
registered when composer starts, so run `composer update` afterwards (the
command says so when it cloned something).

Exit codes:

| Code | Meaning |
|------|---------|
| `0`  | Done. Inaccessible remotes were skipped with a notice. |
| `1`  | Invalid configuration, unknown package name, lock timeout, a `git clone` that failed after a successful access check, or – with `--strict` – any inaccessible remote. |

## `checkouts:status`

```
composer checkouts:status [--format=table|json]
```

Lists every checkout of the manifest:

| Column    | Content |
|-----------|---------|
| Package   | Name, `(optional)` for `"require": false`. |
| Path      | Checkout path relative to the root project. |
| Present   | Directory with a `composer.json` exists. |
| Branch    | Branch from the manifest. |
| Current   | Checked out branch, `-` for a detached HEAD or no own git working tree. |
| Dirty     | Uncommitted changes (including untracked files). |
| Installed | Installed version from `vendor/composer/installed.json`, `(other source)` if not installed from the checkout. |
| State     | See below. |

States:

| State              | In sync | Meaning |
|--------------------|---------|---------|
| `ok`               | yes     | Present and installed from the checkout with a version matching the manifest. |
| `missing`          | yes     | Not present and not installed from its location. Nothing to do. |
| `unused`           | yes     | Present, `"require": false` and not installed (nothing requires it). |
| `not-installed`    | **no**  | Present and required, but not installed – e.g. cloned after the last update. |
| `other-source`     | **no**  | Present, but installed from somewhere else (packagist.org, another path). |
| `version-mismatch` | **no**  | Installed from the checkout, but the manifest `version` changed since. |
| `stale`            | **no**  | Installed from the checkout location, but the checkout is gone. |

Branch and local changes never affect the state: working on a feature branch
is the point of having checkouts.

Exit codes:

| Code | Constant                          | Meaning |
|------|-----------------------------------|---------|
| `0`  | `StatusCommand::EXIT_IN_SYNC`     | Nothing to do. |
| `3`  | `StatusCommand::EXIT_OUT_OF_SYNC` | At least one state is not in sync – run `composer update`. |
| `1`  | `StatusCommand::EXIT_FAILURE`     | Invalid configuration or format. |

A dedicated code lets scripts tell "update needed" apart from a failure (`1`,
or composer's own `255` for uncaught exceptions) without parsing output. It is
only meaningful for this command; composer's `install`/`update` use `3` for
another purpose.

### JSON output

`--format=json` writes to standard output (notices go to standard error):

```json
{
    "inSync": false,
    "manifest": "/path/to/packages/checkouts.json",
    "directory": "/path/to/packages",
    "checkouts": [
        {
            "name": "vendor/library",
            "path": "../packages/library",
            "present": true,
            "required": true,
            "expectedBranch": "main",
            "currentBranch": "main",
            "dirty": false,
            "expectedVersion": "1.19.x-dev",
            "installedVersion": null,
            "installedFromCheckout": false,
            "state": "not-installed",
            "inSync": false
        }
    ]
}
```

`manifest` is `null` for inline configuration. `currentBranch` and `dirty` are
`null` when the checkout is not an own git working tree.

## See also

* [Configuration & manifest](configuration.md)
* [How it works (architecture)](architecture.md#installed-from-the-checkout)
* [Limitations](limitations.md)
