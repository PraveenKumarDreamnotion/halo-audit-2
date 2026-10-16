# Changelog

All notable changes to the SEO Analyzer plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added
- Competitor comparison is back, built on the 2.x engine. With `comparison="true"` on the shortcode (as in 1.x) the form asks for your page, a competitor page and a keyword. Both pages are audited by the same checks and scoring; the report shows both overall scores, a side-by-side table of the four areas with the difference, and the competitor's result next to each of your findings. The PDF carries the same comparison. `competitor="false"` keeps it off; `competitor="true"` alone does nothing (as in 1.x).
- The competitor's report is stored in the `competitor_url`, `competitor_results` and `competitor_overall_score` columns that the table already had. No schema change.
- The Reports screen shows the competitor page and its score for a comparison, and the leads CSV has two new columns at the end, "Competitor page" and "Competitor score" (the earlier columns keep their places).
- Brevo list sync. With a Brevo API key and the new "Brevo list ID" setting, a visitor who confirms their email with the code is added to that Brevo contact list. A new contact is created in the list; an existing contact is added to it. Both get the attributes NAME (from the code form), KEYWORD, URL and COMPETITOR_URL (as typed in the audit form) and FORM_NAME (the address of the page the form was submitted on), read from the stored report, never from the request; an attribute with no value is left out, so it never blanks a value in Brevo, and no other attribute changes. Success is recorded only when Brevo's own record shows the contact in the list. The sync runs after the unlock answer is sent and never blocks the report; timeouts, rate limits and Brevo server errors are retried by WP-Cron (4 tries). The Reports screen has a "Brevo list" column and the plugin's screens show a notice after a failed sync, with the reason (invalid key, IP not in Brevo's Authorised IPs, unknown list, and so on). No API key, email or Brevo response is stored or logged. Empty list ID means no sync. (1.x added contacts to list 14 from the OpenAI add-on; 2.0.0 removed the add-on and nothing replaced it.) Each attribute is read back from Brevo after the write; one Brevo did not keep (for example an attribute the account has not defined), or a FORM_NAME that could not be sent because the form page was unknown, is named in the Reports screen's Brevo column and in an admin notice, instead of counting as a complete sync.

### Fixed
- Comparison findings ("See all findings" and the PDF) show both pages for every check: your page and the competitor page each in their own labelled column, with that page's status and evidence. Checks are matched by ID on the server. A check that ran on only one page shows "Not checked" on the other side; before, the competitor's evidence was not shown at all and checks that ran only on the competitor's page were left out. Each area also shows both pages' scores.
- Comparison speed results no longer go stale when answers arrive out of order: a full report fetched before a speed test finished is fetched again instead of replacing newer scores, and a speed test refused by the rate limit shows "Not measured" in the findings as well as in the summary.

### Changed
- A comparison counts as two audits in the hourly limit, and as two speed tests when a PageSpeed key is saved.
- If either page of a comparison cannot be read, or both addresses lead to one page, nothing is stored and the message names the page. 1.x showed the report with the competitor marked "Unavailable".

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
