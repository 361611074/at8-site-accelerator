=== AT8 Site Accelerator ===
Contributors: x361611074
Tags: cache, page cache, redis, lazy load, webp
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.5
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

**1. Page cache** - full-page caching, TTL, separate mobile variant, URL/cookie/query-argument bypass rules. Logged-in visitors are always excluded from the shared cache.

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
* It will not leave a copy of `wp-config.php` on disk. When the plugin enables `WP_CACHE`, the original is written to a temporary file that is deleted as soon as the change has been verified - whether it succeeded or was rolled back.
* It will not cache pages for logged-in users.
* It will not delete your settings or cache on deactivation.
* It will not delete your data on uninstall unless you explicitly turn off "keep data on uninstall".
* It does not use `FLUSHDB`, does not use `eval`, and does not call any shell command.
* It contains no licence key check, no activation server, and no code that downloads or installs anything.
* It contacts no external server. The only outbound link is the optional Pro information link on the plugin's own settings screen, and it is a normal link you have to click.

== Pro Version ==

AT8 Site Accelerator has an optional Pro edition that adds licence management, manual cache preheating and single-post post-publish preheating.

The Free edition is complete on its own: it is not a demo, nothing is locked after a trial period, and no feature asks for a purchase. Pro is installed separately, at your own initiative, by uploading it through the normal WordPress plugin installer.

