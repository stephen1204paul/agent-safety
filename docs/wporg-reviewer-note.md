# Reviewer note — Agent Safety 0.4.0

This file is not part of the plugin zip. It's for the wordpress.org plugin review team.

## Custom tables

The plugin creates three tables (prefix `{$wpdb->prefix}agsafe_`):

* `agsafe_audit_log` — one row per gate decision or executed agent action.
* `agsafe_approvals` — pending, approved, rejected, and terminal approval requests.
* `agsafe_grants` — pre-approval grants (renamed from `agent_safety_grants` in this release; the
  upgrade path renames the table in place, or falls back to create-copy-drop if the rename is
  refused, and leaves an admin notice if both old and new tables somehow exist).

Custom tables are used because the audit log has to be append-only and hash-chained, and options
or post meta don't give us a place to enforce that.

## Hash chain, and why rows can't be rewritten

Each audit row stores `prev_hash` and `entry_hash` (both `CHAR(64)`, sha256). `entry_hash` is
computed over the row's own content plus `prev_hash`, so it chains to the row before it. The
audit log viewer re-verifies the whole chain on every page load and shows a tamper-evidence
banner if any row's stored hash doesn't match a hash recomputed from its content, or if a row is
missing from the sequence.

This is why rows are never edited or deleted in place, including for privacy erasure requests:
changing `record_json` in any row (or removing it) would break `entry_hash` for that row and
every row after it, and the viewer would show the log as tampered. There's no code path in the
plugin that updates or deletes an audit row once it's written; the only way to remove rows is
dropping the table entirely (uninstall, opt-in only, see below).

## Audit-row retention through erasure requests

When a personal-data erasure request matches a WordPress user, the plugin's eraser reports the
user's audit rows as **retained**, not erased, with a message explaining they're kept as a
tamper-evident security record. This is deliberate, for the reason above: rewriting or deleting a
row to remove personal data would break the hash chain that proves the log hasn't been tampered
with.

The personal-data exporter matches rows by resolving the requester's email to a WordPress user
and matching the row's `wp_user` field only. It never searches the stored `input` JSON for
personal data, because that field can contain arbitrary arguments from other plugins' abilities
(order details, customer records) that the plugin has no schema for. The privacy policy text
added by the plugin discloses that tool inputs may contain such data and that it's retained as
part of the audit record.

Uninstall is opt-in: by default, deleting the plugin from the Plugins screen leaves all three
tables, every plugin option, and every scheduled cron event in place. A site owner who wants
everything removed defines `AGSAFE_REMOVE_DATA` as `true` before deleting the plugin, which drops
all three tables (`DROP TABLE IF EXISTS`, using `$wpdb->prepare('%i', ...)` for the table name),
deletes every option the plugin uses, and clears every cron event it scheduled.

## What the webhook sends, and when

The plugin has one opt-in outbound webhook, disabled until an administrator enters a URL under
the plugin's settings. When set, a new pending approval (a fresh request only — an agent retrying
a call that already has a pending row does not re-fire it) sends one HTTP POST to that URL with a
JSON body:

```json
{
  "event": "approval.requested",
  "approval_id": "apr_...",
  "verb": "woocommerce/products-delete",
  "review_url": "https://example.com/wp-admin/tools.php?page=agent-safety-pending"
}
```

No call arguments, no customer data, and no PII are sent. The URL and payload are both filterable
(`agent_safety_webhook_url`, `agent_safety_webhook_payload`) for a site that wants to route
somewhere else or add fields, but the shipped default is exactly the four fields above. The
plugin also sends one email, through `wp_mail()`, to the site's administrators (or a configured
recipient) for the same event; that's WordPress's own mail delivery, not a third-party service.
