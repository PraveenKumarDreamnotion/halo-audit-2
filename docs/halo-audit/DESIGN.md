# Halo Audit plugin rebuild (part 1 of 3): design

Date: 2026-10-09
Status: approved by Sandeep on 2026-10-09. Build plan: `docs/halo-audit/PLAN.md`
Target repo: `PraveenKumarDreamnotion/seo-analyzer` (public), plugin folder `SEO-plugin/`
Live on: `https://stg-thrivemattic-staging.kinsta.cloud/ai-tools/seo-analysis/`

This repo is public and section 7 describes holes in deployed code. Deploy the
Task 2 patch from `PLAN.md` before any other work.

## 1. Goal

Turn the free "Halo Audit" tool into a real sample of Halo quality that brings
leads. Today it scores one page against one keyword with checks that are weak or
wrong, and it has security holes.

The whole job has three parts. Each gets its own spec and plan.

1. **Plugin rebuild (this spec).** Security fixes and a new check engine in PHP.
2. **Scan server.** Runs live AI-answer checks (ChatGPT, Gemini, AI Overviews)
   through BrightData. Separate spec.
3. **Link them.** The plugin requests a scan and shows the result. Separate spec.

Part 1 must leave a clean slot for parts 2 and 3 so that nothing else in the
plugin changes when the scan arrives.

### Decisions made by Sandeep (2026-10-09)

| Decision | Choice |
|---|---|
| Scope | PHP plugin plus a scan server (three parts, plugin first) |
| Email gate | Score and grade are free. Full findings, PDF and the live AI scan need name + email + code |
| Audience | Any site, plus education extras when the site is a school, college or university |
| Delivery | The design and plan go to this repo as a pull request. Praveen reviews them, builds from the plan, and deploys (changed 2026-10-10) |
| Code approach | New engine, same shell. Both add-ons (OpenAI, Google NLP) switched off |

### Success criteria

- Every check result matches Halo's result for the same page on 5 known sites.
- A logged-out visitor cannot read, change or export any lead or report.
- A check that cannot run never lowers a score.
- The fast checks return in under 15 seconds on a normal page.
- Praveen can deploy with `composer install` and a plugin update, as today.

## 2. Sources being ported

| Source | What is taken |
|---|---|
| `halo-seo-framework/packages/rank-tracker/src/halo_seo_rank_tracker/launch_check.py` (`run_onpage_checks`, line 249; `CHECKS`, line 30) | Single-URL on-page battery, og:image check, staging-leak hosts |
| `halo-seo-framework/packages/cli/src/halo_seo_cli/commands/recon.py` | SSL (120), robots and AI bots (171), security headers (259), JS rendering (783), redirects (828), sitemap health (889) |
| `halo-seo-framework/packages/core/src/halo_seo_core/robots.py` | RFC 9309 group matching for AI-bot access |
| `private-uni-research/agents/ai_readability_agent.py` (`SCORE_WEIGHTS`, line 74) | AI-readiness checks: llms.txt soft-404 guard, JSON-LD types with `@graph`, semantic HTML, SSR content, RSS |
| `private-uni-research/src/form_ux/checks.py`, `src/form_ux/freshness.py` | Education extras: apply-link findability, fee visible, sitemap freshness buckets |
| `tm_SEO/docs/SEO-GEO-AEO-BEST-PRACTICES.md` | Thresholds. This doc wins where it disagrees with Halo code |

Lessons carried over from `private-uni-research` docs: "unmeasured is not zero"
(`docs/DATA_QUALITY_FALSE_ZEROS.md`), and a failed or blocked fetch must never
be reported as a bad site.

## 3. The report

Inputs stay the same: one URL and one primary keyword.

### 3.1 Scored groups

| Group | Weight | Checks |
|---|---|---|
| On-page for the keyword | 30 | title length; keyword in title; keyword in H1; keyword in URL; meta description length; exactly one H1; heading order; self-referencing canonical; no `noindex`; word count; image alt text; internal links |
| Technical | 20 | HTTPS; certificate expiry; redirect chain; `robots.txt` reachable; sitemap present and valid XML; six security headers; staging-host leaks; content visible without JavaScript |
| AI readiness | 30 | AI crawlers allowed; `llms.txt`; JSON-LD present and valid; schema types; FAQ schema; semantic HTML; Open Graph complete; Twitter card; og:image reachable and sized; RSS/Atom feed |
| Speed | 20 | PageSpeed Insights mobile performance score; LCP; CLS |

