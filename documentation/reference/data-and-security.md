# Data and security

## Passwords are never exported

User **passwords** are **not** included in migration exports. New users created on the destination receive **generated passwords** (and normal WordPress password-reset flows apply).

## System users

- Users are matched primarily by **email**, then **login**, depending on the implementation in the system user importer.
- **Roles** from the export are applied only when the destination WordPress installation defines those role slugs and the acting user has permission to assign them (e.g. administrator-related capabilities).
- Role assignment avoids leaving an account **without any role** during import: invalid or missing role data falls back to the site’s **default role**, with a safe fallback if that default is misconfigured.
- **Multisite:** roles are **per subsite**. A network super admin may not look like a normal Administrator under **Users** on a subsite; see preflight informational text and add users to the subsite if needed.

## Destructive record import

For each **selected record type**, the destination import process is designed to **delete existing records of that type** and recreate them from the source so **IDs and relationships** can align with the exported data.

**Plan backups** and test on staging before running against production.

## JWT and API access

API imports use a **bearer token** stored on the destination after a successful connection test. Protect administrator accounts on the source and restrict who can run imports on the destination. Prefer **HTTPS** everywhere.

## File exports

JSON export files contain **PII and ministry data**. Encrypt at rest and limit distribution to trusted operators.

### File import jobs on the destination

When you **Upload & Preview** on the destination, the plugin stores a copy of the decoded export in the **WordPress database** (per user, as a **job** with metadata) so chunked preflight and import can run without a short time limit. After a **successful** import, the large payload is typically **removed** while a small **success** record may remain in the job list until automatic or manual cleanup. Operators with access to the Migration screens can **delete** jobs from the list; retention in days is configurable under **Settings** → **File import jobs** (see [Settings and scope](../user-guide/settings-and-scope.md)). Treat database backups as including any not-yet-pruned job payloads.

## Capabilities

Migration admin UI and REST permission checks rely on **`manage_dt`**. Some user operations may require **`promote_users`** or other WordPress caps when creating or elevating accounts.

## See also

- [Overview](../user-guide/overview.md)
- [Troubleshooting](../user-guide/troubleshooting.md)
