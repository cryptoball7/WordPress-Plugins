# Multi-Site Security Sync (MSSS)

This package contains:

- `wp-multisite-security-sync.php` — the WordPress plugin to install on each site you want monitored.
- `central-collector-example.php` — a minimal central collector demonstration script. For production, run a small app (Laravel/Express/WordPress plugin) that accepts events, verifies HMAC, and stores/visualizes data in a database.

## Install (site agent)

1. Copy `wp-multisite-security-sync.php` into a folder named `wp-multisite-security-sync` and zip it or place the file directly in `wp-content/plugins/wp-multisite-security-sync/`.
2. Activate the plugin in each WordPress site's Plugins admin screen.
3. Go to Settings → Multi-Site Security Sync and configure:
   - Collector URL (e.g. https://central.example.com/collector.php)
   - API Key (shared secret). **Use a strong random string and keep it secret.**
   - Site label (friendly name)
   - Which events to send
4. For better reliability and security, host the collector behind HTTPS and restrict access by IP if possible.

## Install (central collector - demo)

1. Copy `central-collector-example.php` to a secure HTTPS-enabled server.
2. Set an environment variable `MSSS_API_KEY` matching each site agent's API Key OR edit the file and set `$API_KEY` directly.
3. Ensure `msss-central.log` is writable by the web server.

## Security notes & recommendations

- Use HTTPS for all communications.
- Use a strong random shared secret (32+ bytes). Rotate it periodically and update agents.
- For production, create a small web application that stores events into a database, provides a dashboard, and supports per-site API keys. The demo script is intentionally tiny and not hardened for scale.
- Consider rate-limiting and alerting on the collector side to avoid noisy sites filling logs.

## Extending

- Add encryption to payloads (optional) or migrate to JWTs.
- Build a central WordPress plugin that provides a dashboard of sites and alerts.
- Add more event hooks: file integrity checks, firewall alerts, WP core auto-update results, etc.

