=== Social Share Performance Analyzer ===
Contributors: Cryptoball cryptoball7@gmail.com
Tags: social, analytics, shares, social-share
Requires at least: 5.0
Stable tag: 1.0.0

Track and analyze social share counts for your posts.

== Description ==
SSPA stores share counts in a local table and gives you:
* CSV import of share counts (supports large bulk imports).
* Admin dashboard with charts and a table.
* REST endpoints for aggregated data & CSV export.

== CSV Format ==
Required columns (exact header names):
post_id, platform, share_count, recorded_at
Optional column:
meta (JSON string)

Example:
post_id,platform,share_count,recorded_at,meta
123,facebook,456,2025-10-10 12:00:00,"{\"url\":\"https://...\"}"

== Changelog ==
= 1.0.0 =
* Initial release.

== Upgrade Notice ==
None.
