# Changelog

All notable changes to the SEO Analyzer plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Changed (results design review, 22 Oct 2026)
- Area cards: the name on its own line, the score in large figures with a score bar, and only the counts that are not zero ("5 to fix · 1 to improve · 6 pass").
- Speed while the test runs: "Testing…" and "Takes up to 90 seconds", with no counts. The status line says "Page read. The speed test is still running." until it ends, then "Audit complete."
- The Speed area is named "Speed (mobile)". The keyword is shown beside the page address.
- Findings: "Fix these first" lists the top 5 Fix and Improve results, ranked by severity and area weight. In each area the checks needing work come first (Fix, Improve, Not measured), each with a "What to do" row; passed checks are folded under "N checks passed". Single column at every width.
- Status badges, colours and type follow the PDF: dark text on a light tint, olive accent `#808000`, charcoal ink, Oswald (`--halo-display`) for scores and headings. "Download PDF" is a charcoal outline button.
- New shortcode attributes `cta_url`, `cta_title`, `cta_text` and `cta_button` add an optional closing block under the findings. Without `cta_url` there is no block.

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
