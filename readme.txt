=== Qasim PHP Upgrade Check & Guard ===
Contributors: qasimwp
Tags: php, compatibility, php 8, upgrade, php version
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Check your plugins and themes for code that breaks on a newer PHP version, see which of it really runs, and catch new crashes after a PHP change.

== Description ==

Your host wants to move your site to a newer PHP version. Will your plugins survive it? Qasim PHP Upgrade Check & Guard reads the code of your plugins, themes, must-use plugins and special WordPress files (such as object-cache.php) and tells you, in plain words, what would break.

**Check**

Pick the PHP version you plan to move to (8.0 to 8.5) and click Check. Every plugin and theme gets one of four answers:

* **Will break**: this code stops working on the new PHP version.
* **Ask your host**: the code needs a PHP feature that newer PHP no longer includes by default (for example IMAP).
* **Works, fix later**: works, but shows a warning and will stop working in a future version.
* **Ready**: nothing found.

You get a short "Do this first" list, the file and line of every problem with what it means and how to fix it, a CSV report, a print view and a text you can copy to your developer or host. The check runs in small steps in the background, so it also works on shared hosting.

**Smart results (optional, off by default)**

Turn it on and Qasim PHP Upgrade Check & Guard learns which plugin files your site really loads, for example on checkout, shop pages or the admin. Each problem then says where it runs, or that it was not seen running in the last days, so you can fix what matters first. It looks at some page views only (1 in 4 by default) and stores file names and counts, nothing about your visitors.

**Upgrade guard (optional, off by default)**

Turn it on before your host changes PHP. Qasim PHP Upgrade Check & Guard learns how your site normally behaves, notices the PHP change by itself and compares the next 7 days with before. If a plugin starts crashing or showing new errors, the Upgrade guard tab names it and tells you what to do. It can also email the site's admin address about new crashes (off by default).

**What Qasim PHP Upgrade Check & Guard does not do**

* It does not run or change your code. It reads files as text.
* It is not a guarantee. Code that is built at run time, or loaded in unusual ways, can be missed. Always test on a staging copy before you upgrade a live shop.
* The rules for PHP 8.5 may be incomplete.
* It does not switch your PHP version. Your host does that.
* All PHP compatibility scanning and 7-day Upgrade guard monitoring run 100% locally on your server. The plugin makes zero external API requests, loads no remote assets, and sends no telemetry.

== Installation ==

1. Install Qasim PHP Upgrade Check & Guard from Plugins > Add New, or upload the zip under Plugins > Add New > Upload Plugin.
2. Activate it.
3. Go to Tools > PHP Upgrade and click Check.
4. Optional: in the Settings tab, turn on Smart results and Upgrade guard.

WP-CLI users can also run `wp qpucg scan --target=8.4`.

== Frequently Asked Questions ==

= What does "not seen running" mean? =

With Smart results on, Qasim PHP Upgrade Check & Guard didn't see that code run on the page views it looked at during the last days. It may still run at other times, for example once a month or only for one user. Treat it as "probably less urgent", not as "safe to ignore".

= The check says a file is too large for the memory limit. What now? =

Large files need more memory to check. Ask your host to raise the PHP memory limit to 512M, then check again. You can also run the check with WP-CLI, which usually has more memory.

= Does it check must-use plugins? =

Yes. Must-use plugins and special WordPress files (drop-ins such as object-cache.php) are checked like normal plugins. Upgrade guard cannot see crashes that happen before Qasim PHP Upgrade Check & Guard loads (such as in must-use plugins or during initial file loading of earlier plugins), though runtime crashes inside hooks, pages, checkout and admin are captured.

= Where is my data stored? =

In your own WordPress database, in Qasim PHP Upgrade Check & Guard's own tables (with the prefix `qpucg_`) and a few `qpucg_` options. See Privacy below.

= Will it slow my site? =

The check runs only when you start it. Smart results and Upgrade guard are off until you turn them on; when on, they look at some page views only and write to the database once, at the end of those page views.

= Does it work on multisite? =

Each site has its own check and data under Tools > PHP Upgrade. Deleting the plugin from the network removes the data of every site (unless a site turned off "Delete all data").

== Privacy ==

Qasim PHP Upgrade Check & Guard makes no remote requests, has no tracking and loads no files from other servers.

What is stored, and where: everything is stored in your own WordPress database, in Qasim PHP Upgrade Check & Guard's tables and options.

* Check results: the file name, line and problem of each finding, and a cache of unchanged files so the next check is faster.
* Smart results (only if you turn it on): the names of the plugin and theme files that loaded, counted per day and per type of page (for example checkout or admin). No visitor, customer, IP address or URL is stored.
* Upgrade guard (only if you turn it on): PHP errors from plugins and themes, with the plugin, file, error type, counts and dates. The error message is cleaned before it is stored: only its first line is kept, and URLs, email addresses and IP addresses are removed. A plugin can still put other text in its own error message, so the cleaning is best effort.

Smart results, Upgrade guard and the crash email are all off by default.

The only data that can leave your site is the optional crash email. It is off by default, and when turned on it is sent to your site's admin email address, at most once a day.

How long it is kept: collected data older than 30 days is removed automatically, and each finished day is summarised and its raw rows deleted. Qasim PHP Upgrade Check & Guard keeps its collected data under 50 MB. The latest check results stay until you check again, until you click "Delete collected data" in Settings, or until you uninstall Qasim PHP Upgrade Check & Guard. Uninstalling removes all of Qasim PHP Upgrade Check & Guard's tables and options, unless you turned off "Delete all data when Qasim PHP Upgrade Check & Guard is uninstalled".

== Screenshots ==

1. The answer after a check: one sentence, four counts and what to do first.
2. A plugin opened: each problem with what it is, how to fix it and what it means for your site.
3. A check running in the background, with progress.
4. Upgrade guard, waiting for your host to change PHP.
5. Settings: Smart results, Upgrade guard and the crash email, all off by default.

== Changelog ==

= 1.0.0 =
* First public release.

== Credits and licenses ==

Qasim PHP Upgrade Check & Guard is free software under the GNU General Public License, version 3 or later.

The check uses these open-source projects, bundled in `vendor-prefixed/` with their own license files:

* PHP_CodeSniffer 4.0.4, BSD-3-Clause, https://github.com/PHPCSStandards/PHP_CodeSniffer
* PHPCompatibility 10.0.0-alpha2, PHPCompatibilityWP 3.0.0-alpha2 and PHPCompatibilityParagonie 2.0.0-alpha2, LGPL-3.0-or-later, https://github.com/PHPCompatibility. Version 10 is still marked alpha by its authors; it is the only version with rules for PHP 8.
* PHPCSUtils 1.2.3, LGPL-3.0-or-later, https://github.com/PHPCSStandards/PHPCSUtils
* Composer's class loader, MIT

The bundled code in `vendor-prefixed/` contains only the stripped, namespace-prefixed runtime parser libraries required to statically check PHP files (zero dev dependencies, tests, docs, or CLI binaries). It is renamed into the `Qpucg\Vendor` namespace (with PHP-Scoper) so it cannot clash with other plugins, and any lines that would run shell commands or `eval()` are removed.

Source code: https://github.com/qasimwp/qasim-php-upgrade-check-guard . To reproduce the bundled scan engine and release package from source, run `php bin/build-engine.php` followed by `php bin/build-zip.php` (full build requirements are in the repository README).
