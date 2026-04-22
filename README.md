# Disciple.Tools Migration

Move your Disciple.Tools settings and records from one site to another.

## What it does

This plugin lets you export data from one Disciple.Tools site and import it into another. You can transfer:

- General settings, custom lists, tiles, fields, roles, and workflows
- System users (matched by email -- no passwords are transferred)
- Contacts and groups, including comments and connections between records

## How it works

Install and activate the plugin on both sites. You can migrate data in two ways:

- **File export/import** -- Download your data as a file from the source site, then upload it on the destination site.
- **Direct connection** -- Connect the two sites via API so the destination pulls data directly from the source.

Go to **Extensions (D.T)** → **Migration** in the WordPress admin to configure what to include, run a preflight check for potential issues, and start the migration.

**Important:** Importing records will replace existing records of the same type on the destination site.

## Documentation

Step-by-step guides (file vs API), preflight, troubleshooting, and REST reference: see **[documentation/README.md](documentation/README.md)**.

## Requirements

- Disciple.Tools theme v1.20+
- WordPress 4.7+
- D.T admin role on both sites

## License

GPL-2.0+
