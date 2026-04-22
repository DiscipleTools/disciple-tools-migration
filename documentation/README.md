# Disciple.Tools Migration — Documentation

This folder contains user and reference documentation for moving Disciple.Tools configuration and records between sites using the **Disciple.Tools – Migrations** plugin.


## Guides

| Document | Description |
|----------|-------------|
| [User guide: Overview](user-guide/overview.md) | What the plugin does, requirements, and where to find it in WordPress |
| [User guide: Settings and scope](user-guide/settings-and-scope.md) | Enabling migration and choosing what to include |
| [User guide: Migration via file](user-guide/migration-via-file.md) | Export download → transfer → import from JSON |
| [User guide: Migration via API](user-guide/migration-via-api.md) | Connect to a live source site and pull data over the REST API |
| [User guide: Preflight and warnings](user-guide/preflight-and-warnings.md) | Optional checks before import |
| [User guide: Troubleshooting](user-guide/troubleshooting.md) | Common problems and constraints |

## Reference

| Document | Description |
|----------|-------------|
| [REST API](reference/rest-api.md) | Migration REST namespace, routes, and behavior |
| [Data and security](reference/data-and-security.md) | Passwords, users, roles, and destructive import semantics |

## Typical workflows

### A. File-based (offline-friendly)

1. Install and activate the plugin on **source** and **destination** Disciple.Tools sites (see [Overview](user-guide/overview.md)).
2. On the **source** site, open **Extensions (D.T)** → **Migration** → **Settings**: enable migration and select what to export (including record types).
3. On the **source** site, open **Export** and download the JSON package.
4. Move the file to a secure location and upload it on the **destination** site under **Import**.
5. Optionally run **preflight**, review warnings, then start the import.

### B. API-based (direct site-to-site)

1. Ensure both sites have the plugin enabled and migration turned on; **source** must allow the same categories you plan to pull.
2. On the **destination** site, open **Import** → **API Connection to Source Site**: enter the source base URL and obtain a session (see [Migration via API](user-guide/migration-via-api.md)).
3. Fetch capabilities and previews from the source, optionally run **preflight**, then start the import so the destination pulls settings and records in batches.

Both channels respect the same scope rules and the same import semantics on the destination (see [Data and security](reference/data-and-security.md)).

## Screenshots

Illustrations live next to the guides that reference them, under each section’s `imgs/` directory. Filenames are placeholders until real screenshots are added.
