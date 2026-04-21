# REST API reference

Migration exposes routes under the namespace:

```text
/wp-json/dt-migration/v1/
```

## Authentication

In-browser and server-side calls from an authenticated Disciple.Tools administrator use the normal WordPress REST authentication (cookies / application passwords as configured).

**Site-to-site imports** on the destination use **`Authorization: Bearer <JWT>`** after the destination obtains a token from Server A’s `jwt-auth/v1` flow. The migration routes check for the **`manage_dt`** capability for the current user context when the request is handled as that user.

## Routes

### `GET /dt-migration/v1/capabilities`

Returns a summary of whether migration is **enabled** on this site, **`allowed_items`** (settings flags and per–post-type record toggles), **`site_meta`** (URL, WordPress version, PHP, Disciple.Tools theme version, multisite flag, etc.), and **`plugin_capabilities`** (whether API and file modes are supported and which record types are enabled).

Use this from the destination to verify Server A before importing.

### `POST /dt-migration/v1/export`

**Non-destructive.** Builds a **settings-oriented** export payload: `dt_settings` (tiles, fields, post type structure, workflows, etc., according to **allowed_items**) and **`system_users`** when that category is allowed. The response **`note`** states that this payload does **not** include record rows.

Callers may send a JSON body; the response includes it under **`request`** for traceability. **Record data** is fetched with **`GET .../records/{post_type}`** in batches.

### `GET /dt-migration/v1/records-preview`

**Non-destructive.** Returns **counts** of posts per **enabled** record post type from migration settings.

### `GET /dt-migration/v1/records/{post_type}`

**Readable.** Returns a **batch** of full record payloads for `post_type`.

Query parameters:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `offset` | `0` | Starting index for pagination |
| `limit` | `50` | Page size, clamped between **1** and **100** |

The response includes `records`, `total`, `offset`, `limit`, and `has_more`.

If `post_type` is **not allowed** in migration settings, the API returns **403** with an explanatory message.

Record objects may include **`dt_migration_comments`** and other fields attached by the import/export pipeline.

## Error responses

Typical cases:

- **403** — Post type not allowed for migration, or insufficient capabilities
- **500** — Missing `DT_Posts` or other server-side failure

Always check the JSON **`message`** body when present.

## See also

- [Migration via API](../user-guide/migration-via-api.md)
- [Data and security](data-and-security.md)
