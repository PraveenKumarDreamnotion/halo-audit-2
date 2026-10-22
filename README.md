# Halo Audit 2

Halo Audit is a WordPress plugin. A visitor enters a page address and a keyword. The plugin scores that page in four areas: on-page SEO for the keyword, technical health, AI readiness and speed. The score and grade are free. The full findings and a PDF need a name and an email confirmed with a 6-digit code.

This repository holds version **2.0.1**. The plugin is stand-alone: it needs no theme changes, no other plugin and no add-on.

> **Status: not released.** Nothing in this repository has been deployed. Open review items are tracked in [issue #1](https://github.com/PraveenKumarDreamnotion/halo-audit-2/issues/1). Read it before you install on a live site.

## Where this came from

This is a snapshot of branch `halo-audit-2.0.1-delivery` (commit `c95f424`) in [`PraveenKumarDreamnotion/seo-analyzer`](https://github.com/PraveenKumarDreamnotion/seo-analyzer). The first commit here has the same files. The earlier history, with the retired 1.x code and its add-ons, was left out on purpose.

## Repository layout

| Path | What it is |
|---|---|
| `SEO-plugin/` | The plugin source (folder name kept from the original repository). |
| `SEO-plugin/README.txt` | The full plugin manual: requirements, settings, shortcode, email, security limits, themes, troubleshooting, uninstall, developer filters. |
| `SEO-plugin/CHANGELOG.md` | Changes by version. |
| `SEO-plugin/includes/engine/` | The analyzer, the checks and the scoring (namespace `HaloAudit\`). `Wp/` holds the WordPress layer: shortcode, REST routes, email gate, PDF, admin screens, Brevo sync. |
| `SEO-plugin/public/` | The widget's CSS and JavaScript. |
| `SEO-plugin/tests/` | `unit/` (plain PHPUnit) and `integration/` (WordPress test suite in wp-env). |
| `SEO-plugin/bin/build-zip.sh` | Builds the installable ZIP. |
| `docs/halo-audit/` | Design notes, the original build plan and acceptance records. `DESIGN.md` is the reference for scoring and security. `README.md` and `PLAN.md` there describe the plan from before the rebuild and are kept as history. |

## Requirements

- WordPress 6.2 or later (tested on 7.1).
- PHP 8.0 or later with the `curl`, `dom`, `mbstring`, `openssl` and `gd` extensions. Without one of them the plugin shows a notice and stays off.
- The site must be able to send email and make outgoing HTTPS requests.

## Install

The repository is source code. Install from a built ZIP, not from a copy of `SEO-plugin/`: the ZIP includes the Composer packages, and the source does not.

1. Build the ZIP (see [Build the ZIP](#build-the-zip)). It is written to `dist/halo-audit-2.0.1.zip`.
2. In WordPress go to Plugins > Add New > Upload Plugin, choose the ZIP and press Install Now, then Activate.
3. Add `[seo_analyzer]` to a page. Add `comparison="true"` for the competitor comparison.

**Updating a site that runs 1.x:** replace the files in the existing plugin folder. **Never delete the 1.x copy from the Plugins screen.** The 1.x uninstall drops the table that holds reports and leads. Export the leads first.

Settings (all optional) are under SEO Analyzer > Settings: PageSpeed key, Brevo key and sender, and Brevo list ID. See `SEO-plugin/README.txt` for what each one does.

## Develop and test

You need Docker. You don't need PHP on the host: the commands below run it in containers. Run them from `SEO-plugin/` unless stated otherwise.

Install the development packages:

```sh
docker run --rm -v "$PWD:/app" -w /app composer:2 composer install --ignore-platform-req=ext-gd
```

Unit tests:

```sh
docker run --rm -v "$PWD:/app" -w /app php:8.0-cli php vendor/phpunit/phpunit/phpunit
```

Integration tests (WordPress test suite in [wp-env](https://www.npmjs.com/package/@wordpress/env), PHP 8.1):

```sh
npm install
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin php vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml
```

The tests make no real network calls. Every HTTP request, including those to Brevo and PageSpeed, is answered by a test stub. Codes are captured instead of emailed.

Last recorded results, on this exact tree (2026-10-22):

| Check | Result |
|---|---|
| Unit, PHP 8.0, 8.1, 8.3 | 416 tests OK on each |
| Integration, PHP 8.1 | 305 tests OK |
| `php -l` on 116 files, PHP 8.0, 8.1, 8.3 | No errors |
| `node --check public/js/halo-audit.js` | OK |

Not run: integration tests on PHP 8.0, WordPress 6.2, and real email, Brevo or PageSpeed calls.

## Build the ZIP

From the repository root:

```sh
docker run --rm -v "$PWD:/repo" -w /repo/SEO-plugin composer:2 sh bin/build-zip.sh /repo/dist
```

The script builds from the last commit, not from uncommitted files. It installs the production Composer packages and leaves out tests and build files. It writes `dist/halo-audit-<version>.zip`, with a single `seo-analyzer/` folder inside. `dist/` and `*.zip` are not tracked.

## Known limits

Full detail is in [issue #1](https://github.com/PraveenKumarDreamnotion/halo-audit-2/issues/1). The release checklist, with the status and evidence for each item, is in [`docs/halo-audit/README.md`](docs/halo-audit/README.md#release-checklist).

- **Brevo `FORM_NAME` has only been checked against mocked Brevo responses.** Passing tests don't show that a real Brevo account stores the attribute.
- **Competitor comparison and the Brevo list sync expand the original 2.0 scope.** Both are pending review (see `docs/halo-audit/DESIGN.md`).
- **Not deployed.** It is unconfirmed whether production runs the earlier security hotfix or 2.0.1. 2.0.0 had address-check and email-code gaps that 2.0.1 fixes (see `SEO-plugin/CHANGELOG.md`), so update any site still on 2.0.0.
- **Live Brevo writes.** On any site with a live Brevo key and a list ID saved, every code verification writes a real contact. Don't submit test forms on such a site.

## Licence

The plugin is licensed under the **GNU General Public License, version 2 or (at your option) any later version**, as its header declares (`SEO-plugin/README.txt`, `SEO-plugin/composer.json`). The full text of version 2 is in [`LICENSE`](LICENSE). Third-party packages installed by Composer carry their own licences. The built ZIP also ships FreeSans from GNU FreeFont (from mPDF's font folder), used for Devanagari text in the PDF. It is licensed under the GNU General Public License, version 3 or later, with the FreeFont font exception, so a PDF that embeds the font is not itself covered by the GPL. The full terms are in [`SEO-plugin/licenses/FreeFont-GPL-3.0-with-font-exception.txt`](SEO-plugin/licenses/FreeFont-GPL-3.0-with-font-exception.txt).