[AT8 Site Accelerator Pro](https://www.at8.fun/product/at8-site-accelerator-pro/)

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

No. Every key carries a per-site salt prefix - the plugin's own namespace plus the site's blog ID, its home URL and the cache version - so invalidation only deletes keys under this site's prefix, and `FLUSHDB` does not appear anywhere in the codebase.

= Why don't logged-in visitors get the page cache anymore? =

Because a cache entry is not tied to a specific person. Caching a page for a signed-in visitor would mean one visitor could be served a page that was generated for another. Since 3.0.5 signed-in visitors always bypass the cache, and the old "also cache logged-in users" switch has been removed together with its stored setting.

= Is Multisite supported? =

Yes. Each site gets its own cache directory and its own Redis key prefix.

== Changelog ==

= 3.0.5 =
* Fixed: **enabling "WP_CACHE" used to leave a readable copy of your `wp-config.php` on the web server.** That file contains your database password, your four secret keys and all eight salts, and the copy sat next to the original where any visitor - or any bot - could have asked for it. The original is now only ever written to a temporary file that is deleted the moment the change has been checked, whether it was applied or rolled back.
* Fixed: **plugin notices were shown on every admin screen.** They are now limited to this plugin's own settings screen plus the Plugins and Dashboard screens, so nothing appears on other plugins' pages or on your posts, media, users or tools screens.
* Removed: **the "also cache pages for logged-in visitors" option.** The cache key did not contain anything visitor-specific, so one visitor's page could have been handed to another. Logged-in visitors are now always excluded from the shared cache, and the option is gone from the settings screen.
* Fixed: a request with a `Host` header of "." or ".." could have made the plugin read and write cache files outside its own cache directory. Host names are now normalised, and such values are rejected.
* Added: a read-only "Pro" tab on the settings screen with a short description and a link. It is static text - no popup, no automatic download, no tracking, and no notice anywhere else in the admin.
* Verified with the full test suite: 318 checks pass, including new checks for every item above.

= 3.0.4 =
* Fixed: **the plugin no longer overwrites another caching plugin's `advanced-cache.php`.** That file is a single shared slot, and previously merely activating this plugin would silently replace a drop-in left there by WP Super Cache, W3 Total Cache, LiteSpeed Cache or a host environment. Installation is now skipped whenever the existing file does not belong to this plugin, and the reason is shown instead of failing quietly. Removal was already restricted to the plugin's own file and still is.
* Fixed: the drop-in file now carries an explicit owner marker, so "is this file ours?" is answered by the file itself rather than by guessing from its contents.
* Fixed: **"Disable Heartbeat on the front end" was also disabling it inside wp-admin.** The setting said front end only, but the backend Heartbeat was deregistered too, which can break autosave and live notifications. The wp-admin Heartbeat is now always left alone; use "Reduce frequency" to slow the backend down as well.
* Fixed: **"Remove WordPress Events and News" was also removing Site Health, the browser-version notice and the PHP-version notice.** Each switch now removes only its own card. The Events and News switch also never actually worked, because it targeted the wrong dashboard column.
* Fixed: uploading a PNG could cause a fatal error on servers whose GD build lacks PNG support. Support is now detected per image format before any GD function is called, so an unsupported format is skipped instead of failing.
* Fixed: when "Cache separately for mobile" was enabled, only cache hits sent `Vary: User-Agent`; misses, newly stored pages and fallback responses did not, so a CDN or reverse proxy could still serve the desktop page to a phone. Every cache response now sends it.
* Changed: cache keys and the generated runtime configuration no longer contain the site's `COOKIEHASH`. Cache namespacing now uses the plugin's own identifier together with the site's blog ID and home URL, which isolates sites just as well without copying an authentication-related value into cache directory names, Redis keys and on-disk configuration.
* Changed: the conflict scanner no longer loads WordPress admin core files; it reads the same information through public WordPress APIs.
* Changed: notices, dashboard notices and the settings-page layout were verified to load only on this plugin's own screen, and the settings descriptions now state exactly what each switch does.

= 3.0.3 =
* Fixed: **with WooCommerce active, requests carrying the `woocommerce_items_in_cart` cookie were no longer excluded from the cache.** WooCommerce's `WC_Cart_Session` removes that cookie from `$_COOKIE` while the request is being handled, so the second cache-admission check - the one that runs after WordPress has loaded - could no longer see it and stored a response it should have skipped. Both checks now read the same snapshot of the cookies as the browser sent them, taken before any plugin can modify them. This applies to every cart and session cookie in the exclusion list, not just this one.
* Changed: cache admission now reads an explicitly taken cookie snapshot instead of the live `$_COOKIE`, so the drop-in and the plugin can never disagree about what the visitor actually sent.

= 3.0.2 =
* Fixed: **upgrading from 3.0.1 could have left the whole site - front end and wp-admin - blank.** `advanced-cache.php` is a drop-in that gets copied into `wp-content/`; a plugin upgrade never touches it, and this release renames the plugin namespace, so an old copy would have called classes that no longer exist. That is a PHP fatal which runs before WordPress can load, so nothing can repair it afterwards. The drop-in now fails safe (falls back to "no page cache") instead of fataling, carries a version stamp, and rewrites itself on the next request. A compatibility alias keeps already-installed old copies working until that rewrite happens.
* Fixed: saving the same post twice within one request only invalidated the cache once, so the second save could leave a stale page in the cache until the TTL expired.
* Fixed: while pages were being served from cache, the drop-in and the runtime configuration file were never refreshed, because the cache-hit path exits before the cleanup hooks run. Both now run early enough to always be reached.
* Fixed: on a fresh install the settings screen could claim "old 2.x settings were detected and migrated" even though there was nothing to migrate, and a copy of that message was shown on every admin screen where it could not be dismissed. It now appears only on the plugin's own settings screen, and only when a real migration happened. Plugin Check: renamed the namespace so every symbol starts with the four-character prefix that Plugin Check derives from the code (`at8sa`), and cleared the remaining coding-standard, text-domain and direct-database-query warnings reported by Plugin Check 2.1.0. The plugin now reports zero errors.

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

= 3.0.5 =
Recommended for every 3.0.4 user. This release removes a leftover copy of `wp-config.php` that could expose your database password and secret keys, stops plugin notices from appearing across the whole admin, and removes the option that allowed logged-in visitors to share cached pages. If you were using "cache logged-in users", the cached pages built with it are discarded automatically - after this update, logged-in visitors always bypass the cache. No action is required.

= 3.0.3 =
Recommended for all users, especially sites running WooCommerce. Fixes a case where the shopping-cart cookie stopped being honoured by the cache, so a page that should never have been cached could be stored. No settings changes are needed.

= 3.0.2 =
Recommended for all 3.0.1 users. This release renames the plugin namespace; the advanced-cache.php drop-in copied into wp-content/ is not replaced by an upgrade, so it now fails safe and repairs itself. Also fixes cache invalidation when a post is saved twice in one request.

= 3.0.1 =
Recommended for every 3.0.0 user. Fixes two subtle issues: settings changed from WP-CLI or cron had no effect on the front end, and each cache purge left unreleased data in Redis. Retrying "enable WP_CACHE" now completes it automatically.

= 3.0.0 =
Upgrading from 2.x migrates your settings automatically. After upgrading, check the "Diagnostics" tab once to confirm the environment status.
