# Release notes

Release notes for the ECSKC customisation package, newest first, one `## <version>` heading per
version. `make release` refuses to tag a version without its heading here.

This file says what changed in each version. How to build, test, package and publish a release
is in [`RELEASE.md`](../RELEASE.md) at the repository root.

## 0.1.0

**Release date:** not yet released.

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

### Requirements

- Joomla 5.4 or a later 5.x release.
- PHP 8.3 or later.

### Install and update

- Installing writes no managed file. The installation message links to the check, where each
  change can be reviewed and applied.
- Both plugins are enabled on the first install only. An update leaves each one enabled or
  disabled as the site had it.
- The component and the plugins cannot be uninstalled on their own. Uninstall the package.
- The package registers its update site, so later versions appear under System, Update,
  Extensions as one package update.

### Uninstall limits

Joomla deletes the component's `backup/` and `state/` folders with it, so uninstalling first
copies them to a protected archive in the site's temporary folder and names it in a message.

- Nothing reads that archive back. Restoring backups or the event log after a reinstall is a
  manual job.
- The message comes after the deletion. Save or delete the archive then.
- No archive is written when a write run holds the run lock, when a check is reading the state,
  when the state cannot be read, or when the archive cannot be written or protected. The
  uninstall still completes, and the message says why there is no archive.
