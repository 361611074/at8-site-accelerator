=== AT8 Site Accelerator ===
Contributors: at8fun
Tags: cache, page cache, redis, lazy load, webp
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight full-page cache with surgical invalidation, smart preloading, HTML minification, WebP conversion and database cleanup.

== Description ==

AT8 Site Accelerator splits "make WordPress fast" into eleven independent modules that can each be switched off on their own. Every module makes an explicit trade-off, and every trade-off is documented.

= Why "surgical invalidation" instead of "purge everything" =

Most caching plugins delete the entire site cache whenever a post is saved. On a site with any real traffic that means: one edit = the whole cache is empty = the next batch of visitors all hit the database. The cache plugin becomes the source of the load spike.

This plugin invalidates only the URLs that are actually affected when a post is saved: the post itself, the home page, related archives, the post's category/tag/author archives, and their paginated pages. Everything else keeps its cache.

= The cache hit path =

Once "advanced cache" is enabled, the plugin writes `wp-content/advanced-cache.php`. On a hit it sends the cached response and ends the request **before** WordPress finishes booting - no database queries, no theme, no other plugins.

= Choosing a backend =

* **Redis** - uses a built-in pure-PHP RESP client, so the `phpredis` extension is not required. When several sites share one Redis instance, keys are isolated per site by a salt, and `FLUSHDB` is **never** used (it would wipe other sites).
* **Disk** - the directory layout mirrors the URL structure (`cache/at8-site-accelerator/<domain>/<path>/index.html`), so "invalidate one URL" becomes "delete one directory".
* **Auto** - prefers Redis and falls back to disk when Redis is unreachable. The probe result is cached for one hour so that every request does not pay a connection timeout.

= Modules =

**1. Page cache** - full-page caching, TTL, separate mobile variant, whether to cache logged-in users, URL/cookie/query-argument bypass rules.

**2. Invalidation strategy** - surgical invalidation (default) or full purge; whether saving a post should also purge the home page.

**3. Smart preloading** - prefetches the target page when a visitor hovers or touches a link, turning "click" into "instant". Respects `Save-Data` and slow connections, and stops automatically while the page is hidden.

**4. Browser caching** - long-lived cache headers for static assets, plus ready-to-paste nginx / Apache rule snippets (the plugin never edits your `.htaccess` on its own).

**5. HTML minification** - removes comments and redundant whitespace. The contents of `pre` / `textarea` / `script` / `style` / `svg` are preserved verbatim, and IE conditional comments are kept. Includes a safety valve: if the minified size drops below 40% of the original, the result is treated as broken and discarded.

**6. Image lazy loading** - uses the browser-native `loading="lazy"` attribute with no JavaScript at all. The first two above-the-fold images are explicitly marked `loading="eager"` so LCP is not delayed.

**7. Front-end cleanup** - emoji script, embeds, generator tag, jQuery Migrate, Dashicons, block editor styles, static asset query strings, Heartbeat frequency.

**8. Images** - automatically creates a WebP copy when a JPEG/PNG is uploaded (PNG transparency preserved). If the copy ends up larger, it is discarded.

**9. Database cleanup** - revisions, auto-drafts, trashed posts, spam/trashed comments, expired transients, table optimization. **Everything is off by default**: cleanup is destructive, so you must opt in per item. A count preview is shown before anything runs.

**10. Admin cleanup** - site health, activity and news widgets, version checks, oversized thumbnails.

**11. Diagnostics and safety** - environment health report (facts only, no scoring), conflict detection, structured logging (off by default, long hex strings and tokens are redacted before writing), and a safe mode that disables caching globally with one click.

= Compatibility =

* **Elementor** - three layers of protection: check that the referenced `post-*.css` really exists before writing, a cache version salt, and an asynchronous style rebuild after the response ends, so visitors never get a stale page with a 404 stylesheet.
* **WooCommerce** - cart, checkout, my account and order endpoints are never cached; changing stock invalidates exactly the affected product page.
* **Other caching plugins** - conflicts are detected and reported (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Autoptimize, FlyingPress, Perfmatters, Cache Enabler, Swift Performance, Nginx Helper, Hummingbird).

= What this plugin will not do =

* It will not modify WordPress core files.
* It will not overwrite your `.htaccess` (it only gives you rule snippets; you decide whether to apply them).
* It will not delete your settings or cache on deactivation.
* It will not delete your data on uninstall unless you explicitly turn off "keep data on uninstall".
* It does not use `FLUSHDB`, does not use `eval`, and does not call any shell command.

