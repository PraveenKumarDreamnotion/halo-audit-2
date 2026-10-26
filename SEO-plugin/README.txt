=== SEO Analyzer ===
Contributors: thrivemattic
Tags: seo, audit, ai, performance
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 2.0.3
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Halo Audit. Scores one page for on-page SEO, technical health, AI readiness and speed.

== Description ==

Enter a page address and a keyword. Halo Audit reads the page and scores it in four areas:

* On-page for the keyword (30%)
* Technical (20%)
* AI readiness (30%)
* Speed (20%)

Schools, colleges and universities also get four admission checks. These are shown but not scored.

The score and grade are free. The full findings and the PDF need a name and a confirmed email.

A check that cannot run shows "Not measured". It is not counted as a failure: the score is worked out from the checks that ran.

The plugin needs no theme changes, no other plugin and no add-on. The form, the report, the email step and the PDF all belong to the plugin.

== Requirements ==

* WordPress 6.2 or later.
* PHP 8.0 or later with the `curl`, `dom`, `mbstring`, `openssl` and `gd` extensions. The plugin shows a notice and stays off when one is missing. Page fetches use cURL only, so that each connection goes to the address that was checked.
* The site must be able to send email (see Email below).
* The site must be able to make outgoing HTTPS requests.

== Installation ==

1. In WordPress go to Plugins > Add New > Upload Plugin. Choose `halo-audit-2.0.1.zip` and press Install Now.
2. Press Activate.
3. Add `[seo_analyzer]` to a page.

The ZIP already holds everything the plugin needs. You do not run Composer on the server.

If the upload is refused because the file is too large (the ZIP is about 7 MB), unzip it and copy the `seo-analyzer` folder to `/wp-content/plugins/` with SFTP, then activate the plugin.

Updating a site that already runs this plugin: replace the files in the folder the plugin is already in. Do not upload the ZIP as a second plugin. If two copies are active, only the first one loads.

Coming from version 1.x: never delete the 1.x copy from the Plugins screen. Deleting it drops the table that holds your reports and leads. Replace its files instead, and export the leads first to be safe.

Database tables: activating the plugin checks its tables (`{prefix}seo_analyzer_results`, `{prefix}seo_analyzer_otp`) against the columns and indexes this version needs and adds whatever is missing. It never drops a table, column, index or row, so the site's own database stays in place: there is no need to copy a database from another site. After replacing the files on a site that ran an older version, back up the database, then deactivate and activate the plugin once. If something could not be added, a notice on the Plugins screen names it and the database's error, nothing is marked as upgraded, and the next activation tries again.

== Settings ==

SEO Analyzer > Settings. Every setting is optional.

* Google PageSpeed API key. Turns on the Speed group. Without a key the speed test is skipped: Speed shows "Not measured" and the score is worked out from the other three groups. The key is free from Google and stays on the server.
* Brevo API key and Sender email. Send the email code through Brevo. Without a key the code is sent with WordPress mail.
* Brevo list ID. When set (with a Brevo key), a visitor who confirms their email is added to this Brevo contact list. The contact's NAME, KEYWORD, URL, COMPETITOR_URL and FORM_NAME attributes are set from the audit (an empty one is left out); no other attribute of an existing contact changes. These five attributes must exist in Brevo (Contacts, Settings, Contact attributes). URL is the audited page; FORM_NAME is the address of the page on which the visitor submitted the form (for example /ai-tools/seo-analysis/), left out when the form is not on a single page. The Reports screen shows each lead's list status. If Brevo uses Authorised IPs, allow the server's IP address there. Empty means no list sync.
* Scan server URL and secret. Not used in this version.

Saved keys are never shown again, not even in the page source. A key field stays empty; "A key is saved" means one is stored. Leave the field blank to keep it, type a new key to replace it, or use "Remove the saved key" to delete it. A new key must be 10 to 512 characters of letters, digits and . _ ~ + / = : - (the characters API keys use). Invisible characters, spaces at the ends and surrounding quotes that come with copy and paste are removed first. After saving, a message confirms the new key was saved, or says why it was refused; a refused key never replaces the saved one.

== Email ==

To unlock the findings a visitor enters a name and an email address and gets a 6-digit code. The code is valid for 15 minutes and works once.

