=== Akismet Anti-Spam ===
Contributors: matt, ryan, andy, mdawaffe, tellyworth, josephscott, lessbloat, eoigal, cfinke, automattic, jgs, procifer, stephdau
Tags: akismet, comments, spam, antispam, anti-spam, anti spam, comment moderation, comment spam, contact form spam, spam comments
Requires at least: 4.6
Tested up to: 5.5
Stable tag: 4.1.6
License: GPLv2 or later

Akismet checks your comments and contact form submissions against our global database of spam to protect you and your site from malicious content.

== Description ==

Akismet checks your comments and contact form submissions against our global database of spam to prevent your site from publishing malicious content. You can review the comment spam it catches on your blog's "Comments" admin screen.

Major features in Akismet include:

* Automatically checks all comments and filters out the ones that look like spam.
* Each comment has a status history, so you can easily see which comments were caught or cleared by Akismet and which were spammed or unspammed by a moderator.
* URLs are shown in the comment body to reveal hidden or misleading links.
* Moderators can see the number of approved comments for each user.
* A discard feature that outright blocks the worst spam, saving you disk space and speeding up your site.

PS: You'll be prompted to get an Akismet.com API key to use it, once activated. Keys are free for personal blogs; paid subscriptions are available for businesses and commercial sites.

== Installation ==

Upload the Akismet plugin to your blog, activate it, and then enter your Akismet.com API key.

1, 2, 3: You're done!

== Changelog ==

= 4.1.6 =
*Release Date - 4 June 2020*

* Disable "Check for Spam" button until the page is loaded to avoid errors with clicking through to queue recheck endpoint directly.
* Add filter "akismet_enable_mshots" to allow disabling screenshot popups on the edit comments admin page.

= 4.1.5 =
*Release Date - 29 April 2020*

* Based on user feedback, we have dropped the in-admin notice explaining the availability of the "privacy notice" option in the AKismet settings screen. The option itself is available, but after displaying the notice for the last 2 years, it is now considered a known fact.
* Updated the "Requires at least" to WP 4.6, based on recommendations from https://wp-info.org/tools/checkplugini18n.php?slug=akismet
* Moved older changelog entries to a separate file to keep the size of this readme reasonable, also based on recommendations from https://wp-info.org/tools/checkplugini18n.php?slug=akismet

= 4.1.4 =
*Release Date - 17 March 2020*

* Only redirect to the Akismet setup screen upon plugin activation if the plugin was activated manually from within the plugin-related screens, to help users with non-standard install workflows, like WP-CLI.
* Update the layout of the initial setup screen to be more readable on small screens.
* If no API key has been entered, don't run code that expects an API key.
* Improve the readability of the comment history entries.
* Don't modify the comment form HTML if no API key has been set.

= 4.1.3 =
*Release Date - 31 October 2019*

* Prevented an attacker from being able to cause a user to unknowingly recheck their Pending comments for spam.
* Improved compatibility with Jetpack 7.7+.
* Updated the plugin activation page to use consistent language and markup.
* Redirecting users to the Akismet connnection/settings screen upon plugin activation, in an effort to make it easier for people to get setup.

= 4.1.2 =
*Release Date - 14 May 2019*

* Fixed a conflict between the Akismet setup banner and other plugin notices.
* Reduced the number of API requests made by the plugin when attempting to verify the API key.
* Include additional data in the pingback pre-check API request to help make the stats more accurate.
* Fixed a bug that was enabling the "Check for Spam" button when no comments were eligible to be checked.
* Improved Akismet's AMP compatibility.

= 4.1.1 =
*Release Date - 31 January 2019*

* Fixed the "Setup Akismet" notice so it resizes responsively.
* Only highlight the "Save Changes" button in the Akismet config when changes have been made.
* The count of comments in your spam queue shown on the dashboard show now always be up-to-date.

= 4.1 =
*Release Date - 12 November 2018*

* Added a WP-CLI method for retrieving stats.
* Hooked into the new "Personal Data Eraser" functionality from WordPress 4.9.6.
* Added functionality to clear outdated alerts from Akismet.com.

For older changelog entries, please see the [additional changelog.txt file](https://plugins.svn.wordpress.org/akismet/trunk/changelog.txt) delivered with the plugin.
