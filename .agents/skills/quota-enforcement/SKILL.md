---
name: quota-enforcement
description: "Change upload quota checks, user/site quota settings, or disk-usage queries."
---

# Quota enforcement

Trace the event registrations in `Module.php` through `src/Listener/*QuotaListener.php`,
`src/Service/UploadSizeResolver.php`, `src/Service/DiskQuotaManager.php`, and the settings forms.
The listener services own upload checks and user/site display and settings handlers.

- Settings are expressed in MB; usage is bytes. Preserve the `1024 * 1024` conversion and the
  nonpositive/unlimited convention. An upload reaches the limit legally; exceeding it is rejected.
- User usage follows media ownership; site usage follows only explicit `item_site` membership.
  Attached item sets do not assign their items to a site. Check each assigned site on upload,
  duplicate joins, unassigned resources, and user/site limits together before changing a query.
- Set the target ID before reading or writing user/site settings; shared services must not retain
  another user's or site's target accidentally.
- Keep enforcement server-side for the ingestion paths the module supports. A form warning alone
  cannot enforce a quota. Trace error handling so a failed usage query is not silently reported as zero.

Run the focused tests under `test/` for below/equal/above limits, unlimited settings, and overlapping
site membership, then the full PHPUnit suite. Use the commands in AGENTS.md; avoid a dependency update
merely to run validation. Verify a rejected upload leaves no orphan media or files when changing ingestion.