The code is sent through Brevo when a Brevo key is saved. Otherwise it is sent with `wp_mail()`, so whatever sends mail for the site (the host, or an SMTP plugin) sends the code.

If a code cannot be sent, the visitor sees "We could not send the code" and the plugin's admin screens show a notice until a later code goes out. Test this once after you install: run an audit and unlock it with your own address.

== Shortcode ==

`[seo_analyzer]`

* `title="Free SEO audit"` sets the heading. The default is "Halo Audit".
* `title=""` leaves the heading out. Use this when the page already has a heading for the tool.
* `heading_level="3"` sets the heading level, 2 to 5. The default is 2.
* `align="wide"` or `align="full"` adds the standard WordPress alignment class, so a block theme gives the tool its wide or full width.
* `cta_url="https://example.com/contact/"` adds a closing block under the full findings, with a button to that address. `cta_title`, `cta_text` and `cta_button` set its heading, text and button label (the label defaults to "Talk to us"). Only a full http or https address is accepted (not a relative path such as `/contact/`). Without `cta_url` there is no block.

* `comparison="true"` turns on the competitor comparison: the form asks for your page, a competitor page and one keyword. Both pages are audited by the same checks and the report shows the two scores, each area side by side, and how the competitor page did on every finding. `competitor="false"` keeps the comparison off; `competitor="true"` on its own does nothing.

A comparison counts as two audits towards the limit of 10 an hour. If either page cannot be read, no report is made and the message names the page. The old attributes `popup` and `nlp` are accepted and ignored.

The tool fills the width of the place you put it in. In a narrow column it stacks; in a wide one the form sits on one line and the findings use two columns. To give it more room, put it in a wider part of the page.

More than one tool can sit on a page. Each keeps its own report.

== What the visitor sees ==

1. A form: page address and keyword.
2. The score, the grade and the four group scores. No account is needed.
3. "See all findings" or "Download PDF" asks for a name, an email and the 6-digit code.
4. After the code: every finding, with what to fix and why. The PDF holds the same report.
5. The unlock lasts 30 days in that browser.

== Security limits ==

* The tool does not read local or private addresses: `localhost`, `.local`, `.test`, private and reserved IPv4 and IPv6 ranges (including IPv6 forms that carry a private IPv4 address), ports other than 80 and 443. Every redirect is checked again, and the connection goes to the address that was checked (cURL only; without it the audit does not run).
* Each visitor can run 10 audits an hour. One email address gets 3 codes an hour. Each code allows 5 tries, and one visitor can try codes 30 times an hour.
* A site that answers with anything but a page (an error such as 403, a redirect with no address, or an empty answer) gets no score. The tool says why it could not read the page.
* If a side file (sitemap, llms.txt, feed) is refused, rate-limited or fails, the checks that need it show "Not measured". A robots.txt that is rate-limited or fails does the same; one refused with 401 or 403 counts as "no rules", as the robots.txt standard (RFC 9309) says.
* The findings and the PDF are checked on the server. They are never sent to a browser that has not confirmed an email.
* Reports that were never unlocked are deleted after 7 days.

== Themes ==

The plugin styles only its own markup, under `.halo-audit`, and needs no theme files and no other plugin. Version 2.0.1 was tested from its ZIP on a WordPress site with no other active plugin, on Twenty Twenty-Five (block theme) and Twenty Twenty-One (classic theme): form, score, email step, findings and PDF, at desktop and phone widths. Earlier 2.0.0 builds were also run on GeneratePress. Other themes and page builders have not been tested.

The colours suit a light background. To change them, set the `--halo-*` variables on `.halo-audit` in your theme's CSS, for example:

`.halo-audit { --halo-accent: #1a56db; --halo-radius: 4px; }`

Variables: `--halo-ink`, `--halo-muted`, `--halo-line`, `--halo-soft`, `--halo-field`, `--halo-surface`, `--halo-pad`, `--halo-radius`, `--halo-accent`, `--halo-accent-ink`, `--halo-button`, `--halo-button-hover`, `--halo-display`, `--halo-body`, `--halo-pass`, `--halo-warn`, `--halo-fail`.

