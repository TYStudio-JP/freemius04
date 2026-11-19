=== Upgrade Warden – PHP 8 Compatibility Scanner ===
Contributors: tystudiojp
Tags: php, compatibility, php 8, deprecated, upgrade
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows, line by line, what PHP 8.0 to 8.6 will break, change or deprecate in your plugins and themes. Every rule was checked on real PHP versions.

== Description ==

Your host is about to switch the PHP version, or already has, and one old plugin or theme turns the site into a white page. Upgrade Warden tells you beforehand which plugin, which file and which line, and what exactly PHP will do with it.

= What it does =

* **Scans every plugin and theme**, active or not, and must-use plugins. It reads the PHP files; it does not run them and changes nothing.
* **Answers for every PHP version from 8.0 to 8.6 from one scan.** Choose another version and the result is there; no second scan.
* **Three kinds of result, in plain words.** "Will break": PHP no longer runs the code. "Will behave differently": the code runs and does something else, without any warning. This is the kind nobody notices until a customer does. "Deprecation notices": it still works and PHP announces that it will stop.
* **Shows the line** of your code, says what it does that PHP changed, from which version, and what to write instead.
* **Knows a check when it sees one.** Code that only runs behind a PHP version check is listed as "behind a check", not as broken.
* **Run-time log.** A scan cannot know the data a page will handle. `count()` of something that turns out to be empty looks like any other call; PHP 7 answers it with a warning and PHP 8 stops the page. Switch recording on and Upgrade Warden notes the deprecation notices and fatal errors PHP raises on your live site, and the PHP 7 warnings that are known to become errors on PHP 8, with the file and the line. Look at it before you switch PHP, and after.

= Checked on real PHP, not copied from a list =

The usual way to build a compatibility checker is to read the PHP documentation and write the rules down. This plugin was built the other way round.

* For each of the 602 entries in the "backward incompatible changes" and "deprecated" sections of the official PHP 8.0 to 8.6 upgrade notes, there is a recorded verdict: detected by a rule, covered by the list of removed functions and constants, visible only while the code runs, not detectable from the code, or out of scope (Windows only, build options and so on).
* Behind the rules are 933 small test programs. Each one makes a claim such as "deprecated in 8.1, an error from 8.4", and each one was run on real PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5. A claim that a real PHP did not confirm was changed until it did. In several places PHP turned out to behave differently from its own upgrade notes; the plugin follows what PHP does.
* The explanations in the run-time log come from the same runs: 426 messages of PHP itself, each tied to the change it belongs to. The log was then checked by running all the test programs again with the plugin listening.
* PHP 8.6 could not be run yet. What the plugin says about 8.6 is taken word for word from the official upgrade notes, and the screen marks every such result as "from the official upgrade notes; not yet run on a real PHP of this version".

= What it cannot do =

* It reads code; it does not prove that a site works. Use it together with a staging copy if you have one.
* A scan cannot see problems that depend on data. That is what the run-time log is for, and the log only sees what actually happened while it was recording.
* It checks your plugins and themes, not WordPress itself. Keep WordPress up to date for that.
* It looks at one file at a time. A function that one file of a plugin declares and another file calls is recognised; a class declared in one file and extended in another is not followed.
* Files larger than 1.5 MB and the folders `.git` and `node_modules` are skipped.

= Privacy =

The scan and the log run on your own site. The log stores PHP's message, the file and the line, for 7 days after a message was last seen, in your own database. It stores no visitor, no address, no form data and no page content, and an uncaught exception of your own code is not stored at all, because its text can hold customer data. Nothing is sent anywhere (see "External services" for the optional Freemius opt-in).

= Pro =

The free edition finds. Upgrade Warden Pro repairs and keeps watch:

* **Automatic fixes** for the kinds of finding where the repaired code was run on real PHP 7.4 to 8.5 and printed exactly what the original printed. The fix changes only the characters in question, not the layout of the file, and the fixed code still runs on your current PHP, so you can fix first and switch PHP afterwards.
* **Preview, backup, undo.** You see every change as a diff before anything is written. The originals are kept, and Undo puts them back, unless a file was changed in the meantime, in which case it is left alone.
* **Weekly scan and scan after every update**, with an e-mail when a plugin or theme has more code that will break than before.
* **Run-time log for 90 days**, with a daily e-mail when something new stops a page.
* **Report** as a single HTML file to send to a client or a developer, or to print.

