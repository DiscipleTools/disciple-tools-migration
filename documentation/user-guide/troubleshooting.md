# Troubleshooting

## Migration is disabled

**Symptom:** Export or Import says migration is disabled.

**Fix:** On **Settings**, enable **Allow this site to perform Disciple.Tools migrations** and save.

## Permission errors

**Symptom:** Cannot access Migration screens or REST returns forbidden.

**Fix:** Use a Disciple.Tools account with **`manage_dt`**. Importing some user roles may require **`promote_users`** on the destination.

## API connection fails

**Symptom:** Test connection shows an error; no capabilities table.

**Checks:**

- **URL** is the site root (no trailing path to wp-admin required; use the public site URL pattern you use for REST).
- **HTTPS** and valid certificates.
- **JWT** plugin / `jwt-auth/v1` available on Server A and credentials correct.
- Firewall allows **Server B → Server A** requests.

Re-run **Test Connection** after fixing Server A. Clear old tokens by saving a fresh connection if the UI still fails.

## JWT expired or invalid

**Symptom:** Import batches fail after a delay.

**Fix:** Obtain a new token via **Test Connection** on the destination.

## Preflight shows ID collisions

**Symptom:** Warnings list post IDs that exist on the destination with a **different** type than the import expects.

**Fix:** Manually resolve conflicts (delete or rename the conflicting content), choose a clean destination database for those types, or adjust export scope. Do not ignore if you need strict ID preservation.

## Unknown field warnings

**Symptom:** Preflight lists fields on records that the destination does not recognize.

**Fix:** Import **fields** (and related tiles/settings) before records, or add matching field definitions on the destination first.

## User import / role messages

**Symptom:** Errors assigning roles, or unexpected role after import.

**Fix:** Ensure role slugs in the export **exist** on the destination. The import path validates roles and assigns a **safe default role** when an export row has no valid roles, so users are not left without a role mid-import. On multisite, add users to the subsite explicitly if they do not appear as expected.

## Large exports or timeouts

**Symptom:** Download or API batch stalls.

**Fix:** Increase PHP / web server timeouts where appropriate; for API imports, batches use pagination — retry from the UI. For very large file uploads, check `upload_max_filesize` / `post_max_size`.

## Theme version notice

**Symptom:** Admin notice that Disciple.Tools theme is below required version.

**Fix:** Upgrade the **Disciple.Tools theme** to the version required by the plugin (see main plugin file / readme).

## Import progress appears stuck

**Symptom:** Progress bar or counts stop updating.

**Fix:** Check browser console and server error logs; session or nonce expiry can interrupt long jobs — refresh and consult whether partial import requires cleanup (records may be half imported depending on stage).

<!-- Screenshot: Import progress / batch UI -->

![Import in progress (batches or progress indicator)](imgs/fig-09-import-progress.png)