`--halo-accent` colours the bars, rules and focus rings. The filled buttons use `--halo-button` and `--halo-button-hover` (a darker olive by default, so their white `--halo-accent-ink` text keeps a 4.5:1 contrast); set those too when you change the accent. `--halo-display` (scores and headings) defaults to Oswald and `--halo-body` (small labels) to Lato, both loaded from the plugin's own `assets/fonts/`; the rest of the text uses the theme's font.

A theme can follow the tool's state. The root element carries `data-state` (`idle`, `invalid`, `loading`, `error`, `preview`, `gate`, `code`, `unlocked`), and a `halo-audit:state` event bubbles from it on each change.

== Troubleshooting ==

* "Run composer install" notice: the plugin was copied from the source repository, not from the ZIP. Install the ZIP.
* Speed always says "Not measured": add a PageSpeed key, or check that the key is valid.
* "We could not send the code": the site cannot send email. Check the Brevo key and sender, or the site's mail setup.
* "Your session has expired": a firewall or cache is blocking `/wp-json/halo-audit/v1/`. Do not cache that path.
* "The audit ran but its report could not be saved", or the speed test says "This report was not found": the plugin's tables are incomplete (for example still the 1.x results table). Back up the database, then deactivate and activate the plugin. To check by hand: `SHOW CREATE TABLE {prefix}seo_analyzer_results;` must list `report_key` with a UNIQUE key, and `grade`, `engine_version`, `is_education`, `lead_verified_at`, `scan_id`.
* The tool looks unstyled after an update: clear the cache of your optimisation plugin, including its minified CSS and JavaScript.
* With "Remove Unused CSS" in an optimisation plugin, add `halo-audit` to its safelist. Most of the report is drawn in the browser after the page loads.

== Uninstall ==

Deactivating keeps all data. Deleting the plugin from the Plugins screen removes its tables (reports and leads), its settings and its scheduled clean-up. Export the leads first: SEO Analyzer > Reports > Export leads as CSV.

== PDF fonts and languages ==

The PDF text is set in Lato and its headings and score numbers in Oswald, both from the plugin's own `assets/fonts/` under the SIL Open Font License 1.1 (`assets/fonts/OFL-Lato.txt`, `assets/fonts/OFL-Oswald.txt`). The same files give the on-page results their Oswald and Lato. Lato and Oswald cover Western European text; any character they do not have (the status signs, Cyrillic, Greek, Vietnamese, Arabic and others) is set in DejaVu Sans Condensed, and the status signs in the chips are set in DejaVu. A copy of the plugin without `assets/fonts/` sets the whole PDF in DejaVu, as before.

DejaVu Sans is under the Bitstream Vera licence with DejaVu changes in the public domain. Text in Devanagari (Hindi, Marathi, Nepali, Sanskrit), such as a Hindi keyword or address, is set in FreeSans from GNU FreeFont, version 0412.2268, copyright 2002-2012 GNU FreeFont contributors, under the GNU General Public License version 3 or later with the FreeFont font exception: a PDF that embeds the font is not itself covered by the GPL (full terms: `licenses/FreeFont-GPL-3.0-with-font-exception.txt`). Both fonts come with mPDF in `vendor/mpdf/mpdf/ttfonts/`; the ZIP keeps only these files (FreeSans adds about 1.5 MB uncompressed). Nothing is downloaded when a PDF is made.

The PDF downloads as `halo-audit-<site>-<YYYY-MM-DD>.pdf`, or `halo-audit-<site>-vs-<competitor site>-<YYYY-MM-DD>.pdf` for a comparison. The site is the audited host without `www.`, in plain ASCII; the date is the report date (UTC). When no safe name can be made, the file is `halo-audit.pdf`.

Hindi in a bold heading or check name is set in regular weight: mPDF 8.3 misplaces the anusvara in FreeSans Bold. Hindi copied or searched in the PDF comes out in display order (a vowel sign before its letter), a known mPDF limit for shaped scripts; it reads correctly on the page. Other scripts with no bundled font (Chinese, Japanese, Korean, Tamil, Telugu, Bengali, Gujarati and others) print as empty boxes.

== Upgrade Notice ==

