# Halo Audit 2.0: start here

This folder holds the design and the build plan for the next version of the
SEO Analyzer plugin. Nothing in `SEO-plugin/` has changed yet. This is a plan
to review and then build.

| File | What it is | Read it when |
|---|---|---|
| `README.md` | This page. The short version. | First |
| `DESIGN.md` | What we are building and why. 325 lines. | Before you review |
| `PLAN.md` | How to build it, step by step, with all the code and tests. 22 tasks. | While you build |

## Do this first: the security patch

**Task 2 in `PLAN.md` is urgent. Please ship it before anything else.**

The current plugin has holes. The worst one lets a logged-out visitor read a
lead's name and email from the reports screen. `DESIGN.md` section 7 lists all
of them. This repo is public, so that list is public too.

Task 2 is small: edits in 5 files, mostly deletions, no new dependencies. It:

- adds a login check and a nonce to the admin report actions;
- removes the open test-mail endpoint and four open "save competitor" endpoints;
- removes a database clean-up that runs on every admin page load;
- removes log lines that write codes, keys and lead data.

To ship it without the rest:

1. `git checkout -b security-hotfix main`
2. Do Task 2, Steps 3, 4 and 5 (the code edits). The tests in Steps 1, 2 and 6
   need the Task 1 test harness; skip them for the hotfix and check by hand.
3. Check by hand: open
   `/wp-admin/admin-ajax.php?page=seo-analyzer-reports&action=view&id=1` in a
   private window. It must not show a report.
4. Open a pull request, merge, deploy to production.

## Why we are rebuilding

The tool at `/ai-tools/seo-analysis/` is sold as a sample of Halo. Today it
does not match Halo:

- Several checks are wrong. "Heading Structure" can never fail. The AI-crawler
  check scores 0 for a normal `robots.txt` that has no `User-agent: *` line.
- Three checks are counted twice, which bends the score.
- A failed fetch can read as a bad score.
- The email gate is in the browser only. The full results are already in the
  page source before the visitor gives an email.
- One 2,400-line class does everything, so nothing can be tested.

## What changes for a visitor

| Today | After |
|---|---|
| One score, eight tier labels | One score, grade A to F |
| 9 loosely defined categories | 4 groups: On-page 30%, Technical 20%, AI readiness 30%, Speed 20% |
| About 20 checks, several duplicated | 33 scored checks, plus 4 extra checks for schools, colleges and universities |
| A check that cannot run scores 0 or 50 | It shows "Not measured" and is left out of the score |
| Waits up to 90 seconds for PageSpeed | First result in under 15 seconds. Speed fills in after |
| Gate in the browser | Score and grade are free. Findings and PDF need a confirmed email, enforced on the server |
| Competitor comparison | Removed for now |
| OpenAI and NLP add-ons | Removed. Every check has its own fixed advice text |

The check rules come from the Halo platform and from our university research
pipeline. `DESIGN.md` section 2 names each source. Section 3.2 has every
threshold in one table.

## What changes in the code

- **New engine** in `SEO-plugin/includes/engine/`. It has no WordPress calls,
  so it runs under plain PHPUnit. One small class per check.
- **WordPress layer** in `SEO-plugin/includes/engine/Wp/`: stored reports, the
  email gate, rate limits, REST routes, PDF, shortcode, admin screens.
- **REST, not admin-ajax.** Routes live under `/wp-json/halo-audit/v1/`.
- **Same shortcode.** `[seo_analyzer]` keeps working. Old attributes are
  accepted and ignored.
- **Same leads table**, with new columns added by a migration. Old rows stay.
- **Deleted:** the old public class, its JS and CSS, the old admin class, the
  bundled TCPDF copy (it was never used; the plugin uses mPDF), both add-on
  plugins, and other dead files.

`PLAN.md` has a file map near the top.

## What you need on your machine

- PHP 8.0 or newer, and Composer.
- Node and Docker Desktop, for the integration tests only (`@wordpress/env`).
- The unit tests need neither Docker nor WordPress.

## How to build

1. Read `DESIGN.md`. Comment on this pull request if you disagree with a
   decision. It is cheaper to change the design now than the code later.
2. Merge this pull request. It adds documents only.
3. Ship the Task 2 hotfix (see above).
4. `git checkout -b halo-audit-engine main`, then follow `PLAN.md` from Task 1.
5. Each task is test first: write the test, see it fail, write the code, see
   it pass, commit. The plan gives the exact code and the exact commands.
6. Task 22 is the acceptance run and the pull request for the engine.

Tasks 3 to 13 (the engine) do not touch the live plugin files, so they are
safe to do in any week. The live behaviour changes at Task 14, when the new
bootstrap replaces the old one. Tasks 14 to 21 should land together.

The plan is written so that an AI coding agent can follow it task by task.
A person can follow it the same way.

## What changes at deploy time

1. Run `composer install --no-dev` in `SEO-plugin/`.
2. The server needs PHP 8.0+ and WordPress 6.2+.
3. The database migration runs by itself on the first page load.
4. The PageSpeed key moves from its own table to an option. The migration
   copies it. Please check it in Settings after the update.
5. Deactivate and delete the two add-on plugins on the site.
6. **Check the visitor IP.** Rate limits use `$_SERVER['REMOTE_ADDR']`. If
   Kinsta puts a proxy address there, every visitor shares one limit. The plan
   has a filter, `halo_audit_client_ip`, for that case. Task 22, Step 6 tells
   you how to check.
7. The page copy on `/ai-tools/seo-analysis/` says "no signup". That line
   must change, because the full findings now need an email. Sandeep owns
   that copy change.

## Decisions already made

Sandeep made these on 2026-10-09. They are recorded in `DESIGN.md` section 1.

| Question | Decision |
|---|---|
| Scope | Rebuild the plugin now. A scan server for live AI answers comes later |
| Gate | Score and grade free. Findings, PDF and the future AI scan need an email |
| Audience | Any site. Education sites get four extra checks |
| Code approach | New engine inside the same plugin shell. Add-ons removed |

## Where the plan departs from the design

Small points, all noted here so that they are not a surprise:

- The integration tests use the WordPress test library that ships with
  `@wordpress/env`, not the `wp-phpunit` Composer package. Same library,
  fewer moving parts.
- REST permission checks answer 403 with a message. WordPress would answer
  401 to a logged-out visitor by default.
- The crawler identifies itself as `HaloAudit/2.0` in a browser-style
  user-agent. Some sites will block it. Those pages show "We could not read
  this page" and get no score, which is the intended behaviour.

## Things to decide during review

1. **Kinsta and `REMOTE_ADDR`**: see deploy point 6. You know the hosting set-up.
2. **Brevo list sync.** The old OpenAI add-on also pushed contacts to Brevo
   lists 9 and 14. The new plugin stores leads in the table and exports CSV.
   It does not push to a Brevo list. Tell Sandeep if the list push is in use.
3. **Report retention.** Reports with no confirmed email are deleted after
   7 days. Reports with a lead are kept. Say so if a different period is needed.

## What comes after this

This is part 1 of 3.

- **Part 2:** a scan server that asks ChatGPT, Gemini and Google AI Overviews
  about the visitor's organisation.
- **Part 3:** the plugin calls that server. The slot for it is already in this
  plan: the `ScanClient` interface, two empty settings fields, and a "Live AI
  visibility" section that says "Coming soon".

Each part gets its own design and plan.

## Questions

Ask Sandeep in `#thrivemattic`, or comment on the pull request.
