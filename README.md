# ECSKC Customisation Package

A Joomla component that deploys the ECSKC site's customisations and re-applies them after
extension upgrades.

Every customisation is declared as an XML rule: the file it targets, the payload it installs,
and the conditions under which it applies. The component compares what is on disk against those
declarations, reports the state of each target, and changes a file only when it can prove the
change is safe.

## Why

Extension updates overwrite customised files. Re-applying them by hand is slow and unreliable.
Working out what an upgrade had lost, what had survived, and what the vendor had already fixed
upstream cost a full forensic session. Installing this package turns that into a routine check
run from the Joomla administrator.

## Scope

In scope is how the site looks and what it can do, meaning presentation and behaviour in Joomla
extension and template directories.

Out of scope is what the site says, along with server and SEO configuration. Site content,
`.htaccess` and `robots.txt` live elsewhere.

## Layout

| Path | Contents |
| --- | --- |
| `extensions/pkg_ecskc_customisations/` | Package manifest and install script |
| `extensions/com_ecskccustom/` | Component source, administrator only |
| `extensions/plg_system_ecskccustom/` | System plugin that serves `override.css` on the site |
| `extensions/plg_installer_ecskccustom/` | Installer plugin that checks after extension work |
| `data/rules/` | The rule set, one XML file per extension |
| `data/payload/` | The customised files a rule installs |
| `tests/` | PHPUnit suites |
| `tools/` | Build helpers: deterministic packager, validator, package check, hashing |
| `Makefile` | Build and release entry points |
| `pkg_ecskc_customisations.update.xml` | Update descriptor template; the release build fills in its URL and checksum |

A payload's bytes are its identity, line endings and final newline included, and `data/payload/`
is pinned `-text` in `.gitattributes`. Never reformat a payload file. A normalised copy still
passes every structural check and then matches nothing on disk.

## Build

Requires PHP 8.3 and Composer.

```sh
make deps       # composer install
make test       # PHPUnit
make lint       # PHP syntax and rule XML well-formedness
make validate   # check the rule set against the payload tree
make versions   # check that every manifest declares the package version
make dist_dev   # build an installable dev package ZIP under installation/dev/
make info       # version, package contents, output paths
```

`make dist_dev` builds from the working tree and strips the update server, so a test install
registers no update site. Every build checks the payloads inside the package it has just written
against the package's own rules. Releases are cut with `make release` and `make dist_release`;
[`RELEASE.md`](RELEASE.md) has the steps, the reproducibility check, and the publication order.

## Install

Download `pkg_ecskc_customisations-v<version>.zip` from the
[GitHub releases](https://github.com/alexyarmoshko/joomla_pkg_ecskc_customisations/releases). In
the Joomla administrator, go to System, Install, Extensions, and upload it. It installs the
component and both plugins, and enables the plugins on the first install only. Installing writes
no managed file.

After the first install, later versions appear under System, Update, Extensions as one package
update. Uploading a newer ZIP by hand works too. An update leaves each plugin enabled or disabled
as the site had it. The children cannot be uninstalled on their own; uninstall the package.

Requires Joomla 5.4 or a later 5.x release, and PHP 8.3.

## Use

In the administrator, Components, ECSKC Customisations opens a Dashboard with the installed
version and a card that shows whether the system plugin is enabled. Its Customisations entry runs
a check of every managed path and shows one card per file with its state, the reason, what to do
next, and the expected and actual hashes, filtered by state or path.

The same screen runs a check or a dry run of the whole set or of one path, and each card links to
its own check and dry run. A dry run shows the operations, backups, and refusals an apply would
produce. A user with the Change Managed Files permission also gets Apply, Revert one step, Apply
one step, Freeze, and Unfreeze on each card, each with a hint and confirmed in the browser. The
whole set is applied from the whole-set dry run's Apply this plan button. Each write shows its
result once; reloading runs a fresh check.

Joomla deletes the component's `backup/` and `state/` folders on uninstall. When they hold any
history, uninstalling first copies them to a protected archive in the site's temporary folder.
Nothing restores that archive, and the message naming it comes only after the deletion. In some
cases, such as a write holding the run lock, none is written; the uninstall limits in
[`docs/RELEASE.md`](docs/RELEASE.md) list them.

## Status

Pre-release, and there is no published release.

## Documentation

- [`docs/RELEASE.md`](docs/RELEASE.md): release notes, one section per version.
- [`RELEASE.md`](RELEASE.md): how to build, test, package, install and publish a release.

## Licence

GNU General Public License v2, see [`LICENSE`](LICENSE).