= 2.0.3 =
Fixes "This report was not found" on the speed test when the plugin's tables were never fully upgraded. After replacing the files, back up the database, then deactivate and activate the plugin once: activation checks and repairs its tables and keeps every existing report. Clear your optimisation plugin's minified JavaScript after updating.

= 2.0.2 =
The PDF has a new look (Lato and Oswald, a charcoal cover) and downloads with the site and date in its name. Every PDF carries the Thrivemattic logo, author, footer credit and the closing call to action ("Book a Discovery Call", https://thrivemattic.com/contact/); the `halo_audit_pdf_brand` filter can change or switch them off. The competitor comparison is back (`comparison="true"`). Clear your optimisation plugin's minified CSS and JavaScript after updating.

= 2.0.1 =
Scoring changes (pages that are not a 2xx web page get no score; refused or failing side files show "Not measured"). Needs the PHP cURL extension. Security fixes for the address check and the email code.

= 2.0.0 =
Needs PHP 8.0 and WordPress 6.2. The OpenAI and NLP add-ons are no longer used: deactivate and delete them. The competitor comparison was removed in 2.0.0 and is back in the next release (`comparison="true"`). The old OpenAI key setting is not read any more.

== Developer notes ==

* Filter `halo_audit_client_ip`: return the true visitor IP when the site is behind a proxy.
* Filter `halo_audit_speed_enabled`: return true or false to force the speed test on or off.
* Filter `halo_audit_curl_usable`: whether page fetches may run (default: the cURL transport with SSL is usable). Returning false stops every audit; it exists for tests.
* Filter `halo_audit_user_agent`: the user agent used to read pages. The default names this site.
* Filter `halo_audit_pdf_footer`: the credit line in the footer of every PDF page, beside the page number. The default is "Report generated by thrivemattic.com with Halo Audit" on every site (no address of the site itself, so a staging site does not print its internal URL). Arguments: the default text and the report. Return your own text, or an empty string to leave it out.
* Filter `halo_audit_pdf_brand`: the branding of the PDFs. The default (`Pdf::DEFAULT_BRAND`) is Thrivemattic's: the bundled logo, the author "Thrivemattic" and the approved closing call to action with a "Book a Discovery Call" button to https://thrivemattic.com/contact/. Return `false` for a neutral PDF (the "HALO AUDIT" text mark, no logo, no call to action, no author), or your own array: `['logo' => true]` to show the plugin's bundled logo on the cover and in the running header, `'cta' => ['eyebrow' => …, 'title' => …, 'text' => …, 'button' => …, 'url' => 'https://…']` to end the PDF with a call to action, and `'author' => …` for the PDF's author field. Only these values are read: the logo is always one of the plugin's own files (a path, URL or image from the filter is ignored), texts are plain and shortened, and the address must be a full http or https URL or the button has no link. Arguments: the default brand array and the report.
* PDF metadata: the title is "Halo Audit: SEO audit report" or "Halo Audit: SEO comparison report"; the author is "Thrivemattic" unless `halo_audit_pdf_brand` changes it (empty when the filter returns `false`).
* Speed retry: when a page's PageSpeed test ran and gave no result, the results show "Try the speed test again". It posts to `halo-audit/v1/speed` with `report_id`, `side` (`mine` or `competitor`) and `retry: true`, under the same nonce and rate limit (20 speed tests an hour per IP). Only that page is measured again, only when its stored result is "no result" and a PageSpeed key is set; otherwise the stored result is returned. One test per page runs at a time: a second call while one runs gets 409.
* Filter `halo_audit_send_code`: return true or false to send the code yourself. Arguments: null, the address, the code.
* Filter `halo_audit_scan_client`: return a `HaloAudit\ScanClient` to enable the live AI visibility scan.
* Tests: `composer test` (unit). Integration tests need Docker: `npx wp-env start`, then
  `npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin php vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml`.
* Release ZIP: from the repository root,
  `docker run --rm -v "$PWD:/repo" -w /repo/SEO-plugin composer:2 sh bin/build-zip.sh /repo/dist`.
  Add `WORKTREE` after the output folder to package uncommitted tracked files for a review candidate. Release ZIPs come from a commit.
  It builds from the last commit, installs the production Composer packages and writes `dist/halo-audit-<version>.zip`.
