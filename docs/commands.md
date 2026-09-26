# Commands

Both commands are provided through composer's `CommandProvider` capability and
are available once the plugin is installed.

## `checkouts:clone`

```
composer checkouts:clone [--strict] [<package>...]
```

Clones every checkout of the manifest (or only the given packages) whose
directory does not exist or is empty:

```
git clone --branch <branch> -- <url> <parent>/.<directory>.clone-<pid>
mv <parent>/.<directory>.clone-<pid> <path>
```

* **Existing directories are never modified** – no fetch, pull, branch switch
  or re-clone. A checkout is yours once it exists. An existing directory
  without a `composer.json` is reported with a warning and counted as
  "unusable" (it is not a checkout the plugin can register; remove it to clone
  again). Only an **empty** directory is replaced by the clone.
* **Rewritten urls are named**: when the git configuration rewrites the
  manifest url (`url.<base>.insteadOf`, e.g. ssh to https in CI), messages
  show both, e.g. `git@github.com:vendor/x.git (rewritten to
  https://github.com/vendor/x.git by git config)` – resolved locally with
  `git ls-remote --get-url`.
* **Complete or nothing**: the clone is written to a hidden temporary sibling
  and renamed into place when git is done, so a concurrent `composer update`
  never sees a half-written checkout and an interrupted clone leaves no
  broken checkout behind. Leftover temporary directories of killed runs are
  removed by the next run.
* **Access check first**: `git ls-remote --exit-code --heads <url>
  refs/heads/<branch>`. A remote that cannot be reached without interaction –
  a private repository without access, no ssh agent, a missing branch – is
  skipped with a notice.
* **Never prompts**: remote git calls run with, for the call only,
  * `GIT_TERMINAL_PROMPT=0` – no terminal credential prompt;
  * `LC_ALL=C` – untranslated git messages, so the reason of a skipped remote
    is reported reliably;
  * `GIT_ASKPASS=` (empty, unless you set it) – git skips `core.askPass` and a
    graphical `SSH_ASKPASS` for credentials;
  * `GCM_INTERACTIVE=never` (unless you set it) – no Git Credential Manager
    dialog;
  * `GIT_SSH_COMMAND="ssh -o BatchMode=yes -o ConnectTimeout=15"`, unless
    `GIT_SSH_COMMAND` or `GIT_SSH` is set already. To customise ssh (for
    example `-o StrictHostKeyChecking=accept-new` in a fresh container without
    `known_hosts`), set `GIT_SSH_COMMAND` yourself – it is used as is.
* **Bounded waiting**: the access check gives up after 60 seconds (or
  composer's `process-timeout`, if lower), unreachable ssh hosts after 15
  seconds. The clone itself uses composer's `process-timeout` (300 seconds by
  default); raise it with `COMPOSER_PROCESS_TIMEOUT` for very large
  repositories.
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
| `1`  | Invalid configuration (see below), unknown package name, lock timeout, a `git clone` that failed after a successful access check, or – with `--strict` – any inaccessible remote. |

## `checkouts:status`

```
composer checkouts:status [--format=table|json]
```

Lists every checkout of the manifest, followed by the packages installed or
locked from a directory below the checkout directory that have no manifest
entry (any more):

| Column    | Content |
|-----------|---------|
| Package   | Name, `(optional)` for `"require": false`, `(not in manifest)` for packages without manifest entry. |
| Path      | Checkout path relative to the root project. |
| Present   | Directory with a `composer.json` exists. |
| Branch    | Branch from the manifest, `-` without manifest entry. |
| Current   | Checked out branch, `-` for a detached HEAD or no own git working tree. |
| Dirty     | Uncommitted changes (including untracked files). |
| Installed | Installed version from `vendor/composer/installed.json` (read from disk), `(other source)` if not installed from the checkout. |
| State     | See below. |

States:

| State              | In sync | Meaning |
|--------------------|---------|---------|
| `ok`               | yes     | Present and installed from the checkout with a version matching the manifest. |
| `missing`          | yes     | Not present and not installed from its location. Nothing to do. |
| `unused`           | yes     | Present, `"require": false` and not installed (nothing requires it). |
| `not-installed`    | **no**  | Present and required, but not installed or not in the lock file – e.g. cloned after the last update; `composer install` rejects the lock then (exit code `4`). Also a present `"require": false` checkout that is locked (another package requires it) but not installed, e.g. after removing `vendor/`. |
| `other-source`     | **no**  | Present, but installed or locked from somewhere else (packagist.org, another path). |
| `version-mismatch` | **no**  | Installed or locked from the checkout, but the manifest `version` changed since. |
| `stale`            | **no**  | Installed or locked from the checkout location, but the checkout is gone. `composer install` fails with `Source path ... is not found` then. |
| `orphaned`         | **no**  | No manifest entry (removed from it), installed or locked from a directory below the checkout directory that holds no package any more. `composer install` fails with `Source path ... is not found`; `composer update` removes the package. |
| `unmanaged`        | yes     | No manifest entry, installed or locked from a directory below the checkout directory that still holds the package – e.g. removed from the manifest but kept on disk, or installed through a `path` repository of the root `composer.json`. Informational; the next `composer update` removes it unless something else requires it. |

The states are computed from `installed.json` as written on disk and from
the lock file – not from composer's in-memory list of installed packages,
which drops packages whose vendor symlink is dangling. See
[architecture](architecture.md#installed-from-the-checkout).

Branch and local changes never affect the state: working on a feature branch
is the point of having checkouts.

Exit codes:

| Code | Constant                          | Meaning |
|------|-----------------------------------|---------|
| `0`  | `StatusCommand::EXIT_IN_SYNC`     | Nothing to do. |
| `3`  | `StatusCommand::EXIT_OUT_OF_SYNC` | At least one state is not in sync – run `composer update`. |
| `1`  | `StatusCommand::EXIT_FAILURE`     | Invalid format or configuration (see below). |

A dedicated code lets scripts tell "update needed" apart from a failure (`1`,
or composer's own `255` for uncaught exceptions) without parsing output. It is
only meaningful for this command; composer's `install`/`update` use `3` for
another purpose.

### Invalid configuration

The configuration is validated when the plugin is activated, before any
command runs. With an invalid manifest, `checkouts:clone` and
`checkouts:status` print the validation errors followed by composer's own
message that the command does not exist (composer skips the commands of a
plugin that failed to activate) and exit with `1`:

```
checkout-path-repository: invalid configuration in manifest file "/path/to/packages/checkouts.json":
 - "vendor/extension": unknown key(s) "brnach", allowed are "url", "branch", "version", "path", "require"
...
There are no commands defined in the "checkouts" namespace.
```

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
`null` when the checkout is not an own git working tree. `expectedBranch` and
`expectedVersion` are `null` for packages without manifest entry (`orphaned`,
`unmanaged`); `required` is `false` for them.

## See also

* [Configuration & manifest](configuration.md)
* [How it works (architecture)](architecture.md#installed-from-the-checkout)
* [Limitations](limitations.md)
