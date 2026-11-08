# Xboard Statistics

Xboard Statistics is a minute-level, multi-dimensional traffic statistics plugin for Xboard and the Sing-box core of Xboard-Node.

It ranks, trends, filters and cross-drills traffic by node, user, domain, source IP, destination IP, network, application protocol, inbound, outbound, destination port, country and ASN. User-Agent, URL paths, query strings, cookies, bodies and packet contents are never collected.

## Requirements

- Xboard with the plugin system (Laravel 12, PHP 8.2+).
- **MySQL 8.0.19 or newer.** SQLite, PostgreSQL and MariaDB are not supported; the migration stops with a clear error on any other database.
- The companion Xboard-Node fork reporting schema v2 one-minute batches.

## Installation

Place this repository in Xboard as:

```text
plugins/XboardStatistic
```

Then install and enable the plugin in the Xboard admin panel. Once enabled, the plugin exposes the node report endpoint:

```text
POST /api/v2/server/statistics/report
```

The endpoint reuses Xboard's `server.v2` node authentication. Credentials travel only in headers and are never written into node-side spool batches. The body may be gzip-compressed and must carry the SHA-256 of the decompressed JSON:

```text
X-Statistics-Checksum: <64 lowercase hex characters>
X-Xboard-Token: <server or machine token>
X-Xboard-Node-ID: <node id>
X-Xboard-Machine-ID: <machine id, machine mode only>
Content-Encoding: gzip
Content-Type: application/json
```

Authentication runs before the body is decompressed, so unauthenticated requests never reach the gzip and JSON decoders. Compressed bodies are limited to 8 MiB and decompressed bodies to 64 MiB.

The plugin does not change Xboard's billing reports.

## Admin page

The plugin adds a single "流量分析" entry to the Xboard sidebar, which opens the full analysis page:

```text
https://<panel-domain>/{secure_path}/xboard-statistic
```

`secure_path` matches the Xboard admin entry. The page checks the local admin bearer token and confirms admin access through a protected endpoint; when the session is missing or expired it redirects to the admin login and returns to the analysis page afterwards. Every data request goes through Xboard's `admin` and `log` middleware.

Two drill-down paths are available:

- Node view: nodes → users on a node → domains of a user on that node.
- User view: users → nodes used by a user → domains of a user on that node.

The current path is kept in the breadcrumbs and the URL. After a domain is selected, the page continues to destination IP, outbound, application protocol, destination port or destination country. IP, network, protocol, inbound, outbound, port, country and ASN conditions live under "更多筛选" and stack on the current path.

The overview, ranking, trend and health panels load independently: a slow panel never blanks the others, a new query cancels the requests of the previous one, and requests give up after 30 seconds with a hint to narrow the range. Health data is loaded only when its tab is open.

When "页面隐藏来源 IP" is enabled, source addresses are shown as IPv4 `/24` and IPv6 `/48` prefixes; country and ASN stay visible.

## Query API

```text
GET /api/v2/{secure_path}/plugin/xboard-statistic/overview
GET /api/v2/{secure_path}/plugin/xboard-statistic/health
GET /api/v2/{secure_path}/plugin/xboard-statistic/traffic
```

`traffic` accepts `from`, `to`, `group_by`, `node_id`, `user_id`, `domain`, `source_ip`, `destination_ip`, `network`, `application_protocol`, `inbound_type`, `outbound`, `destination_port`, `source_country`, `destination_country`, `source_asn`, `destination_asn`, `search`, `sort_field`, `sort_order`, `current` and `pageSize`. `domain=unknown` selects the complete traffic whose domain the node could not identify. `group_by=time` returns the trend in chronological order: hourly points up to 31 days, daily points beyond.

`health` returns, per node, the latest minute bucket, receive delay, node-side pending queue and retry count.

Each response states the `from`/`to` range it actually covered and, for `traffic`, whether it was answered from `minute` facts or `hourly` rollups.

## Storage model

| Table | Content | Retention |
| --- | --- | --- |
| `xbs_traffic_minute` | One row per node, minute and dimension combination; partitioned by UTC day | `minute_retention_days` (7–90) |
| `xbs_traffic_hour_node_user` | Hourly totals per node and user | `rollup_retention_days` (30–730) |
| `xbs_traffic_hour_node_user_domain` | Hourly totals per node, user and domain | `rollup_retention_days` |
| `xbs_report_batches` | Batch metadata, quality counters and node queue health | `batch_retention_days` (30–365) |
| `xbs_domains`, `xbs_ip_addresses`, `xbs_outbounds` | Dimension values, geo data and last-seen time | Until no retained data can reference them |

Rollups are updated in the same transaction that stores a batch, so they are exact and include late reports. Queries are routed automatically:

- Ranges longer than 24 hours that only involve node, user, domain and time read the hourly rollups. The range is aligned to whole UTC hours and the page shows the aligned range.
- Everything else reads the minute facts at the exact range. Queries that need minute facts may span at most 90 days.
- Every report query runs with a MySQL execution time limit and returns a clear error instead of hanging.
- Distinct source/destination IP counts in the overview are computed only for ranges of 24 hours or less.

## Time conventions

- Nodes report `bucket_start` and `bucket_end` as whole UTC minutes with a `Z` suffix; a batch covers exactly one minute.
- All stored times are Unix seconds, independent of the Xboard, PHP, database or host time zone.
- Admin API inputs must be ISO 8601 with `Z` or an explicit offset; outputs are UTC ISO 8601 with `Z`.
- The page uses the browser's local time zone for input and display and sends UTC to the API.
- Partitions, rollup hours and scheduled jobs all use UTC.

## Node configuration

Use the companion Xboard-Node fork and enable statistics on Sing-box nodes only:

```yaml
statistics:
  enabled: true
  spool_path: /etc/Xboard-Node/statistics
  max_hourly_dimensions: 200000
  max_domains_per_user_hour: 5000
  max_destination_ips_per_user_hour: 10000
  max_pending_batches: 10080
  request_timeout: 30
  disable_sniff: false
```

Nodes build immutable gzip batches per UTC minute (schema v2) and usually upload them at the next minute check. Failed batches are persisted atomically and retried with exponential backoff; batches permanently rejected with a 4xx status move to the `rejected` directory. This pipeline is independent of billing reports.

## Scheduled jobs and GeoIP

The Xboard scheduler runs these commands automatically; they can also be run by hand:

```bash
php artisan xboard-statistic:maintain --dry-run
php artisan xboard-statistic:update-geoip --force
php artisan xboard-statistic:enrich-ips
```

- `maintain` (daily, 03:20 UTC) prepares minute partitions seven days ahead, drops expired minute partitions, and deletes expired rollups, batches and unused dimension rows in small chunks. Partition changes use a short lock wait so they never queue report ingestion behind a long query.
- `update-geoip` (daily, 04:05 UTC) keeps the managed DB-IP databases current.
- `enrich-ips` (every 10 minutes) fills country, ASN and AS organization for new addresses, and re-checks addresses after a database update.

Country and ASN come only from offline MMDB files on the panel server; addresses are never sent to an online lookup service. The plugin bundles the MMDB reader and by default downloads and monthly refreshes DB-IP Country Lite and ASN Lite into Xboard's `.docker/.data/xboard-statistic/geoip`, which survives container rebuilds and plugin upgrades. Updates download to a temporary file, verify the MMDB type and replace the old file atomically; on failure the previous database stays in use.

The Country/ASN MMDB path settings are optional overrides: leave them empty for the managed databases, or point them at a MaxMind GeoLite2/GeoIP2 or other compatible MMDB. DB-IP Lite data is used under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) and the page keeps the `IP Geolocation by DB-IP` attribution link.

## Privacy

Visited domains and source IPs are sensitive. Only administrators can query them, and queries are recorded in the Xboard request log. Use the shortest retention that works for you and enable "页面隐藏来源 IP" when needed; it changes only what the page shows, not what is stored.

The protocol is defined in `resources/schema/report-v2.schema.json`. The schema forbids additional fields, so a node cannot silently start sending unapproved dimensions such as User-Agent.

## Development

Tests run against a disposable MySQL 8 database:

```bash
composer install
XBS_TEST_DB_HOST=127.0.0.1 XBS_TEST_DB_PASSWORD=secret vendor/bin/phpunit
```

`XBS_TEST_DB_HOST`, `XBS_TEST_DB_PORT`, `XBS_TEST_DB_DATABASE` (default `xbs_test`), `XBS_TEST_DB_USERNAME` (default `root`) and `XBS_TEST_DB_PASSWORD` select the database. The tests drop and recreate the plugin tables in that database, so never point them at a production schema.

The committed `vendor/` directory holds runtime dependencies only. After changing dependencies, rebuild it with `composer install --no-dev --optimize-autoloader`.

## Packaging and releases

Every push or merge to `main` runs the PHPUnit suite against MySQL 8.4 first. When it passes, GitHub Actions bumps the patch version in `config.json` (`x.y.z` → `x.y.(z+1)`), commits it as `github-actions[bot]`, tags `vX.Y.Z`, builds an installable ZIP rooted at `XboardStatistic/` with a SHA-256 checksum, and publishes both as an Actions artifact (kept for 30 days) and a GitHub Release. The workflow can also be started manually as `Package and release plugin`.

The version commit only touches `config.json`; the workflow ignores it and marks it `[skip ci]` to avoid release loops. The repository must allow GitHub Actions to write contents; if branch protection on `main` blocks bot pushes, give the workflow a bypass or release from a writable branch.
