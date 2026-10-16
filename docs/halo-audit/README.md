# Halo Audit 2: design and delivery notes

This folder holds the design, the original build plan and the acceptance
records for Halo Audit 2. The plugin itself is in `SEO-plugin/`. To install
or test it, start with the repository's top-level `README.md` and
`SEO-plugin/README.txt`.

**Where things stand:** version 2.0.1 is built and tested locally. It is not
released or deployed. Open review items are in
[issue #1](https://github.com/PraveenKumarDreamnotion/halo-audit-2/issues/1).

| File | What it is | Status |
|---|---|---|
| `README.md` | This page. | Current |
| `DESIGN.md` | What the plugin does and why: checks, thresholds, scoring, data, security. | Current. Notes added after 2.0.1 are marked "pending review" |
| `ACCEPTANCE.md` | What was tested, where, and what was not. | Current, up to 2.0.1 |
| `THEME-INTEGRATION.md`, `theme-integration.patch` | An optional patch for one site's theme (GeneratePress child). The plugin does not need it. | Optional |
| `PLAN.md` | The original 22-task build plan, with code and tests for each task. | History. The build is done, and the code has moved on since |

## What the plugin does

A visitor enters a page address and a keyword. The plugin scores the page in
four groups:

| Group | Weight |
|---|---|
| On-page SEO for the keyword | 30% |
| Technical | 20% |
| AI readiness | 30% |
| Speed | 20% |

- **Checks:** 33 scored checks, plus 4 unscored checks for schools, colleges
  and universities.
- **Free and gated:** the score and an A to F grade are free. The findings and
  the PDF need a name and an email confirmed with a code. The server enforces
  this.
- **Unmeasured checks:** a check that cannot run shows "Not measured". The
  score is worked out from the checks that ran.
- **Speed:** uses the Google PageSpeed API when a key is saved. It is scored
  from the PageSpeed score, LCP and CLS.

`DESIGN.md` section 2 names the source of each check rule, and section 3.2
has every threshold.

## What changed from 1.x

| 1.x | 2.x |
|---|---|
| One 2,400-line class | An engine in `SEO-plugin/includes/engine/` with no WordPress calls (tested under plain PHPUnit), and a WordPress layer in `includes/engine/Wp/` |
| admin-ajax handlers | REST routes under `/wp-json/halo-audit/v1/` |
| Email gate in the browser; full results in the page source | Findings and the PDF are served only after the server confirms the email code |
| 9 loosely defined categories, some checks counted twice | 4 groups, each check counted once |
| OpenAI and NLP add-ons | Removed. Each check has fixed advice text |
| TCPDF bundled but unused | mPDF only |
| Competitor comparison | Removed in 2.0.0. Restored on the 2.x engine with `comparison="true"` (pending review) |
| Brevo list push through the OpenAI add-on | Removed in 2.0.0. Replaced by an optional Brevo list sync after a confirmed email (pending review) |

What stayed the same: the `[seo_analyzer]` shortcode and the leads table. Old
rows are kept, and new columns are added by a migration that runs on the
first page load. The PageSpeed key moves from its own table to the
`halo_audit_psi_key` option.

## Security

`DESIGN.md` section 7 lists the 1.x holes and how each one is closed.

**Task 2 hotfix.** The most urgent 1.x holes were patched first, on the 1.x
code, as "Task 2" in `PLAN.md`. That hotfix is merged in the original
repository, but it is not confirmed as deployed to production.

**2.0.1 hardening:**

- Page fetches refuse every private and special-purpose IPv4 and IPv6 range,
  including IPv4-mapped, NAT64 and 6to4 forms.
- Each fetch connects to the address that was checked (cURL only), and the
  audit fails closed without cURL.
- Each email-code attempt is counted atomically, and a code unlocks once.
- Saved API keys are never written into the Settings page.

These notes describe the gaps that 2.0.0 had. Any site still running 2.0.0
should be updated.

## Before deploying

1. Install from the built ZIP (see the top-level `README.md`). Do not run
   Composer on the server. The server needs PHP 8.0+ with `curl`, `dom`,
   `mbstring`, `openssl` and `gd`, and WordPress 6.2+.
2. **Updating from 1.x: replace the files in place.** Never delete the 1.x
   copy from the Plugins screen. Its uninstall drops the reports and leads
   table. Export the leads first.
3. Deactivate and delete the retired OpenAI and NLP add-ons. The NLP add-on
   folder holds a Google service-account key. Its owner should deactivate the
   add-on, then rotate the key, then delete the file, in that order.
4. **Check the visitor IP.** Rate limits use `REMOTE_ADDR`. If the host's
   proxy puts its own address there, every visitor shares one limit. Use the
   `halo_audit_client_ip` filter in that case.
5. Check the PageSpeed key in Settings after the migration.
6. **Brevo list sync** is off until a list ID is saved. The five contact
   attributes (NAME, KEYWORD, URL, COMPETITOR_URL, FORM_NAME) must exist in
   Brevo. With a live key and a list ID saved, every confirmed email writes a
   real contact, so do not submit test forms on such a site. FORM_NAME has
   only been tested against mocked Brevo responses.
7. Page copy on the site is not part of the plugin, and some of it is out of
   date. The tool page says "no signup", but the findings now need an email.
   The comparison page mentions 8 categories, but 2.x has 4 groups.

## Decisions

Sandeep made these on 2026-10-09 (`DESIGN.md` section 1):

| Question | Decision |
|---|---|
| Scope | Rebuild the plugin now. A scan server for live AI answers comes later |
| Gate | Score and grade free. Findings, PDF and the future AI scan need an email |
| Audience | Any site. Education sites get four extra checks |
| Code approach | New engine inside the same plugin shell. Add-ons removed |

Made later by the developer, and **pending Sandeep's review**:

- The 2.0.1 scoring rules (`DESIGN.md` sections 3.2 and 3.3).
- The return of the competitor comparison.
- The Brevo list sync (`DESIGN.md` section 4).

Reports with no confirmed email are deleted after 7 days. Reports with a lead are kept.

## What comes after this

This is part 1 of 3.

- **Part 2:** a scan server that asks ChatGPT, Gemini and Google AI
  Overviews about the visitor's organisation.
- **Part 3:** the plugin calls that server. The slot is already there: the
  `ScanClient` interface, two settings fields (not used yet) and a "Live AI
  visibility" section that says "Coming soon".

## Questions

Ask Sandeep in `#thrivemattic`, or open an issue in this repository.
