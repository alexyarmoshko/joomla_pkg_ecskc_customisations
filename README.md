# ECSKC Customisation Package

A Joomla package that deploys the ECSKC site's customisations and re-applies them after
extension upgrades.

## Why

Extension updates overwrite customised files. Re-applying them by hand is slow and unreliable:
the August 2026 upgrade took a full forensic session to establish what had been lost, what had
survived, and what had quietly become redundant because the vendor had adopted the fix upstream.

This package turns that into a routine, checkable operation — install it, and apply or re-apply
from the Joomla administrator.

## Scope

**In scope** — how the site looks and what it can do: presentation and behaviour, in Joomla
extension and template directories.

**Out of scope** — what the site says, and server or SEO configuration. Site content,
`.htaccess` and `robots.txt` remain in the `ecskc.eu` repository.

## Status

Early. The design is still being worked out and nothing is implemented yet.

See the project brief for the customisation inventory and the
proposed strategy.

## Licence

GNU General Public License v2 — see [`LICENSE`](LICENSE).
