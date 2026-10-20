# site-type-php Behat coverage

Scope: `ee site create --type=php` and everything php-specific (`src/PHP.php`, `src/Site_PHP_Docker.php`, `templates/`), plus the site-command subcommands as they behave on a php site (the php class supplies `dump_docker_compose_yml()`, so update/enable/refresh output is php-specific). Generic site-command internals (LE renewal, backup/restore, clone, share) belong to the site-command suite.

## Inventory

### `ee site create <site-name> --type=php` (`PHP::create`)

| Item | Covered by |
|---|---|
| `<site-name>` required (usage on missing) | site.feature "php site create without a site name prints the php usage" |
| name normalisation: `http://` stripped, trailing `/` stripped, lowercased (`https://` shares the code path, not tested) | create.feature "The site name is normalised" |
| name without a dot gets `.test` | create.feature "A site name without a dot gets the .test TLD" |
| duplicate site name refused, first site untouched | create.feature "Creating the same site twice fails" |
| site name validation (SEC-1) | create.feature "A site name with shell syntax is rejected" `@known-bug @security` |
| unknown flag refused | create.feature "An unknown flag is rejected" |
| unknown `--type` refused | site.feature "An unknown site type is rejected" |
| `--cache` (global redis) | cache.feature scenarios 1-4 (DB flags, main.conf, networks, HIT from redis, BYPASS on query string) |
| `--with-local-redis` (with `--cache`) | cache.feature "--cache --with-local-redis", "Pages of a local-redis site", "Local redis and global db", "Local db and local redis" |
| `--with-local-redis` without `--cache` (ignored) | cache.feature "--with-local-redis without --cache enables no cache" |
| local redis restart policy (MIG-16) | cache.feature `@known-bug` |
| `--admin-email` (and default `admin@<site>`) | create.feature "--admin-email is stored and shown", "The site row holds the php defaults" |
| `--with-db` global db (DB/user created, creds stored, php connects) | database.feature scenarios 1-2 |
| `--dbname` / `--dbuser` / `--dbpass` on global db | database.feature "--dbname, --dbuser and --dbpass are used for the global db" |
| `--dbhost=global-db` | database.feature "--dbhost=global-db is the same as the default global db" |
| `--local-db` (db container, env, volumes, root password, php connects) | database.feature "--local-db runs a MariaDB container", "A local-db php site has its db service" |
| `--local-db` without `--with-db` (ignored) | database.feature "--local-db without --with-db creates no database at all" |
| remote `--dbhost=host:port` with credentials | database.feature "A remote --dbhost with host:port and existing credentials" (uses `global-db:3306` as the "remote") |
| remote `--dbhost` needs `--dbuser` and `--dbpass` | database.feature "A remote --dbhost needs --dbuser and --dbpass" |
| `--skip-check` | database.feature "--skip-check creates the site without probing the remote db" (only proves the create and port parsing: nothing probes today, PHPT-5) |
| remote DB verification / DB auto-create / non-empty DB check | database.feature "An unreachable remote db host fails the create" `@known-bug` (PHPT-5); DB auto-create and the non-empty-DB check: not covered, need a MariaDB reachable from the default bridge network (the verify container), which the test host doesn't provide |
| `--force` (reset non-empty remote DB) | not covered: dead code today (PHPT-5), needs a reachable remote MariaDB |
| DB name / user collisions on rollback | database.feature 3 `@known-bug` scenarios (PHPT-1, PHPT-3, PHPT-2), each on its own sites |
| DB-1 (mysql CLI in php images vs MariaDB TLS) | database.feature 2 `@known-bug` scenarios |
| `--php=<v>` every supported version + `latest` | php-version.feature outline (12 examples, `@slow`) |
| `--php` default 8.4 | create.feature "The site row holds the php defaults", "The compose file ..." |
| `--php` invalid values | create.feature outline "An unsupported --php value is rejected" (4 examples) |
| `--php` loosely matching values (`8`, `7`, `8.40`, `8.4.1`) | create.feature outline `@known-bug` (PHPT-11; `8.4.1` only passes validation on PHP 7) |
| `--php=<v>` without `--type` (EE3 compat mapping) | create.feature `@known-bug` (PHPT-6) |
| EE3 `--mysql` compat flag | create.feature "The legacy --mysql flag creates a php site with a database" |
| php 5.6 ini (sendmail) vs msmtp | php-version.feature outline `sendmail` column |
| `--alias-domains` plain + wildcard (DB, compose VIRTUAL_HOST/CERT_NAME, main.conf server_name, HTTP) | alias-domains.feature scenarios 1-3 |
| alias owned by another site / site name that is an alias | alias-domains.feature scenarios 4-5 |
| alias trimming | alias-domains.feature "Spaces around alias domains are trimmed" |
| alias that is an existing site | alias-domains.feature `@known-bug` (PHPT-7) |
| blank alias entries | alias-domains.feature `@destructive` regression (fixed by site-type-php #114, merged 2026-09-25; a regression breaks the nginx-proxy config until the rollback) |
| invalid alias names (AUTH-1) | alias-domains.feature outline, regression (fixed by #114; 3 examples, the one with a space is also `@destructive`) |
| `--public-dir` (relative, full path, nested, traversal sanitised, with cache, outside files not served) | public-dir.feature |
| bare `--public-dir` | public-dir.feature `@known-bug` (PHPT-12) |
| `--skip-status-check` | create.feature "--admin-email is stored and shown, --skip-status-check skips the HTTP probe" |
| `--ssl=self` (+ wildcard flag stored, HTTPS, HTTP->HTTPS redirect, cert SANs) | ssl.feature scenarios 1-2 |
| `--ssl=custom --ssl-key --ssl-crt` | ssl.feature "Create a php site with a custom certificate" |
| custom SSL argument errors (missing key/crt, missing file) | ssl.feature 2 scenarios |
| `--ssl=inherit` (no parent, parent with wildcard, with `--wildcard`) | ssl.feature 3 scenarios |
| `--ssl=<unknown>` (rejected only after the site is built, PHPT-17) | ssl.feature "--ssl with an unknown value fails and rolls back" |
| `--ssl=le` | ssl.feature `@le` (create, verified HTTPS, issuer, delete + revoke) |
| `--wildcard` with `--ssl=le` | not covered: needs Cloudflare DNS-01 credentials (not configured here) |
| rollback (`catch_clean`) leaves no site row, dir, volumes, containers or network | ssl.feature "--ssl with an unknown value" and "--ssl=inherit together with --wildcard" (real level-4 rollbacks); the other failing creates stop in validation and assert the row and dir only |
| www redirect (non-www and www sites), no redirect without resolvable www | redirect.feature |

### Generated files, containers and side effects of create

| Item | Covered by |
|---|---|
| sites row (type, sub type, php version, paths, alias list, cache flags, SSL fields, DB fields) | create.feature "The site row holds the php defaults", database/cache/ssl features |
| `docker-compose.yml` (pinned images from img-versions.json, services, env, php network alias, no db/redis unless asked, nohttps on http sites) | create.feature "The compose file has the php services and no db or redis", cache/database/php-version features |
| `.env`, `main.conf` (upstream, root, server_name, php vs redis blocks), `custom.ini`, `user.conf`, `admin-tools.conf`, `index.php`, `app/html` removed, `app` symlinked to the htdocs volume, `check.html` removed | create.feature "The site files are generated from the php templates" |
| running containers (php, nginx, postfix; mailhog not started; + db/redis), labels, restart policy, networks | create.feature "The site containers, volumes and network are up", labels.feature, database/cache features |
| docker volumes (8, or 11 with local db) and network labels | create.feature, labels.feature, database.feature |
| volume prefix collision between names differing only in `.`/`-` | create.feature `@known-bug` (PHPT-4) |
| `/etc/hosts` entry (http and self-signed only) | create.feature, ssl.feature, alias-domains.feature |
| HTTP: 200 + PHP executes, pretty URLs, 404, hidden/backup files denied, fpm ping/status, nginx_status | create.feature "The site is reachable over HTTP and runs PHP", "nginx denies hidden and backup files", "The php-fpm status and ping endpoints are wired" |

### `ee site info [<site-name>] [--format=table|json]` (`PHP::info`)

| Item | Covered by |
|---|---|
| table rows: Site, Site Root, DB Host/Name/User/Password, DB Root Password (local db only), Alias Domains, E-Mail, SSL, SSL Wildcard, Cache | lifecycle.feature 3 info scenarios, create/database/ssl/cache/alias create outputs |
| `--format=json` | lifecycle.feature "site info --format=json dumps the site row" (+ db site) |
| site auto-detected from the current directory | lifecycle.feature "site info works from inside the site directory" (the site root; `app/` is a symlink into the volume, so ee's physical `pwd` has no site name there) |
| disabled site | lifecycle.feature "site info still works for a disabled php site" |
| missing / unknown site | site.feature "Check site info sub command is present", lifecycle.feature "site info of an unknown site fails" |
| "Access admin-tools" row | not covered: admin-tools-command's suite owns enabling admin tools |

### `ee site restart [<site-name>] [--all] [--nginx] [--php] [--db]` (`PHP::restart`)

| Item | Covered by |
|---|---|
| no flag: nginx + php + local db restarted, postfix not | lifecycle.feature "site restart restarts nginx, php and the local db, not postfix" |
| `--php` only | lifecycle.feature "site restart --php restarts only php" |
| `--db` with local db | lifecycle.feature "site restart --db restarts the local db" |
| `--db` without local db | lifecycle.feature `@known-bug` (PHPT-13) |
| `--all`, `--nginx` alone | not covered separately (same code path as no flag / `--php`) |

### `ee site reload [<site-name>] [--all] [--nginx] [--php]` (`PHP::reload`)

| Item | Covered by |
|---|---|
| no flag: nginx + php-fpm (USR2) reloaded, containers not restarted | lifecycle.feature "site reload reloads nginx and php-fpm without restarting containers" |
| `--php`, `--nginx` | lifecycle.feature "site reload --php and --nginx reload a single service" |
| disabled site refused | lifecycle.feature "site reload of a disabled php site is refused" |
| missing site | site.feature "Check site reload sub command is present" |
| `--all` | not covered separately (same path as no flag) |

### site-command subcommands on a php site

| Item | Covered by |
|---|---|
| `update --php` (compose image, container version, backup dir, custom.ini kept, 5.6 <-> msmtp/sendmail) | php-version.feature 3 update scenarios |
| `update --php` same version / invalid / `latest` / disabled / unknown site | php-version.feature 4 scenarios |
| `update --add-alias-domains` / `--delete-alias-domains` (+ all error paths, wildcard) | alias-domains.feature 9 update scenarios |
| `update --ssl=self` / `--ssl=off` / already enabled / already disabled / `--ssl=off --wildcard` (on a wildcard site) / normal to wildcard | ssl.feature 3 scenarios |
| `update --ssl=off` on a site created with `--ssl=self` | ssl.feature `@known-bug` (PHPT-10) |
| `update --ssl=le`, `--wildcard` | not covered (LE budget; wildcard needs DNS-01) |
| `update --proxy-cache=on/off` (conf.d, vhost.d incl. alias, already-on error) | lifecycle.feature `@destructive` (restarts nginx-proxy) |
| `--proxy-cache-max-size/-max-time/-key-zone-size` | not covered: site-command generic |
| `enable` (containers, post-enable, already enabled, `--force`) | lifecycle.feature 2 scenarios |
| `refresh` | lifecycle.feature "site refresh regenerates the docker-compose file" |
| `enable --refresh` | not covered separately (same regeneration) |
| `enable --verify` | not covered: site-command generic |
| `disable` (containers removed, volumes/files kept, HTTP down, idempotent) | lifecycle.feature 2 scenarios |
| `list` (`--format=json/text`, `--enabled`, `--disabled`) | lifecycle.feature 2 scenarios |
| `delete` (row, dir, containers, volumes, network, hosts; redirect conf; global DB + user dropped; local db volumes) | lifecycle.feature "Delete a php site and everything it owns", redirect.feature "Deleting a site removes its redirect config" (asserts the conf existed first), database.feature 2 delete scenarios |
| `delete` twice / unknown site | lifecycle.feature "Deleting a php site twice fails the second time" |
| `delete` of an LE site removes certs | ssl.feature `@le` ("Revoking certificate." is logged before the attempt and revocation errors are swallowed, so revocation itself is not proven) |
| `clean` without cache / `--proxy` without proxy cache | cache.feature "ee site clean on a site without cache" |
| `clean --page` on a php cache site | cache.feature `@known-bug` (PHPT-8, CACHE-1) |
| `clean` on a local-redis site | cache.feature `@known-bug` (PHPT-9) |
| `clean --object`, `clean --proxy` with proxy cache | not covered: `--object` shares PHPT-8's path; `--proxy` runs `docker exec -it` (CACHE-1) |
| `ssl-verify`, `ssl-info`, `ssl-renew`, `share`, `clone`, `sync`, `backup`, `restore` | not covered here: generic, owned by the site-command suite (no php-specific code) |
| `site_cleanup` hook on delete | indirectly (delete scenarios); hook consumers are tested in their packages |

Estimated coverage: about 88% of the inventory items above (php-specific items: about 95%; the gaps are the remote-DB verification paths that need a reachable remote MariaDB, `--force`, LE wildcard, and flags that share code paths with covered ones). Several items counted before only passed vacuously (`--ssl=off --wildcard`, the directory auto-detect, rollback, the redirect delete, the combo cache checks); they now assert the real behaviour.

## Known bugs exercised (`@known-bug`, 24 scenarios incl. outline examples)

Every `@known-bug` scenario asserts the correct behaviour, so it fails until the bug is fixed, and names its issues.md ID in a comment: SEC-1, DB-1 (2), MIG-16, CACHE-1, and the site-type-php section `PHPT-*` (cross-referenced with the site-type-wp `WPT-*` entries for the shared DB-name collision):

| ID | Severity | Scenario(s) |
|---|---|---|
| PHPT-1 | High (data loss) | database.feature "Reusing another site's --dbname fails without dropping that database" |
| PHPT-2 | High (data loss) | database.feature "Two site names that map to the same default db name ..." (the pair also collides on volumes, PHPT-4) |
| PHPT-3 | High (data loss) | database.feature "Reusing another site's --dbuser fails without dropping that user" |
| PHPT-4 | High (data mixing) | create.feature "Two site names that differ only in dots and dashes get separate volumes" |
| PHPT-5 | Medium | database.feature "An unreachable remote db host fails the create and rolls back" |
| PHPT-6 | Medium | create.feature "--php=<version> without --type keeps the requested version" |
| PHPT-7 | Medium | alias-domains.feature "An alias domain that is an existing site is refused on create" |
| PHPT-8 | Medium | cache.feature "ee site clean --page purges the php page cache in the global redis" |
| PHPT-9 | Medium | cache.feature "ee site clean purges the page cache of a local-redis site" |
| PHPT-10 | Medium | ssl.feature "SSL of a site created with --ssl=self can be disabled" |
| PHPT-11 | Low | create.feature outline "A --php value that only loosely matches ..." (4 examples) |
| PHPT-12 | Low | public-dir.feature "A bare --public-dir without a value does not create the document root 1" |
| PHPT-13 | Low | lifecycle.feature "site restart --db on a php site without a local db fails" |
| PHPT-14..18 | Low / cosmetic | not tested (no clean black-box assertion): php sites render `.env`/`php.ini` from site-type-wp's templates; site-command's `ltrim($url, 'www.')` character-list bug; `var_dump()` in the inherit error; `--ssl` options not enforced on create (PHPT-17 is documented by the `--ssl=bogus` rollback scenario); no `/etc/hosts` entry for custom/inherit SSL sites |

Other notes from the code: `--php=latest` maps to the php8.4 image, not the newest supported (8.5), maybe intended; `update --add/--delete-alias-domains` doesn't regenerate `main.conf` (stale `server_name`, harmless); a site created with `--dbhost=global-db:<port>` and user-supplied credentials is stored with `db_host=global-db`, so `site delete` drops that pre-existing database and user. The old suite's label assertion was inverted and its mysql checks passed vacuously (DB-1); both fixed in this context.

## Tags

- `@known-bug` (24): expected to fail until fixed; exclude with `--tags='~@known-bug'`.
- `@le` (1): issues one Let's Encrypt certificate for `bh-site-type-php-1.ee4-test.rt.gw` (plus www) and deletes the site in the same scenario. The name is fixed, so more than 5 runs in a week hit LE's duplicate-certificate limit. Needs `le-mail` in config.yml (checked first: stdin is /dev/null, so ee can't prompt). On CI it runs against Pebble (`behat/ci/pebble-setup.sh`): the issuer check reads `EE_BEHAT_LE_ISSUER` and the DNS check `EE_BEHAT_LE_DNS_SERVER` (defaults: Let's Encrypt, 1.1.1.1).
- `@le-real`: none; the `@le` scenario passes on Pebble.
- `@destructive` (3): `update --proxy-cache` restarts the global nginx-proxy container (self-restoring); the blank-alias and space-alias regressions (fixed by #114, merged 2026-09-25; they passed on develop in https://github.com/mrrobot47/site-type-php/actions/runs/36099146918 and are regular scenarios since) would render an invalid nginx-proxy config for ~2 minutes if they regressed.
- `@slow` (12): the PHP version matrix (one site per version).
- `@security` (1): the SEC-1 payload (benign: `id>bhstpsec1` in the core root, removed after the scenario and by the cleanup).
- `@remote`: none (no backups in this package).

## Environment rules followed

- Sites: `bh-site-type-php-<n>.test` (and `sub.`/`sub2.` children, `-<n>-x` variants); aliases `bh-site-type-php-<n>-<x>.test`; global-db names `bh_stp_*` or the derived `bh_site_type_php_*`. `FeatureContext::cleanupTestState()` runs before the suite, after each feature and after the suite, and only touches these names: sites (children first), orphaned containers/networks/volumes/site dirs/proxy files, the suite's redis page-cache keys, `bh_stp_*`/`bh_site_type_php_*` databases and `bh_stp_*`/`bh-site-type-php-*` users that no remaining site row references, and `/etc/hosts` lines with the `# behat:site-type-php` marker or whose every name is a suite name.
- Data-loss guards (PHPT-1..4): the suite refuses to start when a foreign site's volume prefix, derived db name, db name or db user could collide with the suite's names; the db setup steps only accept suite names; the data-loss known bugs only ever point the buggy rollback at databases and users the scenario itself created.
- Commands run with stdin on `/dev/null`, so docker/compose never allocate a TTY (no CACHE-1 hangs; `docker exec -it` fails fast instead).
- HTTP checks for aliases/extra names pin the host to 127.0.0.1 with curl's `--resolve` (keeps SNI for HTTPS); 127.0.0.1 arrives at nginx-proxy as 10.1.0.1 and bypasses HTTP auth. Only the `@le` scenario goes through public DNS to the public IP (it would get a 401 if global HTTP auth were enabled).
- Temporary files (custom certs) live in `/var/tmp/bh-site-type-php`, removed after the suite.

## Site number registry

1-9 create, 10-26 database (22.x/22-x collision), 2-7/27 create (volume collision), 30-38 cache, 40-55 php versions, 60-65 public dir, 70-79 alias domains, 80-89 ssl (85 + sub/sub2 children), 90-93 labels/redirect, 100-106 lifecycle, 197-199 never created. LE: `bh-site-type-php-1.ee4-test.rt.gw`.

## Runtime estimate

About 80 site creations (about 35 s each), plus deletes, updates and restarts: roughly 70-80 minutes for the whole suite, about 60 minutes with `--tags='~@slow'`, and about 55 minutes with `--tags='~@slow&&~@le&&~@known-bug'`.

## Validation done

- `vendor/bin/behat --dry-run --strict` in a private core copy on `upstream/develop`: no undefined or ambiguous steps (159 scenarios, 1088 steps). Behat 3.8 exits 1 on `--dry-run --strict` because every step is "skipped"; the upstream suite does the same.
- Copy the features with `cp -r`, not `cp -a`: Behat's Gherkin cache in `/tmp/behat_gherkin_cache` is keyed on mtime, so preserving older mtimes serves stale parses.
- `php -l` of `features/bootstrap/*.php` on php:7.4-cli and php:8.5-cli.
