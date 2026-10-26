# Changelog

All notable changes to the SEO Analyzer plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.0.3

26 Oct 2026. The speed test's "This report was not found" (404) on a site whose tables were never fully upgraded: reports are saved before an ID is given out, activation checks and repairs the tables, and the browser stops retrying a report that does not exist. Back up the database, replace the files, then deactivate and activate the plugin once.

### Fixed (speed test answering 404, 26 Oct 2026)
- An audit whose report the database did not save still answered with a `report_id`, so the page showed the free results while every later call for that report (the speed test, its retry, the email gate, the full report, the PDF) answered 404 "This report was not found". `ReportStore::create()` now returns no key when the row was not written (an insert the database refused, or a report that cannot be encoded as JSON), and `POST /analyze` then answers 500 `halo_store` ("The audit ran but its report could not be saved. Try again later.") instead of results that can never be unlocked or speed-tested. One way to get there: a database copied in parts that holds the 1.x results table (no `report_key` column) while `halo_audit_db_version` already says 2, so the upgrade never runs and every insert fails with "Unknown column 'report_key'". The fix makes that visible at once; the repair is the activation step below.

### Fixed (database tables checked and repaired on activation, 26 Oct 2026)
- Root cause of the speed 404 on an upgraded site: its results table was still the 1.x table (no `report_key`, `grade`, `engine_version`, `is_education`, `lead_verified_at`, `scan_id`) while `halo_audit_db_version` said 2. The upgrade only ran when that option differed from 2, and it wrote 2 whether or not its `dbDelta` changes had worked; the option can also reach a site in a partial database copy. So the tables were never checked again and every report insert failed.
- `Installer` now checks the real tables, not the option alone. Plugin activation (`register_activation_hook`) always checks and repairs: it creates a missing table, adds missing columns and indexes (after looking for an equivalent index on the same columns), makes `name`/`email` nullable and widens a 1.x `keyword varchar(100)` to 255, keeping each column's collation. Nothing is dropped, truncated or rewritten; row IDs and `AUTO_INCREMENT` stay. Managed: `{prefix}seo_analyzer_results` (17 columns; primary key, unique `report_key`, index `created_at`) and `{prefix}seo_analyzer_otp` (5 columns; primary key, index `email`).
- `halo_audit_db_version` is written only after a check finds nothing blocking missing. The result of the last install or check is kept in option `halo_audit_schema` (problems, warnings, each failed statement with the database's error). While it is not complete an admin notice on the Plugins screen and the plugin's screens lists it and says what to do. A failed install leaves every row as it was and is retried by the next activation (or, on a site without the version, at most once an hour by a request).
- A site whose option already says 2 is never altered by a visitor's request: an admin request checks it read-only (at most hourly) and, if something is missing, asks for a backup and a reactivation. A report that cannot be saved also makes the next admin request check again.
- The 1.x code table (`{prefix}seo_analyzer_otp` with plain-text `otp`) is renamed to `{prefix}seo_analyzer_otp_legacy` instead of being dropped, and 2.x creates its own. It still holds old email addresses and codes; remove it by hand once reviewed (uninstall removes it). It used to be dropped on every upgrade.
- The 1.x `{prefix}seo_analyzer_api_keys` table is dropped only when it holds no key or the same key as the settings (an empty setting takes its key first); a different key stays there, with a warning. A 1.x code table that cannot be renamed is left exactly as it is and reported, never half converted.
- One install runs at a time (a lock row in the options table, freed after 5 minutes if a request dies).
- A 404 from `POST /speed` is final in the browser: a retry would get the same answer. Speed shows "No result" without "Try the speed test again", the status line shows the server's message ("This report was not found. Run the audit again.", or "The speed test is not available on this site right now." when the site does not answer the route), the competitor page of a comparison is not sent a second doomed call and does not stay on "Testing…", and the full report is not fetched again. A timeout or any other error still offers the retry. Errors from the REST routes now keep their `code` in the browser.

### Fixed (Speed score disappearing, 26 Oct 2026)
- For a visitor who had already confirmed their email, Speed (mobile) showed its score for a moment and then went back to "Not measured" / "No result". When the speed test ended, the browser loaded the full report again. That request had no cache headers and no unique address, so a page or edge cache could answer with the copy saved before the test, and the browser then took that copy's empty Speed score. Now `GET /report/{id}` sends `Cache-Control: no-store, max-age=0`, and the browser asks for each full report at its own address (`?t=`). This also stops a cache from keeping one visitor's unlocked report.
- A page's speed result in the browser only moves forward: still running, then ran without a result, then measured. A full report that knows less about a page's speed keeps that page's score, speed state and speed rows, and every other finding still comes from the report. This holds for each page of a comparison. It also fixes two other cases: a successful "Try the speed test again" undone by an older "failed" copy, and a comparison where only the competitor's copy was older. A stored result still wins over a call that timed out in the browser. Scores, checks and the report format are unchanged.

### Changed
- `Analyzer::VERSION` is 2.0.3, so new reports carry `engine_version` 2.0.3 (in the stored report and the results table). 2.0.2 left it at 2.0.1. Reports already saved keep the version they were made with.

## 2.0.2

24 Oct 2026, merged into halo-audit-2 as pull request #4. It holds every change since 2.0.1.

### Fixed (PDF spacing, 23 Oct 2026, night)
- Status badges (✓ Pass, ! Improve, ✗ Fix, ○ Not measured, ✓ Analysis complete) and the grade badge now have room around the text: mPDF ignores inline padding and padding on a block inside a table cell, so the room is a border in each badge's own tint. In a comparison each result line is its own block, because mPDF dropped the left side of a badge that came right after the "Your page"/"Competitor" label.
- More space under the area names on the score cards (a spacer line), under "How to read the results", between the CTA heading and its text, and above the report-type label under the cover logo. Fonts, colours and wording unchanged.

### Changed (PDF branding built in, 23 Oct 2026, night)
- Every PDF now carries the Thrivemattic brand by default, on any site: the logo on the cover and in the running header, the author "Thrivemattic", the footer credit "Report generated by thrivemattic.com with Halo Audit" and the approved closing call to action with its "Book a Discovery Call" button to https://thrivemattic.com/contact/ (`Pdf::DEFAULT_BRAND`). Was: neutral unless a site plugin switched the brand on through `halo_audit_pdf_brand`, so PDFs made on staging or any other host had no logo or CTA (owner decision). The filter now receives the default brand and can still change it, or return `false` for a neutral PDF. The site plugin `tm-halo-audit-branding.php` keeps working unchanged and is no longer needed.

### Fixed (PDF cover title, 23 Oct 2026, evening)
- The cover title's descenders (g, p, y, q) were cut off along a straight line, on every cover. mPDF repaints the cover band's background for each block after it writes that block's text, and Oswald's descenders reach 0.289 em below the baseline, past the title's line box, so the block under the title was painted over them. The title is now the first row of the cover's details table, which mPDF draws after the band, with room under the last line before the first divider (3.4 pt or more at every title size). Line height 1.15 (was 1.1), so wrapped titles read more easily. Font, sizes and colours are unchanged.

### Fixed (PDF closing block, 23 Oct 2026, later)
- The closing CTA no longer stands alone on the last page. When no CTA layout fits the space left after the findings, the PDF is drawn once more with the last area's last three items (passed rows first, then finding cards) moved to the next page under the area title marked "(continued)" (for example "AI readiness (continued)") and the column heads, followed by the standard CTA. A passed list never leaves a single row behind; every check is still printed once, in order. Copy, link and page count are unchanged in the reports tested.
- The ZIP build stops when a bundled logo or brand font is missing from the export, instead of shipping a PDF that quietly falls back to the text mark and DejaVu.

### Changed (QA corrections, 23 Oct 2026)
- PDF title metadata: "Halo Audit: SEO audit report" or "Halo Audit: SEO comparison report" (was without the colon). The author still comes only from the `halo_audit_pdf_brand` filter.
- PDF area cards show every count as plain text, zeros included, as in the brand reference ("2 to fix · 2 to improve · 8 passed", plus "N not measured" when there is one). The web cards still leave out zero counts.

### Changed (brand reference parity, 23 Oct 2026)
- Cover: the eyebrow names the report type and the title names the keyword, as in the reference ("How your page compares for “acca course”", "How your page scores for “…”"; without a keyword, the report type). Labels "Your page / Competitor / Keyword / Date"; the status row stays (owner decision D4). The line under the title, which repeated it, is gone.
- One name per area throughout the PDF: the cards, the section headings and the priority list all use "On-page for your keyword", "Technical", "AI readiness" and "Speed" (were "On-page SEO", "Technical SEO", "Speed (Halo Audit)" on the cards and "Speed and performance" as a heading).
- Findings are plain rows with a hairline under each, as in the reference; the coloured status stripe is gone (the badge, with its sign, carries the status). The "What to do" row is unchanged.
- Priority fixes have the reference's column heads (# / Status / Check / What to do). When a fix does not fit under the area cards, it continues on the next page under "Priority fixes (continued)" and the column heads; the heading always keeps at least its first fix (the smallest block that never leaves it alone).
- "Ahead" figures use the Pass status colour: olive is never text (the cover and CTA eyebrows on charcoal are the brand kit's own exception).

### Fixed (brand reference parity, 23 Oct 2026)
- The grade badge no longer touches "out of 100", and the CTA's eyebrow, title and text have the reference spacing (spacer lines: mPDF ignores margins inside a table cell).
- Long addresses wrap after "/", "?", "&" or "=" (else "." or "-"), never inside a word, and a long host in the score panel wraps after a dot or hyphen. The line ends are measured with the PDF's own fonts and written as line breaks only where a line is full, so no invisible characters go into the text: an address copied from the PDF, with its lines joined, is the address (checked on the rendered PDFs). A part with no break point that is wider than its column still wraps where it must. Links, file names and metadata are unchanged.
- Priority fixes: the heading always stays with the first fix; the others follow under it or, when the page is full, on the next page under "Priority fixes (continued)".
- Web area cards: "Every check passed" when every check in an area passed (was "7 pass").
- The browser keeps the server's file name whether or not it is quoted, and ignores any other `filename` form.
- Comparison findings now follow the single-page layout: "Fix these first" for your page (same ranking; each item also gives the competitor's result; a check that fails only on the competitor page is not listed), checks needing a look first in each area, checks that passed on both pages folded under "N checks passed on both pages", and your page's advice in the "What to do:" row. Scores, statuses and evidence are unchanged.

### Fixed (QA corrections, 23 Oct 2026)
- A speed test that gave no result no longer reads as finished: the PDF status says "Page audit complete, speed test failed" and which page, the web page says "Page audit complete. The speed test did not return a result, so Speed is not measured." and shows "No result" instead of "Not measured". The free and full views carry `speed_failed` per page, read from the stored rows (reports saved earlier included).
- "Try the speed test again" (web): measures that one page again through `/speed` with `retry: true`. Only a page whose stored result is "no result" is measured again; a call while a test for the page runs gets 409 (a lock option per report and page, released after the test, taken over after 3 minutes); the rate limit and nonce are unchanged; the page audit is not run again. Uninstall removes leftover lock options.

### Changed (PDF brand kit, 22 Oct 2026)
- The PDF uses the brand kit (`Wp/BrandPdf.php`): Lato for text and Oswald for headings and score numbers, from the plugin's `assets/fonts/`; charcoal ink, cream panels, and olive only for rules, bars and rings, never for text. Secondary text is `#666b68` (5.43:1 on white, 5.08:1 on cream). Status chips use the brand's status colours and keep their sign (✓ Pass, ! Improve, ✗ Fix, ○ Not measured), so they still read on a grayscale print.
- Page 1 opens on a charcoal cover band (report type, pages, keyword, date, status). The metric cards and area cards are cream with an olive top rule; the competitor's bars are a lighter grey. Every piece of content of the previous layout is kept; the eight QA test reports have the same page counts as before.
- "What to do" in each finding is a cream row with an olive rule on the left and a charcoal label.
- Characters Lato and Oswald do not have (the status signs, arrows, Cyrillic, Greek, Vietnamese, Arabic and others) are set in DejaVu Sans Condensed, so every character the earlier PDF printed still prints; Devanagari keeps FreeSans. mPDF's own font substitution is not used: it left signs and Cyrillic as boxes and fell back to a non-embedded core font.
- The PDF downloads as `halo-audit-<site>-<YYYY-MM-DD>.pdf`, or `halo-audit-<site>-vs-<competitor site>-<YYYY-MM-DD>.pdf` for a comparison (was always `halo-audit.pdf`). The host loses `www.`, is converted to ASCII, keeps only letters, digits, dots and hyphens and is cut to 60 characters; the date is the report date (UTC). The browser now keeps the name the server sends, and falls back to `halo-audit.pdf`.
- The browser's "Fix these first" uses the PDF's order (Fix before Improve, then the check's severity, then the area's weight), so the web page and the PDF list the same five checks. Checks without advice text and education checks are no longer left out of it.
- The comparison view shows the keyword above the two pages.
- Evidence counts have a thousands separator: "2,702 words", "1,500 internal links", "1,199 of 1,200 images have an alt attribute". Reports saved earlier keep their wording.
- "Making the PDF once the speed test has finished…" uses an ellipsis, like the rest of the tool.

### Added (PDF brand kit, 22 Oct 2026)
- Filter `halo_audit_pdf_brand`. Neutral by default: the "HALO AUDIT" text mark, no logo, no closing call to action. A site's own plugin can turn on the bundled logo (cover and running header) and a charcoal closing call to action, and set the PDF author. Only a fixed set of plain values is read; a path, URL or image from the filter is never used. See README.txt, Developer notes.

### Fixed (22 Oct 2026)
- `cta_url` accepts only a full http or https address. A relative path such as `/contact/` was kept in the markup (the script already refused to show it).
- The closing block (`cta_*`) keeps its colours on themes that colour every paragraph and link with `!important`. On Thrivemattic's theme its text had become `#333` on charcoal (1.23:1) and its button label olive on olive (1.30:1); both now pass WCAG AA again (8.71:1 and 5.47:1). Only these three colours use `!important`.

### Changed (results design review, 22 Oct 2026)
- Area cards: the name on its own line, the score in large figures with a score bar, and only the counts that are not zero ("5 to fix · 1 to improve · 6 pass").
- Speed while the test runs: "Testing…" and "Takes up to 90 seconds", with no counts. The status line says "Page read. The speed test is still running." until it ends, then "Audit complete."
- The Speed area is named "Speed (mobile)". The keyword is shown beside the page address.
- Findings: "Fix these first" lists the top 5 Fix and Improve results, ranked by severity and area weight. In each area the checks needing work come first (Fix, Improve, Not measured), each with a "What to do" row; passed checks are folded under "N checks passed". Single column at every width.
- Status badges, colours and type follow the PDF: dark text on a light tint, olive accent `#808000`, charcoal ink, Oswald (`--halo-display`) for scores and headings. "Download PDF" is a charcoal outline button.
- New shortcode attributes `cta_url`, `cta_title`, `cta_text` and `cta_button` add an optional closing block under the findings. Without `cta_url` there is no block.

### Fixed (results design follow-up)
- Filled buttons use `--halo-button` (`#6d6d00`, hover `#5c5c00`): white text on `#808000` was 4.20:1, below WCAG AA for 16px text; now 5.47:1. `#808000` stays the accent for bars, rules and focus rings.
- Oswald and Lato load from the plugin's `assets/fonts/` (the PDF's font files) as "Halo Oswald" and "Halo Lato", so the scores and headings no longer depend on the theme having those fonts, and a theme's own Oswald or Lato is left alone.
- The Speed line in the findings and the comparison table says "Testing…", as the area card does (was "Testing speed...").

### Added
- Competitor comparison is back, built on the 2.x engine. With `comparison="true"` on the shortcode (as in 1.x) the form asks for your page, a competitor page and a keyword. Both pages are audited by the same checks and scoring; the report shows both overall scores, a side-by-side table of the four areas with the difference, and the competitor's result next to each of your findings. The PDF carries the same comparison. `competitor="false"` keeps it off; `competitor="true"` alone does nothing (as in 1.x).
- The competitor's report is stored in the `competitor_url`, `competitor_results` and `competitor_overall_score` columns that the table already had. No schema change.
- The Reports screen shows the competitor page and its score for a comparison, and the leads CSV has two new columns at the end, "Competitor page" and "Competitor score" (the earlier columns keep their places).
- Brevo list sync. With a Brevo API key and the new "Brevo list ID" setting, a visitor who confirms their email with the code is added to that Brevo contact list. A new contact is created in the list; an existing contact is added to it. Both get the attributes NAME (from the code form), KEYWORD, URL and COMPETITOR_URL (as typed in the audit form) and FORM_NAME (the address of the page the form was submitted on), read from the stored report, never from the request; an attribute with no value is left out, so it never blanks a value in Brevo, and no other attribute changes. Success is recorded only when Brevo's own record shows the contact in the list. The sync runs after the unlock answer is sent and never blocks the report; timeouts, rate limits and Brevo server errors are retried by WP-Cron (4 tries). The Reports screen has a "Brevo list" column and the plugin's screens show a notice after a failed sync, with the reason (invalid key, IP not in Brevo's Authorised IPs, unknown list, and so on). No API key, email or Brevo response is stored or logged. Empty list ID means no sync. (1.x added contacts to list 14 from the OpenAI add-on; 2.0.0 removed the add-on and nothing replaced it.) Each attribute is read back from Brevo after the write; one Brevo did not keep (for example an attribute the account has not defined), or a FORM_NAME that could not be sent because the form page was unknown, is named in the Reports screen's Brevo column and in an admin notice, instead of counting as a complete sync.

### Fixed
- Comparison findings ("See all findings" and the PDF) show both pages for every check: your page and the competitor page each in their own labelled column, with that page's status and evidence. Checks are matched by ID on the server. A check that ran on only one page shows "Not checked" on the other side; before, the competitor's evidence was not shown at all and checks that ran only on the competitor's page were left out. Each area also shows both pages' scores.
- Comparison speed: the competitor page is always tested. Before, the browser asked for the competitor's PageSpeed test only after your page's test answered; when that first request failed (a gateway timeout, a rate limit or a dropped connection) the competitor was never tested and both pages showed "Not measured". Now `/speed` takes `side=mine` or `side=competitor`, the browser sends one request per page, and a failure marks only that page "Not measured". A request without `side` (a page cached with the older script) still measures the first page that has no result.
- Each page's speed result is saved to that page's own columns only, so two overlapping speed requests for one report can no longer put back an older copy of the other page.
- A speed check that has not run yet says "The speed test had not finished yet", and with no PageSpeed key "Speed is not tested on this site", instead of "Google PageSpeed did not return a result". The status is still Not measured, so no score changes. A PDF made while a test is running says which page's test had not finished, and the Download PDF button now waits for a running speed test before asking for the PDF.
- A PageSpeed answer that is not a Lighthouse result is "Not measured": a Performance score outside 0 to 1 (an 82 sent instead of 0.82 became 8200), or a negative or infinite LCP or CLS (an infinite value could not be stored).
- PDF: a section title is never left alone at the foot of a page. mPDF keeps a heading with its table only when the heading holds no other tag, and the score beside each title was one; the title is now the first row of its table head, so it also repeats on each page the table runs onto.
- PDF: "fi" and "fl" are no longer drawn as one ligature character, so an address or word copied or searched in the PDF matches the page (it was copied as U+FB01).
- Comparison speed results no longer go stale when answers arrive out of order: a full report fetched before a speed test finished is fetched again instead of replacing newer scores, and a speed test refused by the rate limit shows "Not measured" in the findings as well as in the summary.

### Changed
- The PDF is always downloaded as `halo-audit.pdf` (`Content-Disposition` header; was `halo-audit-<host>.pdf`, while the browser already saved it as `halo-audit.pdf`).
- New PDF layout: a cover with the page(s), keyword, date, overall score and grade (both pages side by side in a comparison) and a key to the results; a summary of the four areas with pass, improve, fix and not measured counts; findings per area with the check, why it matters, what was found and what to do. Results carry a sign as well as a word and a colour (✓ Pass, ! Improve, ✗ Fix, ○ Not measured), so they read on a grayscale print. Table headings repeat on each page, a heading stays with its table, long addresses wrap instead of shrinking the table, and every page has a footer with the page number. Still built only from the stored report, every value escaped, nothing fetched.
- The PDF has a "Top fixes" list (at most 5: Fix before Improve, then the checks that count most in their area, then the areas that count most overall; your page only in a comparison) and a "Google PageSpeed results" table with a column per page. It shows Google's Lighthouse Performance score, LCP and CLS, labelled as Google's mobile lab test (not field data from real visitors), next to the Halo Audit Speed score, labelled as Halo Audit's own with its weights. Area captions say "Halo Audit score". The scoring itself is unchanged.
- On the web page the Speed area reads "Speed (Halo Audit score)", with a note under the scores and in the Speed findings: it is Halo Audit's own score from three checks on Google's mobile lab test, weighted 3, 2 and 2, not Google's Performance score. The check "Mobile speed score" is now "Google Performance score (mobile)" and says "82 out of 100 (Google Lighthouse, mobile lab test)". Reports saved earlier keep their old wording.
- PDF design pass. The first page shows the page(s) audited in a shaded panel, balanced score cards, an "At a glance" box read from the stored scores (strongest and weakest area, or where your page is ahead of and behind the competitor, and the check counts), a one-row key to the four results, the area summary and the Top fixes. One heading style for every section, a fixed type scale, more padding in table cells (8 pt in findings), hairline row separators, fixed column widths in mm, and margins of 16 mm at the sides, 14 mm at the top and 20 mm at the bottom. The PageSpeed table says the unit of each measure. When the first page has two long addresses or extra notes, Top fixes moves whole to page 2 instead of splitting.
- PDF: long addresses wrap inside their column because every table is set to mPDF's `overflow:wrap`. The invisible zero-width spaces that used to make them wrap are gone, so an address copied from the PDF is the address.
- PDF columns: a narrower result column and a wider check column; the summary headings fit on one line; the short tables move to the next page whole instead of splitting.
- PDF redesign (still mPDF 8.3, no new dependency). Page 1 is an executive summary: report title, the pages, keyword and report date (the date once), both score cards with grades, a "What the results mean" box read only from the stored scores and counts (overall result, where your page is ahead or behind, how many checks need work) with the grade scale, a "Scores by area" table with a bar per page, and "Priority fixes" (was "Top fixes"; same order and limit). The details start on page 2 with the key to the results, then "Speed and performance": Google's three measures and Halo Audit's Speed score in one table, with what to do. The Speed area is no longer printed twice (it was a PageSpeed table and again as Speed findings). Then On-page, Technical and AI readiness. Each check's "What to do" is a shaded row under that check's results, kept on the same page as the check. Pages after the first have a running header (report type, host, date). Body text is DejaVu Sans Condensed (bundled with mPDF).
- PDF: the `halo_audit_pdf_footer` credit is printed in the footer of every page beside "Page N of M", instead of as a paragraph after the last table, which could leave a last page holding only that line. An empty string still leaves it out. A long credit or a long host name makes the footer or header taller and the page body moves to make room (mPDF `setAutoTopMargin`/`setAutoBottomMargin` = `stretch`), so they never overlap the report.
- PDF: Hindi and other Devanagari text (a keyword, an address, evidence or advice) is drawn in FreeSans from GNU FreeFont (bundled with mPDF; the ZIP now keeps `FreeSans.ttf`, about 1.5 MB) instead of empty boxes, with conjuncts, vowel signs and the danda shaped by mPDF. Devanagari runs are tagged `lang="hi"` and a small map (`Wp/PdfFonts.php`) sends only that language to FreeSans; every other text stays in DejaVu Sans, so Latin-only reports are unchanged. Limits: Hindi in bold text is set in regular weight (mPDF misplaces the anusvara in FreeSans Bold); copied Hindi text comes out in display order; scripts with no bundled font (Chinese, Tamil, Bengali and others) still print as boxes. Without `FreeSans.ttf` (an older trimmed copy) the PDF still renders, with boxes for Devanagari.
- PDF pagination: "Priority fixes" may now split between rows (its column heads repeat) instead of moving whole to page 2, which left about a third of page 1 empty when the summary ran long (both speed notes, or very long advice). A comparison section measured on neither page says "not measured on either page" once, in its column heads.
- The PDF route answers 500 "The PDF could not be made. Try again." when the renderer fails, instead of a broken download, and anything printed while the PDF is drawn (a PHP notice with `display_errors` on) is discarded so it can never sit in front of `%PDF-`.
- A comparison counts as two audits in the hourly limit, and as two speed tests when a PageSpeed key is saved.
- If either page of a comparison cannot be read, or both addresses lead to one page, nothing is stored and the message names the page. 1.x showed the report with the competitor marked "Unavailable".
- PDF second design pass (still mPDF 8.3.1; scoring, checks and priority order unchanged). One type scale with nothing below 7.5 pt (title 24, section 14, check names 9.5, body 9, notes 8, labels 7.5; score numerals 36) and 18 mm side margins. Each result shows its status and what was found on one line. Each area lists the checks that need a look first (your Fix, then Improve, then not measured, then checks only the competitor failed) with why and what to do, then the passed checks in a compact list without the explanation; every check is still printed once with its status and evidence. Section titles are headings kept with their table: pages a table runs onto repeat only its column heads, which carry each page's score in a comparison, instead of the full title with a large gap. Speed: each measure says once that it is Google's and its unit, the Lighthouse value reads "82 / 100" (the stored text is unchanged), one note on lab data and the Halo Audit weights, and page 1 labels the area "Speed (Halo Audit)". "What the results mean" names areas in plain words with one sentence each for ahead, behind and level ("It is ahead in on-page SEO, technical SEO and speed. It is behind in AI readiness."). Score cards show each page's host. A 4-6 page comparison is now one page shorter.
- The default PDF credit no longer prints the site address: "Report generated by <Site Title> with Halo Audit." (the host name when the site has no title). A staging or local site printed its internal URL to customers. `halo_audit_pdf_footer` still changes or removes it.
- Check wording (status and scoring unchanged): "1 redirect" / "2 redirects", "1 day left" / "60 days left", "1 skipped level", "116 internal links", "1 valid block", "1 of 2 blocks cannot be read", "last update 1 day ago" instead of "(s)". FAQ schema on a page with no FAQ content still passes, and now says why: "No FAQ content on this page, so no FAQ schema is needed". Reports saved earlier keep their wording.
- `bin/build-zip.sh` stops with a message when `includes/engine/Wp/PdfFonts.php` or the FreeFont licence is not in the export (a file git does not track is left out), instead of building a ZIP whose PDF download fails.

- PDF redesigned as a dashboard (still mPDF 8.3.1; scores, statuses, checks and the priority order are unchanged). Page 1:
  - a rounded hero card with the report type, the pages, keyword, report date and status ("Analysis complete", or which page's speed test had not finished);
  - a score panel with a score ring per page, its grade and, in a comparison, the difference and "What the results mean";
  - four metric cards (to fix, to improve, passed, not measured), with the competitor's counts in a comparison;
  - one card per area, two to a row: the area name with its counts in words ("✗ 4 to fix", "! 2 to improve", "✓ 6 passed") and its Halo Audit score beside them, a full-width bar (the competitor's thinner and grey, with the difference) and the top issue in a tinted foot; a page break never splits a card. The "Scores by area" heading and the first row of cards form one block that is never split, instead of a keep-with-table heading (mPDF counted such a heading twice when fitting a kept table, so a long hero pushed the whole section, and every page after it, down a page);
  - the priority fixes (on page 2 when page 1 is full).

  Layout polish:
  - "Halo Audit" in every page's footer links to https://thrivemattic.com/ai-tools/seo-analysis/. Only those words are linked.
  - The score panel's difference and meaning columns, and the text beside each score ring, now have the padding they were meant to have (a CSS rule had overridden it).
  - In the area cards, the bars and the competitor line are separate rows with even gaps.
  - The status legend stands clear of the priority fixes.
  - The passed-check lists line up with the finding cards' "Your page" and "Competitor" columns.
  - Each section title and its first finding card form one block that is never split, as "Scores by area" already does: mPDF had counted a kept heading twice and moved both to the next page early.
  - The legend and the Halo Audit Speed score row can no longer be shrunk to fit the foot of a page.

  The findings are cards: a stripe in your page's status colour, the check and why it matters, each page's labelled result and the "What to do" band. Each card is kept on one page. Passed checks stay a compact list. Speed shows Halo Audit's Speed score beside its title, one card per Google measure and the Halo Audit Speed score row. The score rings and bars are SVG drawn from the scores and embedded as data, so the PDF still fetches nothing. A comparison PDF is usually one page longer than before; a single-page report keeps its length.

### Security
- The PDF renderer reads no file and no URL for an image, stylesheet or `@import`, whatever the markup: mPDF's asset stream wrappers are all off and its local loader and HTTP client are replaced by ones that read nothing. The report markup already held no such reference (every value is escaped); this is a second lock. Fonts are unaffected.

## 2.0.1

Scoring changed, so reports made by this version carry `engine_version` 2.0.1.

### Security
- The address check covers every special-purpose IPv4 and IPv6 range, including IPv4-mapped, NAT64 and 6to4 addresses.
- Page fetches connect to the address that was checked (cURL `CURLOPT_RESOLVE`), which closes the DNS-rebinding window. They use cURL only and fail closed: without a usable cURL transport the audit does not run, and an answer that did not pass through the pin is discarded.
- The PHP `curl` extension is now required. Without it the plugin stays off and shows a notice.
- Saved API keys and secrets (PageSpeed, Brevo, scan server) are no longer written into the Settings page. A blank field keeps the saved key, an invalid new key is refused without touching the saved one, and a separate "Remove the saved key" link (admin only, nonce-checked) deletes it. The keys are not exposed through the REST settings route.
- Saving a pasted key works when the copy brought invisible characters, a non-breaking space or quotes with it: these are removed before the key is checked (the accepted characters are unchanged). The Settings page now confirms each replaced key, says why a key was refused, and marks the field that was not saved.
- Email-code tries are counted in one database statement, so parallel requests cannot go past 5 tries, and a code unlocks once only. Code checks are limited to 30 an hour per visitor.

### Fixed
- Only a 2xx answer with a body is scored. A page with no Content-Type must look like HTML.
- A refused, rate-limited or failing llms.txt, sitemap or feed shows "Not measured" instead of lowering the score. So does a robots.txt that is rate-limited or fails; a robots.txt refused with 401 or 403 is still read as "no rules" (RFC 9309).
- Error messages name the problem: address not found, certificate, connection refused, too many redirects, or timeout.
- The page-type half of "Schema types" counts only the things the page describes, not nested helpers such as SearchAction or BreadcrumbList.
- "Disallow: /" for every crawler fails the AI crawler check when any AI crawler falls under it. When all seven AI crawlers have groups of their own, the "*" group does not bind them (RFC 9309) and their own rules decide.
- The plugin ZIP leaves out `composer.lock`.

### Changed
- The PDF footer now names the site that made the report: "Report generated by <Site Title> (<home URL>) with Halo Audit." It no longer credits Thrivemattic by default. The `halo_audit_pdf_footer` filter still changes or removes it.
- "Not measured" wording: an unmeasured check is not counted as a failure. The score is worked out from the checks that ran, so it can still move up or down when a check drops out.

## 2.0.0

### Changed
- New check engine: 33 scored checks in four groups, plus 4 education extras.
- Thresholds follow the Halo SEO, GEO and AEO standard.
- A check that cannot run shows "Not measured" and is left out of the score.
- Grades A to F replace the eight-tier label.
- Endpoints moved from `admin-ajax.php` to the REST API under `/wp-json/halo-audit/v1/`.
- Reports are stored on the server. The email gate is enforced on the server.
- The PDF is built on the server from the stored report.
- Admin reports list is paginated. Lead export is one CSV.
- Needs PHP 8.0 and WordPress 6.2.
- The widget needs no theme code. It lays itself out from the width of the place it is put in, and keeps its state on its own root element (`data-state`, `halo-audit:state` event).
- Without a PageSpeed key the speed test is skipped. Speed shows "Not measured".
- The user agent names the site that runs the plugin.

### Added
- Shortcode options `title=""`, `heading_level` and `align`.
- Labels on every field, live status messages, and colours that meet 4.5:1 contrast.
- An admin notice when a verification email cannot be sent.
- A notice when a needed PHP extension is missing.
- `bin/build-zip.sh` builds an installable ZIP with the production packages inside.
- AI-readiness group: AI crawler access, llms.txt, schema types, FAQ schema.
- Limits on audits, email codes and downloads.
- A slot for the live AI visibility scan.
- Unit and integration tests.

### Removed
- Competitor comparison.
- OpenAI and Google NLP add-ons.
- Bundled TCPDF copy and other unused code.

### Security
- Several fixes. Update as soon as possible.


## [1.0.0] - 2024-01-03

### Added
- Initial plugin release
- Basic SEO analysis functionality
- WordPress integration
- Admin interface
- Basic reporting features
- OpenAI integration support
- SEO analysis features
- Public API endpoints
- Custom shortcode functionality
- Database management system
- User authentication and OTP verification
- AJAX handlers for asynchronous operations
- PDF report generation capability
