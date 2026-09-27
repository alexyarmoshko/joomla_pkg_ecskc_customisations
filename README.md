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
| `tools/` | Build helpers: deterministic packager, validator, hashing |
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

`make release` tags the version declared in the package manifest. It refuses to run on a dirty
tree, on an existing tag, or without release notes for that version. `make dist_release` then
builds the package ZIP from the tag into `installation/release/`, byte-reproducible from a clean
checkout. Both builds package the component and each plugin from its own file list, then nest
those three archives in the package, and refuse to run when a child manifest declares a version
other than the package's.

`make dist_release` also writes `installation/release/pkg_ecskc_customisations.update.xml`. It is a
copy of the tracked template `pkg_ecskc_customisations.update.xml` with the GitHub release URL and
the ZIP's SHA-256 filled in. The template keeps its 64-zero `<sha256>` placeholder, and
`make release` refuses to tag when it holds anything else. Only the package manifest registers the
update server; `make dist_dev` strips it, so a test install registers no update site.

To publish a release, in this order:

1. Upload the exact ZIP from `installation/release/` as an asset of the GitHub release for the
   version tag in `alexyarmoshko/joomla_pkg_ecskc_customisations`.
2. Copy the generated descriptor over `manifests/pkg_ecskc_customisations.update.xml` in
   `alexyarmoshko/joomla_update_system`, then commit, tag and push there.
3. Record the ZIP's SHA-256 and the `joomla_update_system` commit and tag with the release.

Joomla rejects a download whose hash differs from the descriptor's, so never rebuild or replace
an asset after its descriptor is published.

## Install

In the Joomla administrator, go to System, Install, Extensions, and upload the package ZIP. It
installs the component and both plugins, and enables the plugins on the first install only. An
update leaves each plugin enabled or disabled as the site had it. The children cannot be
uninstalled on their own; uninstall the package. A release package registers its update site, so
later versions appear under System, Update, Extensions as one package update.

## Status

Pre-release, and there is no published release. The core can check, dry-run, apply (in full or
one step at a time), revert, freeze and unfreeze managed customisations. In the administrator, Components, ECSKC
Customisations opens a Dashboard with the installed version and a card that shows whether the
system plugin is enabled. Its Customisations entry runs a
whole-set check and lists every managed path with its state, reason, and
hashes, filtered by state or path. The same screen runs a check or a dry run of the whole set or
of one path, and each file's card links to its own check and dry run. A dry run shows the operations, backups, and refusals
an apply would produce. A user with the Change Managed Files permission also gets Apply, Revert
one step, Apply one step, Freeze, and Unfreeze on each card, each with a hint and confirmed in the
browser. The whole set is applied from the whole-set dry run's Apply this plan button. Each write shows its result once; reloading
runs a fresh check. Installing the component writes no managed file. Joomla deletes `backup/`
and `state/` with the component on uninstall. So, when those folders hold any history,
uninstalling first copies them to a guarded archive under the site's temporary folder —
decision 26 of [`docs/execution_plan.md`](docs/execution_plan.md). If a write run holds the run
lock at that moment, or the archive cannot be written, no archive is made. The uninstall still
completes and reports why.

## Licence

GNU General Public License v2, see [`LICENSE`](LICENSE).