== Installation ==

1. Upload the `at8-site-accelerator` folder to `/wp-content/plugins/`.
2. Activate the plugin on the Plugins screen.
3. Open the "AT8 Accelerator" menu and follow the prompts to enable `WP_CACHE` and install the drop-in.

= Frequently Asked Questions =

= Why is the cache not working? =

Check the first item on the "Diagnostics" tab. The three most common causes are: the `wp-content` directory is not writable, `WP_CACHE` is not enabled in `wp-config.php`, or another caching plugin on the same server is already occupying the `advanced-cache.php` drop-in slot.

= Why is the cache not fully purged after I save a post? =

That is by design - see "Why surgical invalidation" above. If you really want a full purge, switch the strategy to "purge everything" on the "Invalidation" tab.

= Can Redis wipe data belonging to other sites? =

No. Every key carries a per-site salt prefix (derived from this site's `COOKIEHASH` and the cache version), invalidation only deletes keys under this site's prefix, and `FLUSHDB` does not appear anywhere in the codebase.

= Is Multisite supported? =

Yes. Each site gets its own cache directory and its own Redis key prefix.

== Changelog ==

= 3.0.1 =
* Fixed: on some sites the random keys (salts) in `wp-config.php` contain `{` or `}`, which made "enable WP_CACHE with one click" report a write failure and roll itself back, so the advanced cache never became active. Braces are now counted with a real PHP lexer, so string contents are no longer counted as code.
* Fixed: the `wp-config.php` write check now also verifies reversibility - removing the line the plugin added must reproduce the original file exactly, preventing accidental edits to site configuration.
* Fixed: clicking "enable WP_CACHE" twice no longer falsely reports "cannot rewrite automatically".
* Fixed: unit tests failed spuriously on machines with Redis installed (the purger asserted on disk files, but auto mode selected the Redis backend). The backend is now pinned, so results no longer depend on the host.
* Fixed: after clicking "purge cache" the cache entry count showed 0 even though the cache had not really been cleared - the cache version changed but the backend instance was still bound to the old version. The stale instance is now invalidated immediately.
* Fixed: **changing settings from WP-CLI, a cron job or another plugin had no effect on the front end**, which kept running with the old configuration (for example, switching backends did nothing). The sync logic used to run only inside wp-admin and now runs in every runtime context.
* Fixed: **"purge cache" left permanently unreleased data in Redis.** The previous order of operations left a batch of index data behind on every purge, and it never expired, so memory usage grew over time. The order is fixed and historical leftovers are cleaned up as well.
* Fixed: with the Redis backend, the entry count reported after "purge cache" was always 0; it is now counted correctly.
* Fixed: the "also minify inline CSS" toggle on the settings screen had no effect; it is now implemented (note: block themes already emit compact inline styles, so the measured gain is close to zero and enabling it just for this is not recommended).
* Fixed: the `X-AT8-Cache-Backend` response header used inconsistent casing between "served directly by the advanced cache" and "served by the plugin"; both now report `Redis` / `Disk`.
* Coding standards: the whole codebase passes WordPress Coding Standards (previously 535 violations) and the pipeline is now blocking, so any new violation turns CI red.
* Engineering: added 208 unit test cases and static analysis (PHPStan level 5, 0 errors), and CI now really exercises the Redis code paths on a machine with Redis installed (previously those branches were never covered automatically).

= 3.0.0 =
* Full rewrite: modular architecture (Cache / Purge / Optimization / Compatibility / Diagnostics / Admin / REST).
* New: Redis and disk backends with automatic fallback.
* New: per-URL surgical invalidation, replacing full purges.
* New: REST API (`at8sa/v1`).
* New: diagnostics report, conflict detection, structured logging, safe mode.
* New: native lazy loading, automatic WebP conversion, database cleanup.
* All 2.x settings are supported and migrated automatically on upgrade; old settings are never deleted.

The full technical change list (including the reason behind every fix) is in `CHANGELOG.md` at the root of the repository.

== Upgrade Notice ==

= 3.0.1 =
Recommended for every 3.0.0 user. Fixes two subtle issues: settings changed from WP-CLI or cron had no effect on the front end, and each cache purge left unreleased data in Redis. Retrying "enable WP_CACHE" now completes it automatically.

= 3.0.0 =
Upgrading from 2.x migrates your settings automatically. After upgrading, check the "Diagnostics" tab once to confirm the environment status.
