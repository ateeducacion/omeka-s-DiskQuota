---
name: quota-enforcement
description: "Change upload quota checks, user/site quota settings, or disk-usage queries."
---

# Quota enforcement

Trace `Module.php` upload hooks through `src/Service/DiskQuotaManager.php` and the settings forms.

- Settings are expressed in MB; usage is bytes. Preserve the `1024 * 1024` conversion and the
  nonpositive/unlimited convention. An upload reaches the limit legally; exceeding it is rejected.
- User usage follows media ownership; site usage follows site item and item-set relationships.
  Check duplicate joins, unassigned resources, and user/site limits together before changing a query.
- Set the target ID before reading or writing user/site settings; shared services must not retain
  another user's or site's target accidentally.
- Keep enforcement server-side for the ingestion paths the module supports. A form warning alone
  cannot enforce a quota. Trace error handling so a failed usage query is not silently reported as zero.

Run the focused tests under `test/` for below/equal/above limits, unlimited settings, and overlapping
site membership, then the full PHPUnit suite. Use the commands in AGENTS.md; avoid a dependency update
merely to run validation. Verify a rejected upload leaves no orphan media or files when changing ingestion.
