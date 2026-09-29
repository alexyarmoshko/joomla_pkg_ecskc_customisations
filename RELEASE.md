# Release process

How to build, test, package, install by hand, and publish a release of
`pkg_ecskc_customisations`. What changed in each version is in the release notes,
[`docs/RELEASE.md`](docs/RELEASE.md).

## Requirements

- PHP 8.3 with the `zip` extension, Composer, GNU make, git, and `sha256sum`.
- `tools/jzip.php`, the deterministic packager, is part of the repository. A release build
  refuses to run without it and never falls back to `zip`.

## Build and test

```sh
make deps       # composer install
make test       # the PHPUnit suites
make lint       # PHP syntax and XML well-formedness
make validate   # the rule set against the payload tree
make versions   # every manifest declares the package version
make dist_dev   # a test package from the working tree, under installation/dev/
```

`make dist_dev` builds from uncommitted files and strips `<updateservers>` from every manifest,
so a test install registers no update site. Never publish it.

Every build checks the payloads inside the package it has just written against the rules in
the same package, and deletes the package if any payload differs.

## Install by hand

In the Joomla administrator, open System, Install, Extensions, and upload the package ZIP. The
same upload installs over an earlier version as an update. Uninstall the package, not its
children; Joomla refuses to uninstall them on their own.

## Cut a release

The package manifest `extensions/pkg_ecskc_customisations/pkg_ecskc_customisations.xml` owns the
version. The tag is the bare version, `0.1.0`, and the ZIP is named with a dashed form,
`pkg_ecskc_customisations-v0-1-0.zip`.

1. Set `<version>` in the package manifest, the component manifest, both plugin manifests, and
   the update descriptor template `pkg_ecskc_customisations.update.xml`. Add the matching
   `## <version>` heading and its release date to `docs/RELEASE.md`. Commit. This commit becomes
   the tag, and because it changes `docs/RELEASE.md` the public copy keeps it with its date. A
   tag on a commit that changes only files the public copy leaves out lands there on an earlier
   commit, and a build from that public tag then differs from the release in its timestamps.
2. `make release`. It refuses a dirty tree, an existing tag, a version mismatch, a template with
   anything but the placeholder checksum, and missing release notes. It runs the tests and lint,
   then creates the tag.
3. `make dist_release`. It refuses unless the tree is clean and `HEAD` is the tag, and refuses any
   variable set on the command line except `INSTALL_DIR` and `BUILD_DIR`. It exports the tag,
   builds the package into `installation/release/`, and writes the update descriptor beside it.
4. Rebuild the same tag in a second clone at a different path, under a different timezone, umask
   and locale, and compare the two ZIPs:

   ```sh
   git clone --branch <version> <repository> /tmp/ecskc-rebuild
   cd /tmp/ecskc-rebuild
   make deps
   (umask 077; TZ=Pacific/Kiritimati LC_ALL=C make dist_release)
   sha256sum installation/release/pkg_ecskc_customisations-v<dashed-version>.zip
   ```

   The hash must equal the first build's. Both builds run on one host, so this proves that the
   build path, timezone, umask and locale do not reach the archive. It does not cover a different
   PHP or zlib build.

## Publish

In this order. A descriptor published before its ZIP announces a download that fails, and Joomla
rejects a download whose hash differs from the descriptor's, so never rebuild or replace a ZIP
after its descriptor is published.

1. Publish the tag to GitHub, and upload the exact ZIP from `installation/release/` as the asset
   of the GitHub release for that tag in `alexyarmoshko/joomla_pkg_ecskc_customisations`. The
   GitHub repository is a filtered copy of the development history, so the tag there names a
   different commit ID. With step 1 of Cut a release followed, that commit has the same date and
   the same shipped files, and a build from the public tag gives the same hash.
2. Copy `installation/release/pkg_ecskc_customisations.update.xml` over
   `manifests/pkg_ecskc_customisations.update.xml` in `alexyarmoshko/joomla_update_system`.
   Commit and push there. That repository does not tag its descriptors.
3. Record the release below.

## Recorded releases

The exact commands run for each release, both rebuild hashes, the ZIP's SHA-256, and the
`joomla_update_system` commit.

### 0.2.0, 2026-09-30

Commands, in the development repository and then a second clone of its tag:

```sh
make release
make dist_release
git clone --branch 0.2.0 <development repository> <second path>
cd <second path>
make deps
(umask 077; TZ=Pacific/Kiritimati LC_ALL=C make dist_release)
sha256sum installation/release/pkg_ecskc_customisations-v0-2-0.zip
```

- Both builds: `7d850fa33ef24af45bf1302340e2f74e3ad15a7faea61b137f8fff1b70693cf8`, and the two
  update descriptors are identical.
- GitHub release `0.2.0`, asset `pkg_ecskc_customisations-v0-2-0.zip`, 1082862 bytes, SHA-256
  `7d850fa33ef24af45bf1302340e2f74e3ad15a7faea61b137f8fff1b70693cf8`. The downloaded asset has
  that hash.
- `joomla_update_system` commit `8720a4e`, "Update pkg_ecskc_customisations.update.xml manifest
  for release 0.2.0". Its `<sha256>` is the asset's, and the raw descriptor URL serves the file
  byte for byte.
- The development tag is on `289f852`, and the public tag `0.2.0` is on `b9e2f0b`, its copy in
  the public repository with the same commit date. A build from the public tag gives the same
  hash, so step 1 of Cut a release worked.

### 0.1.0, 2026-09-28

Commands, in the development repository and then a second clone of its tag:

```sh
make release
make dist_release
git clone --branch 0.1.0 <development repository> <second path>
cd <second path>
make deps
(umask 077; TZ=Pacific/Kiritimati LC_ALL=C make dist_release)
sha256sum installation/release/pkg_ecskc_customisations-v0-1-0.zip
```

- Both builds: `e2bc10076e0eb5dde3ab48be489af1cc3bbb3a882206ecffc5985802a9e8470c`, and the two
  update descriptors are identical.
- GitHub release `0.1.0`, published 2026-09-28 17:11 UTC, asset
  `pkg_ecskc_customisations-v0-1-0.zip`, 964088 bytes, SHA-256
  `e2bc10076e0eb5dde3ab48be489af1cc3bbb3a882206ecffc5985802a9e8470c`. The downloaded asset has
  that hash.
- `joomla_update_system` commit `eec5bf5`, "Update pkg_ecskc_customisations.update.xml manifest
  for release 0.1.0". Its `<sha256>` is the asset's, and the raw descriptor URL serves the file
  byte for byte.
- The public tag `0.1.0` is on commit `65dc153`. The development tag's commit changed only files
  the public copy leaves out, so the tag landed on the commit before it, dated 16:43:04 UTC
  instead of 16:55:26 UTC. A build from the public tag gives
  `8bbd664328866306fbb09ee46cfde79089f6529c059917616f980240a2880e79`: every file in the package
  and in its three child archives is the same, and only the archive timestamps differ. The
  published asset is the build of the development tag. Step 1 of Cut a release now prevents
  this.
