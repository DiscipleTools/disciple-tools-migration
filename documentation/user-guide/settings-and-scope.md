# Settings and scope

The **Settings** tab controls whether migration is allowed on this site and **which categories of data** may be included in exports (and therefore available to API consumers or file downloads).

## Enable migration

Turn on **Allow this site to perform Disciple.Tools migrations**. If migration is disabled, export and import actions are not available.

<!-- Screenshot: Settings tab — Enable migration and allowed items -->

![Migration settings: enable and configuration checkboxes](imgs/fig-02-settings-enable-and-scope.png)

## Settings and admin data

These options apply to **configuration**, not individual contact/group rows (except where noted):

| Option | Meaning |
|--------|---------|
| General settings | Core Disciple.Tools options eligible for migration |
| Custom lists | Custom list definitions |
| Tiles | Tile layouts per post type |
| Fields | Field definitions per post type |
| Roles | Role-related configuration included in the export |
| Workflows | Workflow definitions |
| WordPress users (system) | Safe user profile data; **no passwords**. See [Data and security](../reference/data-and-security.md) |

## Record types

Under **Record types**, enable each **Disciple.Tools post type** you want in exports and imports. The list reflects **all migratable types** registered on your site (labels shown in the UI; the internal slug appears in the description).

For each selected type, importing on a destination **removes existing records of that type** and recreates them using data from the source, **preserving IDs** where the import process allows, so links between records remain valid.

<!-- Screenshot: Record type checkboxes with plural labels and slugs -->

![Record types: checkboxes per Disciple.Tools post type](imgs/fig-03-settings-record-types.png)

Save changes with **Save Settings**.

## File import jobs (retention)

On the same **Settings** tab, the **File import jobs** section controls how long **completed file migration jobs** remain in the database before the site removes them automatically. Each time you use **Upload & Preview** on the **Import** tab, the destination stores the JSON as a **job** (see [Migration via file](migration-via-file.md)) so long imports are not cut off by a short session window.

- **Remove file migration jobs after (days):** enter a value between **1** and **365** (default is **7**). Jobs older than this are pruned on a schedule and when you save these settings. Lower the number if you need to free space sooner; raise it if you want the **Recent file migration jobs** list on the Import tab to show history for longer.

Saving **Settings** also runs a one-time cleanup pass against jobs past this age.

<!-- PLACEHOLDER: Replace with a screenshot of the Settings tab showing the "File import jobs" row and the days field. -->

![Settings: file import job retention (days)](imgs/fig-10-settings-file-jobs.png)

## API connection storage (destination)

The plugin stores the **source site base URL** and a **JWT** obtained after a successful connection test (see [Migration via API](migration-via-api.md)). **Username and password** used to fetch the token are **not** stored. JWT and URL are kept in the migration settings option for subsequent API import batches.

<!-- Screenshot: Optional — API section if shown on Settings in your build -->

![API connection fields on Settings or Import as applicable](imgs/fig-04-settings-api-connection.png)

## See also

- [Export tab](migration-via-file.md) — how the download reflects these choices
- [Migration via file](migration-via-file.md) — **Recent file migration jobs**, **Retry**, and how retention relates to the day limit above
- [REST API capabilities](../reference/rest-api.md) — how the source reports `allowed_items`