### 3.2 Thresholds

| Check | Pass | Warn | Fail |
|---|---|---|---|
| Title length | 50 to 60 chars | 30 to 49, or 61 to 65 | missing, under 30, over 65 |
| Meta description | 140 to 160 chars | 70 to 139 | missing, under 70, over 160 |
| H1 | exactly one | | none, or more than one |
| Heading order | no skipped level, in document order | one skip | |
| Canonical | present and self-referencing (trailing slash normalised) | present, points elsewhere | missing |
| Noindex | not present in meta robots or `X-Robots-Tag` | | present |
| Word count (visible text, scripts and styles removed) | 300 or more | 200 to 299 | under 200 |
| Image alt | every `<img>` has an `alt` attribute | 80% or more | under 80% |
| Internal links (same host, http/https only) | 2 or more | 1 | 0 |
| Keyword in title / H1 / URL | found (case-insensitive, Unicode-safe) | | not found |
| Certificate | 30 or more days left | 14 to 29 | expired or under 14 |
| Redirect chain | 0 or 1 hop | 2 hops, or a 302/307 | more than 2 hops |
| Security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) | 5 or 6 present | 2 to 4 | 0 or 1 |
| Staging leaks | none | | page references localhost, `.local`, `.test`, `.kinsta.cloud`, wpengine, flywheel or pantheon staging hosts |
| Content without JavaScript | 200 or more words in raw HTML | 50 to 199 | under 50 |
| AI crawlers (GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, PerplexityBot, Google-Extended, CCBot) | all allowed for the page path | 1 or 2 blocked | 3 or more blocked, or `Disallow: /` for `*` |
| `llms.txt` | 200, not HTML, starts with `#` | present but malformed | missing (404, 410 or another 4xx, or an HTML page at that address) |
| JSON-LD | present and parses | none | present but invalid |
| Schema types | Organization or WebSite, plus a page-fit type | only one | none |
| FAQ schema | `FAQPage` present, or the page has no FAQ | FAQ-style heading, no `FAQPage` | 5 or more Q&A items, no `FAQPage` |
| Semantic HTML (`main`, `article`, `nav`, `header`, `footer`) | 4 or 5 present | 2 or 3 | 0 or 1 |
| Open Graph (title, description, image, url) | all four | | any missing |
| Twitter card | present | missing | |
| og:image | reachable, 1200×630 | reachable, other size | missing or HTTP 400+ |
| Feed | feed `<link>` in head or a known feed path answers | missing | |
| PageSpeed performance (mobile) | 90+ | 50 to 89 | under 50 |
| LCP | under 2.5 s | 2.5 to 4.0 s | over 4.0 s |
| CLS | under 0.1 | 0.1 to 0.25 | over 0.25 |

A missing `robots.txt` (404) means all crawlers are allowed. That is a pass for
AI-crawler access and a warn for "`robots.txt` reachable".

