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
| `data/registry.xml` | The ECSKC extensions the Plugins screen lists, each with its repository |
| `data/payload/` | The customised files a rule installs |
| `tests/` | PHPUnit suites |
| `tools/` | Build helpers: deterministic packager, validator, package check, hashing |
| `Makefile` | Build and release entry points |
| `pkg_ecskc_customisations.update.xml` | Update descriptor template; the release build fills in its URL and checksum |

A payload's bytes are its identity, line endings and final newline included, and `data/payload/`
is pinned `-text` in `.gitattributes`. Never reformat a payload file. A normalised copy still
passes every structural check and then matches nothing on disk.

## Build

Requires PHP 8.3 with the `zip` extension, Composer, GNU make, git, and `sha256sum` or
`shasum`.

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
against the package's own rules, and deletes the package if any payload differs. Never publish
a `dist_dev` build.

## Release

The package manifest `extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml` owns the
version. The tag is the bare version, `0.2.0`, and the ZIP is
`pkg_ecskc_customisations-v0-2-0.zip`. A release build packs with `tools/jzip.php`, the
deterministic packager in this repository, and never falls back to `zip`.

1. Set `<version>` in the package, component and both plugin manifests and in
   `pkg_ecskc_customisations.update.xml`, and add a `## <version>` heading with the release date
   to [`docs/RELEASE.md`](docs/RELEASE.md). Commit. That commit becomes the tag. It has to change
   `docs/RELEASE.md`, which the public GitHub copy keeps. A tag on a commit that changes only
   files the public copy leaves out lands there on an earlier commit, and a build from that
   public tag then has different timestamps.
2. `make release` refuses a dirty tree, an existing tag, a version mismatch, a descriptor
   template with anything but its one placeholder checksum, and missing release notes. It runs
   the tests and lint, then tags.
3. `make dist_release` builds the tag into `installation/release/` and writes the update
   descriptor beside the ZIP. It refuses unless the tree is clean and `HEAD` is the tag. Both
   release targets refuse any variable set on the command line except `INSTALL_DIR` and
   `BUILD_DIR`.
4. Rebuild the tag in a second clone, under another timezone, umask and locale:

   ```sh
   git clone --branch <version> <repository> <second path>
   cd <second path> && make deps
   (umask 077; TZ=Pacific/Kiritimati LC_ALL=C make dist_release)
   sha256sum installation/release/pkg_ecskc_customisations-v<dashed-version>.zip
   ```

   Without `sha256sum`, use `shasum -a 256` on the same file. The hash must equal the first
   build's; do not publish otherwise. Both builds run on one host, so this shows that the path,
   timezone, umask and locale do not reach the archive. It does not cover a different PHP or
   zlib build.
5. Publish the ZIP before its descriptor, because a descriptor published first announces a
   download that fails. Publish the tag to the GitHub copy, which is a filtered copy of this
   history: the tag there names a different commit, with the same date and the same shipped
   files, so a build from it gives the same hash. Upload the exact ZIP from
   `installation/release/` to the GitHub release for that tag. Then copy the descriptor over
   `manifests/pkg_ecskc_customisations.update.xml` in
   [`joomla_update_system`](https://github.com/alexyarmoshko/joomla_update_system), commit and
   push. That repository does not tag its descriptors. Joomla rejects a download whose hash
   differs from the descriptor's, so never rebuild or replace a ZIP once its descriptor is
   published.

## Install

Download `pkg_ecskc_customisations-v<dashed-version>.zip` from the
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

The Plugins entry lists the ECSKC extensions named in `data/registry.xml`, installed or not. Each
row shows the extension's name, type, version, status, recorded update and update server, with
a closed Details section below it for the package, description and repository. An installed
extension's name links to its own Joomla or Community Builder screen. The list can be sorted,
searched by name and paged. The screen reads the database and changes nothing. An update shows
only after Joomla's Find Updates has recorded it, so `No update found` does not prove an
extension is current. An installed Community Builder
plugin shows `Not checked` in both update columns, and a `Not installed` row shows `—`.

## Status

Version 0.2.1, released 2026-10-27. The package ZIP is attached to the
[0.2.1 GitHub release](https://github.com/alexyarmoshko/joomla_pkg_ecskc_customisations/releases/tag/0.2.1),
and a site that has it installed is offered later versions under System, Update, Extensions.

## Documentation

- [`docs/RELEASE.md`](docs/RELEASE.md): release notes, one section per version.

## Licence

GNU General Public License v2, see [`LICENSE`](LICENSE).
