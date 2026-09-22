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
| `extensions/com_ecskccustom/` | Component source, administrator only |
| `data/rules/` | The rule set, one XML file per extension |
| `data/payload/` | The customised files a rule installs |
| `tests/` | PHPUnit suites |
| `tools/` | Build helpers: deterministic packager, validator, hashing |
| `Makefile` | Build and release entry points |

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
make dist_dev   # build an installable dev ZIP under installation/dev/
make info       # version, package contents, output paths
```

`make release` tags the version declared in the component manifest. It refuses to run on a dirty
tree, on an existing tag, or without release notes for that version. `make dist_release` then
builds the ZIP from the tag into `installation/release/`, byte-reproducible from a clean
checkout.

## Install

In the Joomla administrator, go to System, Install, Extensions, and upload the ZIP. The component
installs into the administrator and adds nothing to the site front end.

## Status

Pre-release, and there is no published release. The core can check, dry-run, apply, revert, redo,
freeze and unfreeze managed customisations, but nothing in the administrator calls it yet; Phase 3
adds that screen. Installing the component writes no managed file. Joomla deletes `backup/`
and `state/` with the component on uninstall. So, when those folders hold any history,
uninstalling first copies them to a guarded archive under the site's temporary folder —
decision 26 of [`docs/execution_plan.md`](docs/execution_plan.md). If a write run holds the run
lock at that moment, or the archive cannot be written, no archive is made. The uninstall still
completes and reports why.

## Licence

GNU General Public License v2, see [`LICENSE`](LICENSE).