Added in 2.0.1 (decided by the developer on 2026-10-15 for the local build; **pending Sandeep's review**, like the other 2.0.1 notes in sections 3.3, 6, 7 and 10):

- **Unavailable answers are not evidence.** A side file that answers 401, 403,
  407, 408, 425, 429, 5xx, a 1xx, or a redirect with no address, or does not
  answer at all, says nothing about whether the file exists. The checks that
  need it are `not_measured`. "No sitemap" and "no feed" need a definite answer
  (404, 410, another 4xx, or a 200 that is not a sitemap or feed) from every
  address tried.
- **robots.txt and AI crawlers follow RFC 9309.** A 4xx `robots.txt`, including
  401 and 403, means no rules, so AI-crawler access passes. A 429 or 5xx leaves
  the rules unknown (`not_measured`). "`robots.txt` reachable" is `not_measured`
  for 401, 403 and 429.
- **`Disallow: /` for `*`** is a fail when at least one of the seven AI crawlers
  has no group of its own and so falls under `*`. When all seven have their own
  groups, `*` does not apply to them (RFC 9309 group selection) and their own
  rules decide.
- **Schema types.** The site half (Organization, WebSite and similar) may be
  nested, for example `Article.publisher`. The page-fit half must be a top-level
  object or an `@graph` member, and is not a helper type (BreadcrumbList,
  ListItem, ItemList, ImageObject, SearchAction, EntryPoint, ReadAction and
  similar). A generic `WebPage` counts as page-fit.

The house rule `no_em_dashes` from Halo is not ported. It is a Thrivemattic
style rule, not a rule for a visitor's site.

### 3.3 Scoring

- Each check returns one of `pass`, `warn`, `fail`, `not_measured`.
- Points: pass 100, warn 50, fail 0.
- Each check has a severity that sets its weight inside its group: critical 3,
  major 2, minor 1. Severity is fixed per check in code.
- Group score = weighted mean of its measured checks, rounded.
- Overall score = weighted mean of the measured groups, using the weights in
  3.1, renormalised over measured groups.
- `not_measured` checks are left out of every mean. A group with no measured
  checks shows "Not measured" and is left out of the overall score.
- So an unmeasured check is never counted as a failure. Because the means are
  renormalised, leaving a check out can still move a group score up or down:
  the score reflects only the checks that ran.
- If the page itself cannot be fetched, there is no score at all (see 6).
- Grade: A 90+, B 70+, C 50+, D 30+, else F (Halo bands).

### 3.4 Education extras

Shown only when the site is detected as an education institution. Not part of
any score, so scores stay comparable across all sites.

Detection (any one is enough): host ends in `.edu`, `.edu.<cc>`, `.ac.<cc>`;
or JSON-LD type is `CollegeOrUniversity`, `EducationalOrganization`, `School`
or `HighSchool`.

| Extra | Rule |
|---|---|
| Apply link | An "apply" / "admission" link is on the submitted page (pass), absent (warn) |
| Fee visible | A currency amount appears near "fee" or "tuition" on the page (pass / warn) |
| Education schema | `Course`, `EducationalOccupationalProgram` or an institution type is present (pass / warn) |
| Sitemap freshness | Newest `<lastmod>`: 14 days or less ACTIVE, 90 MODERATE, 365 STALE, more DORMANT, none UNKNOWN |

These run on the submitted page only. There is no crawl in part 1.

### 3.5 Fix sentences

Every check carries fixed text: a name, a one-line "why it matters", and one
fix sentence each for warn and fail. Written in plain UK English. No LLM call.
Text lives beside the check, in one file per check.

### 3.6 What is free and what is gated

| Free, no signup | After name + email + code |
|---|---|
| Overall score and grade | Every finding with its fix sentence |
| Each group's score and a pass/warn/fail count | Education extras |
| | PDF download |
| | Live AI visibility scan (part 3) |

The site copy "no signup" on the tool page must change. That page edit is
outside this spec.

## 4. Code structure

All new code sits under `SEO-plugin/includes/engine/` with one class per file.

| Part | Job | Depends on |
|---|---|---|
| `Fetcher` | Fetches the page, `robots.txt`, sitemap, `llms.txt`, og:image header bytes. Blocks unsafe hosts. Caches each URL for the life of one analysis. | WP HTTP API |
| `PageFacts` | Parses the HTML once with `DOMDocument`. Exposes title, meta tags, headings in document order, links, images, JSON-LD (with `@graph` expanded), visible text. | none |
| `SiteFacts` | Holds parsed `robots.txt` groups (RFC 9309), sitemap data, response headers, certificate data. | `Fetcher` |
| `checks/` | One class per check, all implementing `Check`: `id()`, `group()`, `severity()`, `run(Context): Result`. | `PageFacts`, `SiteFacts` |
| `CheckRegistry` | The ordered list of checks. Adding a check is one new file plus one line here. | `checks/` |
| `Scorer` | Turns results into group scores, the overall score and the grade. Pure function. | none |
| `ReportStore` | Saves and loads a report by a random 32-char ID. | `$wpdb` |
| `Gate` | Issues and verifies email codes. Sets and reads the unlock cookie. | `ReportStore`, mailer |
| `RateLimiter` | Counts actions per key in transients. | none |
| `Pdf` | Renders a stored report to PDF with mPDF from a PHP template. | `ReportStore` |
| `ScanClient` (interface) | `start(reportId)`, `status(scanId)`, `result(scanId)`. Part 1 ships `NullScanClient`, which always answers "not available". | none |

`Result` is a small value object: `status`, `evidence` (what was found),
`message` (the fix sentence).

### Kept

- Shortcode `[seo_analyzer]` and its attributes. `comparison`, `nlp` and
  `competitor` are accepted and ignored so existing pages do not break.
  Amended after 2.0.1 (2026-10-16, **pending Sandeep's review**): `comparison="true"`
  brings back the competitor comparison, see "Competitor comparison" below.
- Admin menu, Settings page, Reports page.
- Table `wp_seo_analyzer_results` (extended, see 5).
- Brevo mailer with the `wp_mail` fallback.
- mPDF via Composer.

### Removed

- `public/class-seo-analyzer-public.php` check and scoring code (the file
  becomes a thin shortcode + asset loader).
- `includes/tcpdf/` (unused).
- `Seo_Analyzer_API`, `SEO_Analyzer_Records`, the duplicate
  `verify_otp_and_save_data` handler in `seo-analyzer.php`.
- The two add-on plugins are not loaded and are marked deprecated in the
  README. Their folders are deleted from the repo in the same pull request.
- Competitor comparison. It may return in part 3. (Brought back after 2.0.1, see below.)

### Competitor comparison (amendment after 2.0.1, pending review)

Restored on 2026-10-16 at the request of the developer, because the tool page
`/ai-tools/competitor-analysis/` still carries `comparison="true"`.

- From 1.x (recovered from commit `dc8ec62`): `comparison="true"` adds a required
  competitor URL field between the page and the keyword; both pages are audited
  with the same keyword by the same analysis; the report shows both overall
  scores, each category for both pages, and the competitor's result per factor;
  the PDF included the competitor. `competitor` was printed as a data attribute
  and had no effect.
- In 2.x the two pages are scored by the same `Analyzer` and `Scorer`; no second
  engine. Each page gets its own `Analyzer`, so the address check, pinning, time
  budget and cache are separate. Categories are the four 2.x groups, not the
  eight 1.x categories.
- Differences from 1.x: if either page cannot be read the request fails (422,
  "Your page: ..." or "Competitor page: ...") and nothing is stored, where 1.x
  showed the competitor as "Unavailable". Two addresses for one page are refused
  (400). A comparison counts as 2 in the analyze limit and 2 in the speed limit.
  `competitor="false"` turns the comparison off.
- `/analyze` takes an optional `competitor_url`. The competitor report goes in
  the existing `competitor_*` columns. The free view has both summaries; the
  gated view and the PDF add both sets of findings.

### Brevo list sync and comparison findings (amendment, 2026-10-16, pending review)

- 1.x added contacts to Brevo list 14 from the OpenAI add-on's public AJAX action
  (`handle_brevo_submission`, fired by the add-on's popup buttons, no rate limit, full payload logged).
  2.0.0 retired the add-on and nothing replaced it, so verified visitors stopped reaching the list.
- Now `BrevoSync` runs after a successful `/gate/verify` (after the answer is sent; WP-Cron backstop and
  retries). Off unless a list ID is set in Settings. GET the contact; create it with `listIds` if new, or
  add it to the list if it exists. Both get NAME, KEYWORD, URL, COMPETITOR_URL and FORM_NAME from the stored
  report and lead (PUT for an existing contact; empty values left out; no other attribute touched). FORM_NAME
  is the canonical address of the page the form was submitted on, from a signed `data-form-page` value the
  shortcode renders (this site's host only; omitted off single pages). Success only when a final GET shows
  the list in `listIds`. Status per contact in a non-autoloaded option keyed by a hash of the email.
- Comparison findings are paired by check ID on the server (`ReportView::pairs`); the browser and the PDF
  render the pairs, one column per page.

### Competitor speed, PDF name and PDF layout (amendment, 2026-10-19, pending review)

- Each page of a comparison gets its own `/speed` request (`side=mine`, then `side=competitor`). Before, the
  competitor's request was only sent after your page's request answered, so a failed first request (gateway
  timeout, rate limit) meant the competitor was never tested. A failure now marks only that page Not measured.
- The requests stay one after the other, not parallel: one audit never holds two PHP workers for up to 90 s.
- Each page's speed result is written to that page's columns only (`ReportStore::updateSide`), so overlapping
  requests cannot put back an older copy of the other page.
- Speed rows that have not run yet say "The speed test had not finished yet"; with no PageSpeed key, "Speed is
  not tested on this site". Still `not_measured`, so scoring is unchanged. The PDF button waits for a running
  speed test, and a PDF made mid-test says which page had not finished.
- The PDF is always `halo-audit.pdf`. New layout: cover with score and grade, area summary with counts,
  findings per area; results carry a sign and a word, so they read in grayscale.
- PageSpeed (second pass, same day). Google's Performance score is read from
  `lighthouseResult.categories.performance.score` (0 to 1, times 100 once, in `PsiClient::parse`), LCP from
  `audits.largest-contentful-paint.numericValue` (ms) and CLS from `audits.cumulative-layout-shift.numericValue`,
  mobile strategy, lab data only (no CrUX field data is read). A score outside 0 to 1 or a negative or infinite
  metric is now treated as no result. Unchanged: one missing metric makes all three speed checks Not measured.
- The Speed group score is Halo Audit's own (section 3.3: pass 100, warn 50, fail 0, weighted by severity:
  performance 3, LCP 2, CLS 2). Google's 82 with LCP 4.4 s and CLS 0.01 gives (150 + 0 + 200) / 7 = 50. The PDF
  now says so: a "Google PageSpeed results" table per page labels Google's figures as a mobile lab test and the
  Speed score as Halo Audit's own, with its weights. The formula and thresholds are unchanged. Open question:
  section 3.2 uses "under 2.5 s" and "under 0.1" for a pass, where Google counts exactly 2.5 s and 0.1 as good.
- PDF: a "Top fixes" list (Fix before Improve, then check severity, then area weight; at most 5; your page only
  in a comparison). Section titles sit in the first row of their table head, so a title is never left alone at
  the foot of a page and it repeats on each page the table runs onto. Ligatures are off, so "fi" in an address is
  copied as two letters. Design pass (same day): first page = audited page(s), score card(s), "At a glance"
  (only facts from the stored scores), result key, area summary, Top fixes; every table uses mPDF
  `overflow:wrap`, so long addresses wrap without inserted zero-width spaces. mPDF stays: Dompdf would add a second HTML engine for no layout gain shown here, and
  tc-lib-pdf needs PHP 8.2.
- PDF redesign (later the same day; supersedes the "At a glance" layout above). Page 1 is an executive summary:
  title, pages, keyword, report date, score cards, "What the results mean" (sentences built only from stored
  scores and counts, plus the grade scale of `Scorer::grade`), "Scores by area" with bars, "Priority fixes" (same
  order and limit as Top fixes). Page 2 on: result key, "Speed and performance" (Google's three measures and the
  Halo Audit Speed score in one table; the Speed area is no longer printed twice), then On-page, Technical, AI
  readiness (and Education extras). A check's "What to do" is a tinted row under its results, joined to the check
  by rowspan so mPDF keeps them on one page; the competitor's fix is never printed. Running header from page 2,
  credit (`halo_audit_pdf_footer`) in the footer of every page, so no last page holds only the credit. The details
  start on a new page only when the summary fitted on page 1. Browsershot/Chromium was not chosen: the plugin ships
  as a ZIP for ordinary WordPress hosts (no Node or Chromium in the DevKinsta PHP container either).
- Devanagari (QA pass, same day). `Pdf::e()` tags Devanagari runs (danda included) `lang="hi"`; mPDF
  `autoLangToFont` asks `Wp\PdfFonts`, which returns the family `halodeva` (FreeSans.ttf for regular and bold,
  useOTL 0xFF) for `hi` and keeps the current font for every other language. FreeSans over FreeSerif: sans like
  DejaVu, full Devanagari with deva/dev2 tables, 1.5 MB instead of 4.6 MB, already in mPDF's package. Not
  `autoScriptToLang`: it leaves the danda in the following Latin run (a box). Not FreeSans Bold: mPDF 8.3 misplaces
  the anusvara in it (HarfBuzz shapes the same font correctly), so bold Hindi is regular weight. Not in scope:
  CJK (a CJK font is about 20 MB), Tamil, Telugu, Kannada, Gujarati, Oriya (not in FreeSans), Bengali and Malayalam
  (regular only). Copied Hindi text is in display order (mPDF writes no ActualText).

### PDF second design pass (amendment, 2026-10-20, pending review)

From the PDF redesign review (Option A: keep mPDF 8.3.1, redesign the template).
- One type scale, nothing below 7.5 pt: title 24, score numerals 36, section 14, lede and area scores 11, check
  names 9.5, body 9, supporting text 8, labels 7.5. Margins 18 mm at the sides; colours are tokens in `Pdf::css()`.
- A result is the status chip and the evidence on one line (`<span class="evidence">`), long evidence wraps under.
- Each area: the checks that need a look first (your Fix, Improve, not measured, then checks only the competitor
  failed; report order within each), in full with why and what to do; then a compact "Passed" table (comparison:
  passed on both pages) with status and evidence but no why. Every check is printed once; scoring, check order in
  the stored report and the priority ranking are unchanged.
- Section titles are headings kept with their table (`use_kwt`, written by `Pdf::heading()`); the table head carries
  only the column names and, in a comparison, each page's score, so continuation pages repeat a compact head and
  not the title. Tried and rejected: a title row in the thead (repeated in full); a table `<caption>` (left alone
  at a page foot when the first row moved on); a plain h2 (when mPDF moves it with its table it lays the table out
  first and the heading lost its font). The heading's first letter is 0.02 pt larger so mPDF always writes the
  font before the heading text; checked on rendered pages (no heading drawn in table type, none stranded).
- Speed: each measure's name cell says whose measure it is and its unit; the Lighthouse value is printed as
  "N / 100" (`Pdf::lighthouseValue`, display only; stored evidence unchanged); one note on lab data and the weights.
  Page 1 labels the area "Speed (Halo Audit)".
- "What the results mean": areas in plain words (on-page SEO, technical SEO, AI readiness, speed), one sentence
  each for ahead, behind and level. Score cards show each page's host; the competitor bar is #7e837f (3.1:1 on the
  track) and thinner than your page's, so the two stay apart on a grayscale print.
- Default credit: "Report generated by <Site Title> with Halo Audit." (host when there is no title); no address, so
  a staging or local site no longer prints its internal URL. The filter is unchanged.
- Asset lock: mPDF gets `whitelistStreamWrappers = []` and, through its service container, a local-content loader
  and HTTP client that read nothing (mPDF 8.3.1 type-hints its concrete AssetFetcher, so the fetcher itself cannot
  be replaced). Fonts are opened directly and are not affected. `Pdf::refusedFetches()` lists refused paths (tests).
- Check wording only (no logic): singular/plural in Certificate, HeadingOrder, InternalLinks, JsonLd, RedirectChain,
  SitemapFreshness; FaqSchema's pass with no FAQ content now says "No FAQ content on this page, so no FAQ schema is
  needed". Whether that case should be "Not applicable" instead of Pass is an open product decision. Stored
  reports keep their old wording.
- `bin/build-zip.sh` stops when `PdfFonts.php` or the FreeFont licence is not exported (untracked files are left out).

### PDF dashboard redesign (amendment, 2026-10-20, pending review)

Supersedes the page-1 composition and the findings tables of the second design pass. Kept: mPDF 8.3.1, asset lock,
Devanagari font map, escaping, type scale (nothing below 7.5 pt), problems-first plus compact passed lists, the
priority order, kept headings (`Pdf::heading()`), the credit filter.

- **Page 1:**
  - a rounded hero card (eyebrow, title, lede, pages, keyword, date, status);
  - a rounded score panel: SVG score rings, grade chips, the difference in a comparison, and "What the results mean";
  - metric cards for your page's to fix / to improve / passed / not measured counts, with the competitor's counts in a comparison;
  - one card per scored area, two to a row (matched to the reference dashboard on 2026-10-20). Each card has the name with its counts as labelled status chips, the Halo Audit score beside them, a full-width SVG bar (the competitor's thinner and grey, with the difference), and a tinted foot with the top issue, picked by the same ranking as the priority fixes. Each row of cards is its own table that is never split;
  - the priority fixes.
- **Findings:**
  - each check is a card table that is never split: status stripe, check and why, each page's result labelled "Your page" / "Competitor", and the "What to do" band;
  - passed checks are a compact list;
  - each section heading carries its scores.
- **mPDF facts used, each checked on rendered pages:**
  - `border-radius` works on top-level blocks but not on tables or cells, so card grids are square-cornered tables, which also keeps cards in a row equally tall;
  - a block inside a cell draws badly;
  - floats render but are unsafe near page breaks;
  - an inline `<svg>` is written to a temp file and read back, which the asset lock refuses; a `data:image/svg+xml` image is decoded in memory and drawn as vectors, not as an embedded image;
  - a heading moved by `use_kwt` leaves its background behind, so headings use a rule and no fill;
  - a `.class strong` size rule does not apply inside these cells, so check names are 9 pt bold, as in the previous design;
  - margins and padding on a block inside a cell are ignored, so spacing inside cards comes from cell padding or line-height;
  - for a `page-break-inside:avoid` table, mPDF subtracts a keep-with-table heading's height from the remaining space although the heading is already placed (a double count), so "Scores by area" keeps its heading with the first card row with a `page-break-inside:avoid` div instead.

### Endpoints

All move from `admin-ajax.php` to REST under `/wp-json/halo-audit/v1/`.
REST routes have explicit permission callbacks and are easy to test.

| Route | Does | Guard |
|---|---|---|
| `GET /nonce` | Returns a fresh nonce (fixes stale nonces on cached pages) | none |
| `POST /analyze` | Runs the fast checks, stores the report, returns the free view + report ID | nonce, rate limit |
| `POST /speed` | Runs PageSpeed for one page of a report ID (`side=mine` or `side=competitor`; no `side` = the first page without a result), saves that page only, returns the new free view | nonce, report ID |
| `POST /gate/send` | Emails a code | nonce, rate limit |
| `POST /gate/verify` | Checks the code, saves the lead, sets the cookie | nonce, attempt limit |
| `GET /report/{id}` | Returns the full findings | report ID + valid unlock |
| `GET /report/{id}/pdf` | Streams the PDF | report ID + valid unlock |
| `POST /scan`, `GET /scan/{id}` | Slot for part 3. Return "not available" | report ID + valid unlock |

## 5. Data

`wp_seo_analyzer_results` gains columns through one versioned `dbDelta`
migration. Old rows stay readable in the admin list.

| Column | Use |
|---|---|
| `report_key` (char 32, unique) | Public ID of the report |
| `engine_version` (varchar) | Which check set produced it |
| `grade` (char 1) | A to F |
| `is_education` (tinyint) | Detection result |
| `lead_verified_at` (datetime, null) | Set when the code is verified |
| `scan_id` (varchar, null) | Slot for part 3 |

`results` holds the server-built JSON. `name` and `email` are null until the
gate is passed. Reports with no verified lead are deleted after 7 days by a
daily WP-Cron event.

`wp_seo_analyzer_otp` stores a hash of the code (not the code), the expiry and
an attempt count.

The PageSpeed key moves from the custom table `wp_seo_analyzer_api_keys` to an
option. The migration copies the value, then drops the table.

New options: scan server URL, scan server secret (both empty in part 1).

`uninstall.php` drops both tables and deletes every option the plugin sets.

## 6. Visitor flow and failure handling

1. Visitor enters URL and keyword. The form fetches a fresh nonce.
2. `POST /analyze` runs every check except Speed. Target under 15 seconds.
   Fetch timeouts: 15 s for the page, 5 s for each side file.
3. The page shows score, grade and group summaries.
4. `POST /speed` runs in a second request. The Speed group fills in and the
   overall score updates. On failure it shows "Not measured".
5. "See all findings" or "Download PDF" opens the gate: name, email, code.
6. `POST /gate/verify` saves the lead against the report and sets a signed,
   HttpOnly cookie valid for 30 days.
7. `GET /report/{id}` returns the findings. The browser renders them.

| Failure | Behaviour |
|---|---|
| Page answers with anything but a 2xx with a body (4xx/5xx, a bot wall such as 403/429, 1xx, 204/304, a redirect with no address, an empty body), or the fetch fails | No score. The message names the cause: the HTTP status, address not found, certificate, connection refused, more than 3 redirects, or no answer in time. Nothing is stored as a zero |
| Side file fails, is refused or rate-limited (see 3.2) | The checks that need it are `not_measured` |
| PageSpeed fails or has no key | Speed group "Not measured". Overall score renormalised |
| Non-HTML response, or no Content-Type and a body that does not start like an HTML document | No score. Message says the URL is not a web page |
| The server cannot pin the connection (no usable PHP cURL transport) | No audit. The plugin needs the `curl` extension and stays off without it |
| Rate limit hit | HTTP 429 with a plain message and the wait time |
| Wrong code 5 times | The code is void. Visitor must request a new one |

## 7. Security fixes

Deploy the Task 2 patch from `PLAN.md` first. It closes rows 1, 8, 9 and 10.

| # | Hole today | Fix |
|---|---|---|
| 1 | `handle_report_actions` runs on `admin_init` with no capability or nonce check. `admin-ajax.php?page=seo-analyzer-reports&action=view&id=N` can expose lead name and email to a logged-out visitor | `current_user_can('manage_options')` + `check_admin_referer` on every admin action |
| 2 | The gate is enforced only in the browser. Full results are in the DOM and in `window.seoAnalysisResults` | The server returns findings only after a verified unlock |
| 3 | The browser posts the results JSON that gets saved | The server stores its own results. The browser sends the report ID only |
| 4 | SSRF: `wp_remote_get` on any host, localhost allowed, SSL checks off for local hosts, 5 redirects | `wp_safe_remote_get`, http/https only, ports 80/443 only, host resolved and rejected if private, loopback or link-local, max 3 redirects each re-checked, SSL verification always on. Added in 2.0.1: an explicit table of special-purpose IPv4 and IPv6 ranges, with IPv4-mapped, NAT64 and 6to4 addresses judged by the IPv4 inside; every fetch is pinned to the checked address (cURL `CURLOPT_RESOLVE`) and fails closed when cURL cannot be used |
| 5 | No limit on sending codes; no limit on guesses; code stored in plain text | 3 codes per email per hour, 10 per IP per hour, 5 attempts, hash stored. Added in 2.0.1: each attempt is claimed in one conditional `UPDATE` before the code is checked and the code is used up by a single-winner `DELETE`, so parallel requests cannot exceed 5 tries or unlock twice; 30 code checks per IP per hour (best-effort, transient-based) |
| 6 | No rate limit on analysis | 10 analyses per IP per hour |
| 7 | PDF endpoint renders any posted HTML | PDF is built on the server from the stored report. mPDF remote fetching is off |
| 8 | Open endpoints: `test_wp_mail`, four `save_competitor_*` | Deleted |
| 9 | `seo_analyzer_cleanup_database` runs `DELETE ... LIKE` on every `admin_init` | Deleted |
| 10 | `$_POST`, codes, results and the PageSpeed key written to logs and the browser console | All such logging removed |
| 11 | Report HTML built by string concatenation in JS with unescaped page data | Rendering uses `textContent` / escaped templates only |
| 12 | Admin reports list is an unpaginated `SELECT *` | Paginated `WP_List_Table` with prepared queries |

IP for rate limits comes from `REMOTE_ADDR` only. Forwarded headers are not
trusted.

## 8. Tests

- PHPUnit with `wp-phpunit`. Runs with `composer test`.
- One test class per check, driven by saved HTML fixtures: at least one pass,
  one warn and one fail fixture each.
- `Scorer`: weights, renormalisation, all-`not_measured` group, grade bands.
- `robots.txt` parser: grouped user-agents, case, missing `*`, 404 body,
  longest-match `Allow` vs `Disallow`.
- `Fetcher`: rejects `127.0.0.1`, `10.0.0.0/8`, `169.254.169.254`, `localhost`,
  a redirect to a private host, non-http schemes.
- Security: each row in section 7 has a test that fails on the old behaviour.
- Non-Latin page: word count and keyword checks do not error.
- Acceptance run on staging against 5 known sites, compared by hand with
  `aseo page verify --json` output for the same URLs.

## 9. Not in part 1

- Live AI-answer checks (parts 2 and 3).
- Competitor comparison. (Brought back after 2.0.1, see section 4.)
- Multi-page crawl, broken-link check, duplicate-title check.
- LLM-written recommendations.
- Changes to the tool page copy on the site.
- Mounting `aseo recon` in the Halo CLI.

## 10. Delivery

- Work on branch `halo-audit-engine`, made from `main`.
- The Task 2 security patch ships first, as its own pull request.
- One pull request to `PraveenKumarDreamnotion/seo-analyzer`. Commits are
  ordered so Praveen can review in steps: security fixes, engine, checks,
  endpoints and front-end, PDF, removals.
- Plugin version goes to 2.0.0. `Requires PHP: 8.0` and `Requires at least:
  6.2` are added to the header.
- 2.0.1 (hardening, 2026-10-15) changes scoring, so `engine_version` is 2.0.1.
  Required PHP extensions: `curl`, `dom`, `mbstring`, `openssl`, `gd`.
- Praveen deploys to staging. The acceptance run happens there.
