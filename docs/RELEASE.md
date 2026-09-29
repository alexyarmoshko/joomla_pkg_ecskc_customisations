# Release notes

Release notes for the ECSKC customisation package, newest first, one `## <version>` heading per
version. `make release` refuses to tag a version without its heading here.

This file says what changed in each version. How to build, test, package and publish a release
is in [`RELEASE.md`](../RELEASE.md) at the repository root.

## 0.2.0

**Release date:** 2026-09-29.

Adds a Plugins screen to the component. The managed files, their rules and their payloads are
the same as in 0.1.0.

### Plugins screen

Components, ECSKC Customisations now has a third entry, Plugins, after Customisations. It lists
the 18 ECSKC extensions named in the package's registry, `registry.xml`: this package and its
three extensions, `pkg_cbuseronlinestatus` and its two, and three components, three modules,
three system plugins and two Community Builder plugins, each with a repository under
`github.com/alexyarmoshko`.

- Each row shows the name, type, version, status, recorded update and update server. A Details
  section below it, closed until clicked, shows the package, the description and the
  repository. The status is `Enabled`, `Disabled`, `Not installed` or `Manifest missing`.
- An installed extension's name links to its own screen: a plugin's edit form, a module or
  template list filtered to it, Extensions: Manage filtered to it, or Community Builder's plugin
  editor.
- The list sorts on name, type, version, status and update, searches by name, and
  pages with the site's list limit. The sort and the limit are kept for the session.
- An extension inside a package reports the package's update and update server, because the
  package carries the update server.
- The screen needs the component's Access Administration Interface permission. It reads the
  database and changes no extension, database row or file.
- If the registry cannot be read, or a database read fails, the screen shows the error and no
  list, so it never reports an extension as missing that it did not look for.

The Customisations and Plugins screens now carry their names in the page title, as the
Dashboard does: "ECSKC Customisations: Customisations" and "ECSKC Customisations: Plugins".

### Known limits

- An update shows only after Joomla's Find Updates has recorded it. `No update found` does not
  prove an extension is current.
- An installed Community Builder plugin shows `Not checked` for update and update server.
  Community Builder keeps its own update data, which this version does not read. A row that is
  `Not installed` shows `—` in both columns.
- The registry ships in the package. Adding an extension to the list needs a new release.

### Requirements

- Joomla 5.4 or a later 5.x release.
- PHP 8.3 or later.

Tested on a copy of the club site running Joomla 5.4.8 and PHP 8.3: an update over 0.1.0, every
row of the Plugins screen, each name link, sorting, searching and paging, and probes for a
disabled plugin, a recorded update, an empty manifest and a malformed registry.

### Install and update

- Update from 0.1.0 under System, Update, Extensions, or upload the ZIP. The update adds the
  Plugins entry and writes no managed file.
- An update leaves each plugin enabled or disabled as the site had it.

## 0.1.0

**Release date:** 2026-09-28.

The first release. One Joomla package, `pkg_ecskc_customisations`, installs three extensions:

- **ECSKC Customisations** (`com_ecskccustom`), an administrator component. It checks each
  managed file against the hashes its rules declare, shows a dry run of any change, applies,
  reverts or re-applies one version at a time, and freezes a file so the package leaves it alone.
  Apply backs up what it overwrites. Revert and re-apply keep no backup. Every file change is
  recorded in an event log.
- **System - ECSKC Customisations** adds the site's `override.css` to site pages.
- **Installer - ECSKC Customisations** checks the managed files after any extension is installed
  or updated, changes nothing, and shows one message when a file needs attention.

### Managed files

Twelve files: seven vendor files with site customisations (two Helix Ultimate contact form
overrides, three NTS KBee Kunena template files, the et_tennis `options.xml`, and a CB Gallery
controller), and five files the package owns (two Apple touch icons, the CBSubs language override
and its stub, and `templates/et_tennis/css/override.css`).

### Download

`pkg_ecskc_customisations-v0-1-0.zip`, SHA-256
`e2bc10076e0eb5dde3ab48be489af1cc3bbb3a882206ecffc5985802a9e8470c`. Two builds of tag `0.1.0`
from separate clones give this same hash.

### Requirements

- Joomla 5.4 or a later 5.x release.
- PHP 8.3 or later.

Tested on a copy of the club site running Joomla 5.4.7 and PHP 8.3: a first install, an update
over an earlier build, an uninstall, and every action in the component.

### Install and update

- Installing writes no managed file. The installation message links to the check, where each
  change can be reviewed and applied.
- Both plugins are enabled on the first install only. An update leaves each one enabled or
  disabled as the site had it.
- The component and the plugins cannot be uninstalled on their own. Uninstall the package.
- The package registers its update site, so later versions appear under System, Update,
  Extensions as one package update.

### Known limits

- Revert one step needs the file's earlier content, from the package or from a backup that
  Apply wrote. Where neither holds it, the revert is refused and the file is left as it is. For
  example, the two Kunena layouts' versions ship the same content, so they cannot be reverted
  from one to the other.

### Uninstall limits

Joomla deletes the component's `backup/` and `state/` folders with it, so uninstalling first
copies them to a protected archive in the site's temporary folder and names it in a message.

- Nothing reads that archive back. Restoring backups or the event log after a reinstall is a
  manual job.
- The message comes after the deletion. Save or delete the archive then.
- No archive is written when a write run holds the run lock, when a check is reading the state,
  when the state cannot be read, or when the archive cannot be written or protected. The
  uninstall still completes, and the message says why there is no archive.