== External services ==

The scan, the run-time log and the fixes use no outside service. One thing can contact one, and it is your choice.

= Freemius =

This plugin uses [Freemius](https://freemius.com) for the opt-in below, for the upgrade page and, in the Pro edition, for licences and updates.

* After activation a screen asks whether you want to opt in. You can press "Skip"; then nothing is sent and the plugin works the same.
* If you opt in, your site sends to Freemius: the first name, last name and email address of your WordPress user; the address and title of the site, its WordPress and PHP versions and its language; the version of this plugin and whether it is active or was removed; and the names and versions of the installed plugins and themes. Later changes of these (an update, deactivation, removal) are sent as well. You can opt out again at any time from the "Opt Out" link of the plugin on the Plugins screen.
* The page "Upgrade" under Tools > Upgrade Warden shows prices and the purchase form from Freemius, which is loaded from freemius.com in your browser when you open that page.
* When you deactivate the plugin, a form may ask why. An answer is sent to Freemius only if you submit the form.
* No scan result, no line of your code and nothing from the run-time log is ever sent to Freemius.

Freemius: [Terms of service](https://freemius.com/terms/), [Privacy policy](https://freemius.com/privacy/)

== Installation ==

1. Install the plugin and activate it.
2. Go to Tools > Upgrade Warden.
3. Press "Scan plugins and themes". On a large site this takes a minute or two; the scan runs in short steps, so it does not time out, and you can leave the page and come back.
4. Choose the PHP version you want to move to. Open a plugin or theme to see the lines.
5. Optional: open the "Run-time log" tab and press "Start recording". Use the site for a few days, then look at what was recorded.

== Frequently Asked Questions ==

= Is it safe to run on a live site? =

Yes. The scan only reads files and writes its results to two database tables of its own. It runs in steps of a few seconds, so it does not hold up the server. Nothing in your plugins or themes is changed by the free edition.

= Which PHP version should I choose? =

The version your host offers or is about to enforce. If you are free to choose, pick the newest version that every plugin and theme you need is "Nothing found" or only "Deprecation notices" on.

= A plugin shows "Deprecation notices". Do I have to do anything? =

Not today. The code works on that version. It will stop working in a later one, so it is worth telling the author, and worth choosing a maintained alternative if the plugin is no longer updated.

= It says "Will behave differently". What does that mean? =

The code runs on the new version, without an error and without a warning, and gives another result than before. For example, a comparison of a number with a text that used to be true is now false. These are the changes that are hardest to notice, which is why they are listed separately.

= What does "from the official upgrade notes" mean? =

Results for PHP 8.0 to 8.5 rest on code that was run on those versions. PHP 8.6 could not be run yet, so what is new in 8.6 comes from PHP's own upgrade notes and is marked that way.

= Does the run-time log slow the site down? =

It does nothing unless PHP raises a message. When it does, the message is compared with a list in memory, and a new one is written to the database once; the same message is written again at most once an hour. A page that raises thousands of notices is cut off after 40 different ones.

= Why is recording off by default? =

Because listening to another plugin's errors is something you should decide. While recording is on, Upgrade Warden moves itself to the top of the list of active plugins, so that it hears what the others say while they load. Stop recording and it no longer does.

= Does it check WordPress core? =

No. WordPress keeps itself compatible with new PHP versions; update WordPress.

= Does it work on a multisite network? =

Yes. Each site of a network has its own results and its own log, so run the scan on the site you are looking at.

= What happens to my data when I delete the plugin? =

The plugin's database tables and settings are removed. Backups made by the Pro edition before a fix stay in `wp-content/uploads/upgrade-warden/` so that an original is never lost; delete that folder yourself when you no longer need it.

== Screenshots ==

1. Overview: every plugin and theme, with what happens on the chosen PHP version.
2. The findings of one plugin: the line, what it does, from which version, and what to write instead.
3. The run-time log: what PHP said while the site ran, and what it means on the chosen version.
4. Pro: the exact changes of an automatic fix, before anything is written.

== Changelog ==

= 1.0.0 =
* First release.
