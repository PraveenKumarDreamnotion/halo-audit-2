# Halo Audit Plugin Rebuild Implementation Plan

> **How to use this plan:** work through the tasks top to bottom. Each step is one small action. Tick the checkbox (`- [ ]`) when a step is done. Start with `docs/halo-audit/README.md`.
>
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the check engine of the `seo-analyzer` WordPress plugin with Halo's on-page, technical and AI-readiness rules, close its security holes, and leave a slot for the live AI scan.

**Architecture:** A WordPress-free engine (`HaloAudit\`) parses one page and a few side files into facts, runs 37 small check classes, and scores them. A thin WordPress layer (`HaloAudit\Wp\`) stores reports, gates the full findings behind an emailed code, and exposes REST routes. The old 2,400-line public class, the dead code and both add-ons are deleted.

**Tech Stack:** PHP 8.0+, WordPress 6.2+, Composer (PSR-4), `DOMDocument`, mPDF 8, PHPUnit 9.6, `@wordpress/env` (Docker) for integration tests, vanilla JS.

**Spec:** `docs/halo-audit/DESIGN.md`

## Global Constraints

- Working copy: your clone of this repo, branch `halo-audit-engine` made from `main`. Plugin root is `SEO-plugin/`. Every path in this plan is relative to `SEO-plugin/` unless it starts with `docs/`, `..` or `$REPO`.
- Commands use `$REPO` for the repo root. Set it once per terminal: `export REPO="$(git rev-parse --show-toplevel)"`.
- **Task 2 goes first and goes alone.** This repo is public and `DESIGN.md` section 7 lists holes in deployed code. Open Task 2 as its own pull request and deploy it to production before the other tasks.
- PHP floor is 8.0. Do not use enums, `readonly`, `never`, `new` in initialisers, `array_is_list`, or first-class callable syntax.
- WordPress floor is 6.2.
- Engine classes under `includes/engine/` (outside `includes/engine/Wp/`) must not call any WordPress function.
- A check returns exactly one of `pass`, `warn`, `fail`, `not_measured`. `not_measured` never lowers a score.
- Points: pass 100, warn 50, fail 0. Severity weights: critical 3, major 2, minor 1.
- Group weights: on-page 30, technical 20, AI readiness 30, speed 20. Education extras are never scored.
- Grade bands: A 90+, B 70+, C 50+, D 30+, else F.
- All visitor-facing text is plain UK English. No em-dashes. No "it's not X, it's Y" sentences.
- Fetch limits: 15 s for the page, 5 s for each side file, max 3 redirects, ports 80 and 443 only, SSL verification always on.
- Rate limits: 10 analyses per IP per hour; 3 codes per email per hour; 10 codes per IP per hour; 5 wrong code attempts.
- Never log `$_POST`, codes, API keys, names or emails.
- Commit after every task. Apart from Task 2, do not open the engine pull request until Task 22.
- Unit tests: `vendor/bin/phpunit` (run inside `SEO-plugin/`).
- Integration tests: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin vendor/bin/phpunit -c phpunit.integration.xml` (run inside `SEO-plugin/`, Docker Desktop must be running).

## Review Focus

1. **Page in a non-UTF-8 or mislabelled charset.** A reasonable person expects a normal report, not zero words and failed keyword checks. Pinned in Task 4 (`test_windows_1252_page_is_parsed`).
2. **`robots.txt` or `llms.txt` that answers 200 with an HTML page (soft 404).** Expect "missing", not "all crawlers blocked" or "llms.txt present". Pinned in Task 7 (`test_html_robots_is_not_parsed_as_rules`) and Task 10 (llms.txt HTML row).
3. **URL typed without a scheme, with spaces, or in upper case (`  Example.com/Page `).** Expect it to be analysed as `https://example.com/Page`. Pinned in Task 4 (`test_normalise`).
4. **Slow or very large target site.** Expect a report in bounded time with slow parts shown as "Not measured", never a PHP timeout. Pinned in Task 6 (`test_deadline_stops_further_fetches`) and Task 14 (response size cap).
5. **Site behind a proxy where every visitor shares one `REMOTE_ADDR`.** Expect one busy visitor not to lock everyone out. Pinned in Task 17 (`test_client_ip_filter_is_used`) and checked on staging in Task 22.

---

## File Structure

```
SEO-plugin/
  seo-analyzer.php                     bootstrap only (rewritten in Task 14)
  composer.json                        PSR-4 autoload + dev tools
  phpunit.xml.dist                     unit suite
  phpunit.integration.xml              integration suite
  .wp-env.json                         Docker WordPress for integration tests
  package.json                         @wordpress/env only
  includes/class-seo-analyzer-mailer.php   kept (log line removed)
  includes/engine/
    Result.php  Check.php  BaseCheck.php  Context.php  Scorer.php
    Text.php  Url.php  PageFacts.php  RobotsTxt.php
    Response.php  HttpClient.php  UrlGuard.php  Fetcher.php
    SiteFacts.php  CertProbe.php  SiteProbe.php
    PsiClient.php  Education.php
    CheckRegistry.php  Analyzer.php  ReportView.php
    ScanClient.php  NullScanClient.php
    Checks/OnPage/*.php      12 checks
    Checks/Technical/*.php    8 checks
    Checks/Ai/*.php          10 checks
    Checks/Speed/*.php        3 checks
    Checks/Education/*.php    4 checks
    Wp/Plugin.php  Wp/WpHttpClient.php  Wp/StreamCertProbe.php
    Wp/Installer.php  Wp/ReportStore.php  Wp/RateLimiter.php  Wp/Gate.php
    Wp/RestController.php  Wp/Pdf.php  Wp/Shortcode.php
    Wp/Admin/Settings.php  Wp/Admin/ReportsTable.php  Wp/Admin/Admin.php
  public/js/halo-audit.js   public/css/halo-audit.css
  tests/unit/...            pure PHPUnit, no WordPress
  tests/integration/...     WP_UnitTestCase
```

---

### Task 1: Workspace and test harness

**Files:**
- Create: `composer.json` (replace), `phpunit.xml.dist`, `phpunit.integration.xml`, `.wp-env.json`, `package.json`, `tests/unit/SmokeTest.php`, `tests/integration/bootstrap.php`, `tests/integration/SmokeTest.php`
- Modify: `../.gitignore`

**Interfaces:**
- Produces: PSR-4 autoload `HaloAudit\` → `includes/engine/`, `HaloAudit\Tests\` → `tests/unit/`. The two test commands in Global Constraints.

- [ ] **Step 1: Make the branch**

```bash
export REPO="$(git rev-parse --show-toplevel)"
cd "$REPO" && git checkout main && git pull && git checkout -b halo-audit-engine
git log -1 --format=%H -- SEO-plugin
```

Expected: the last line prints `b965a0e1e9421d35405c81aad27257d4c63bc4fd`. This plan was written against that commit. If `SEO-plugin/` has changed since, re-check the line numbers that Task 2 names before you edit.

- [ ] **Step 2: Write `composer.json`**

```json
{
    "name": "thrivemattic/seo-analyzer",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=8.0",
        "ext-dom": "*",
        "ext-mbstring": "*",
        "ext-openssl": "*",
        "mpdf/mpdf": "^8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "yoast/phpunit-polyfills": "^2.0"
    },
    "autoload": {
        "psr-4": { "HaloAudit\\": "includes/engine/" }
    },
    "autoload-dev": {
        "psr-4": { "HaloAudit\\Tests\\": "tests/unit/" }
    },
    "config": {
        "platform": { "php": "8.0.30" }
    },
    "scripts": {
        "test": "phpunit"
    }
}
```

- [ ] **Step 3: Write the PHPUnit configs**

`phpunit.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="vendor/autoload.php" colors="true" failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`phpunit.integration.xml`:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/integration/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="integration">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 4: Write the smoke tests and the integration bootstrap**

`tests/unit/SmokeTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoload_namespace_is_registered(): void
    {
        $map = require dirname(__DIR__, 2) . '/vendor/composer/autoload_psr4.php';
        $this->assertArrayHasKey('HaloAudit\\', $map);
    }
}
```

`tests/integration/bootstrap.php`:

```php
<?php
$plugin_dir = dirname(__DIR__, 2);
$tests_dir  = getenv('WP_TESTS_DIR') ?: '/wordpress-phpunit';

define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_dir . '/vendor/yoast/phpunit-polyfills');

require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function () use ($plugin_dir) {
    require $plugin_dir . '/seo-analyzer.php';
});

require $tests_dir . '/includes/bootstrap.php';
```

`tests/integration/SmokeTest.php`:

```php
<?php
class Halo_Smoke_Test extends WP_UnitTestCase
{
    public function test_plugin_is_loaded(): void
    {
        $this->assertTrue(defined('SEO_ANALYZER_VERSION'));
    }
}
```

- [ ] **Step 5: Write `.wp-env.json` and `package.json`**

`.wp-env.json`:

```json
{
    "core": null,
    "phpVersion": "8.0",
    "plugins": ["."]
}
```

`package.json`:

```json
{
    "name": "seo-analyzer",
    "private": true,
    "devDependencies": {
        "@wordpress/env": "^10.0.0"
    }
}
```

- [ ] **Step 6: Ignore local artefacts**

Append to the `.gitignore` at the repo root:

```
.DS_Store
.phpunit.result.cache
```

Then remove the tracked `.DS_Store` files:

```bash
cd "$REPO" && git rm -q --cached -r --ignore-unmatch '*.DS_Store'
```

- [ ] **Step 7: Install and run the unit suite**

```bash
cd "$REPO"/SEO-plugin
composer update
vendor/bin/phpunit
```

Expected: `OK (1 test, 1 assertion)`.

- [ ] **Step 8: Start Docker and run the integration suite**

```bash
open -a Docker   # wait until `docker info` succeeds
cd "$REPO"/SEO-plugin
npm install
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin vendor/bin/phpunit -c phpunit.integration.xml
```

Expected: `OK (1 test, 1 assertion)`. If the bootstrap cannot find `includes/functions.php`, run `npx wp-env run tests-cli env | grep WP_TESTS` and put the printed directory in place of `/wordpress-phpunit` in `tests/integration/bootstrap.php`.

- [ ] **Step 9: Commit**

```bash
cd "$REPO"
git add -A
git commit -m "chore: add Composer autoload and PHPUnit harness"
```

---

### Task 2: Hotfix the open holes in the current code

This task is small on purpose. Put this one commit on its own branch from `main` (`git checkout -b security-hotfix main`, then `git cherry-pick <this commit>`), open it as its own pull request, and deploy it to production before the rebuild lands. It needs only Step 3, Step 4 and Step 5 to ship; the tests need the Task 1 harness.

**Files:**
- Modify: `admin/class-seo-analyzer-admin.php:36-46`, `:553-596`, `:688-709`
- Modify: `admin/partials/seo-analyzer-reports-display.php:38`
- Modify: `seo-analyzer.php:94-118`, `:133-191`
- Modify: `public/class-seo-analyzer-public.php:15-24`, `:584`, `:613-614`
- Modify: `includes/class-seo-analyzer-mailer.php:46`
- Test: `tests/integration/HotfixTest.php`

**Interfaces:**
- Produces: nothing later tasks rely on. All of these files except the mailer are deleted or replaced by Task 21.

- [ ] **Step 1: Write the failing tests**

`tests/integration/HotfixTest.php`:

```php
<?php
class Halo_Hotfix_Test extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        unset($_GET['page'], $_GET['action'], $_GET['id'], $_GET['_wpnonce']);
        parent::tear_down();
    }

    public function test_logged_out_visitor_cannot_view_a_report(): void
    {
        wp_set_current_user(0);
        $_GET['page'] = 'seo-analyzer-reports';
        $_GET['action'] = 'view';
        $_GET['id'] = '1';

        $this->expectException(WPDieException::class);
        (new Seo_Analyzer_Admin('seo-analyzer', '1.0.6'))->handle_report_actions();
    }

    public function test_admin_without_nonce_cannot_download(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $_GET['page'] = 'seo-analyzer-reports';
        $_GET['action'] = 'download';
        $_GET['id'] = '1';

        $this->expectException(WPDieException::class);
        (new Seo_Analyzer_Admin('seo-analyzer', '1.0.6'))->handle_report_actions();
    }

    public function test_open_endpoints_are_gone(): void
    {
        foreach (['test_wp_mail', 'save_competitor_score', 'save_competitor_data', 'save_competitor_results', 'save_competitor_overall_score'] as $action) {
            $this->assertFalse(has_action('wp_ajax_nopriv_' . $action), $action);
        }
    }

    public function test_cleanup_hook_is_gone(): void
    {
        $this->assertFalse(has_action('admin_init', 'seo_analyzer_cleanup_database'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run the integration command with `--filter Halo_Hotfix_Test`.
Expected: 4 failures (no exception thrown twice; `has_action` returns a priority twice).

- [ ] **Step 3: Guard the admin report actions**

In `admin/class-seo-analyzer-admin.php`, replace the whole `handle_report_actions` method with:

```php
    public function handle_report_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'seo-analyzer-reports') {
            return;
        }
        if (!isset($_GET['action']) || !isset($_GET['id'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'seo-analyzer'), '', array('response' => 403));
        }

        $id = intval($_GET['id']);
        check_admin_referer('seo_analyzer_report_' . $id);

        if ($_GET['action'] === 'download') {
            $this->download_report($id);
            exit;
        }
    }
```

In `admin/partials/seo-analyzer-reports-display.php` replace the Download link line with:

```php
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=seo-analyzer-reports&action=download&id=' . intval($report['id'])), 'seo_analyzer_report_' . intval($report['id']))); ?>">Download</a>
```

- [ ] **Step 4: Remove the open endpoints, the cleanup hook and the duplicate handler**

In `public/class-seo-analyzer-public.php` delete these ten lines from the constructor (lines 15 to 24):

```php
        add_action('wp_ajax_test_wp_mail', array($this, 'test_wp_mail'));
        add_action('wp_ajax_nopriv_test_wp_mail', array($this, 'test_wp_mail'));
        add_action('wp_ajax_save_competitor_score', array($this, 'save_competitor_score'));
        add_action('wp_ajax_nopriv_save_competitor_score', array($this, 'save_competitor_score'));
        add_action('wp_ajax_save_competitor_data', array($this, 'save_competitor_data'));
        add_action('wp_ajax_nopriv_save_competitor_data', array($this, 'save_competitor_data'));
        add_action('wp_ajax_save_competitor_results', array($this, 'save_competitor_results'));
        add_action('wp_ajax_nopriv_save_competitor_results', array($this, 'save_competitor_results'));
        add_action('wp_ajax_save_competitor_overall_score', array($this, 'save_competitor_overall_score'));
        add_action('wp_ajax_nopriv_save_competitor_overall_score', array($this, 'save_competitor_overall_score'));
```

In `seo-analyzer.php` delete:
- the whole function `seo_analyzer_cleanup_database()` with its docblock and the line `add_action('admin_init', 'seo_analyzer_cleanup_database');` (lines 94 to 118);
- the two `add_action('wp_ajax..._verify_otp_and_save_data', 'seo_analyzer_verify_otp_and_save_data');` lines and the whole function `seo_analyzer_verify_otp_and_save_data()` (lines 133 to 191).

- [ ] **Step 5: Remove the sensitive log lines**

Delete each of these lines:
- `public/class-seo-analyzer-public.php`: `error_log('Received data: ' . print_r($_POST, true));`, `error_log('Full results: ' . print_r($results, true));`, `error_log('Competitor results: ' . print_r($competitor_results, true));`
- `includes/class-seo-analyzer-mailer.php`: `error_log('SEO Analyzer Brevo API Request: ' . print_r($body, true));`
- `admin/class-seo-analyzer-admin.php`: `error_log('Validating PageSpeed API Key: ' . $new_input);` and `error_log('PageSpeed API Key validation successful. Saving: ' . $new_input);`

In `admin/class-seo-analyzer-admin.php` replace the `enqueue_scripts` method with:

```php
    public function enqueue_scripts() {
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/seo-analyzer-admin.js', array('jquery'), $this->version, false);
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run the integration command with `--filter Halo_Hotfix_Test`.
Expected: `OK (4 tests, ...)`.

Then confirm nothing sensitive is still logged:

```bash
cd "$REPO"/SEO-plugin
grep -nE "print_r\(\\\$_POST|API Key:|Brevo API Request" -r admin public includes --include=*.php | grep -v tcpdf
```

Expected: no output.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "fix: require login and nonce for report actions, remove open endpoints"
```

---

### Task 3: Core types and the scorer

**Files:**
- Create: `includes/engine/Result.php`, `Check.php`, `BaseCheck.php`, `Scorer.php`
- Test: `tests/unit/ScorerTest.php`, `tests/unit/BaseCheckTest.php`

**Interfaces:**
- Produces:
  - `Result::pass(string $evidence = ''): Result`, `::warn()`, `::fail()`, `::notMeasured()`; public `string $status`, `string $evidence`; constants `Result::PASS|WARN|FAIL|NOT_MEASURED`.
  - `interface Check { id(): string; group(): string; severity(): int; title(): string; why(): string; fix(string $status): string; run(Context $c): Result; }`
  - `abstract class BaseCheck implements Check` driven by class constants `ID, GROUP, SEVERITY, TITLE, WHY, FIX_WARN, FIX_FAIL`.
  - `Scorer::score(array $rows): array` where each row has `group`, `severity`, `status`. Returns `['groups' => [group => ['score' => ?int, 'pass' => int, 'warn' => int, 'fail' => int, 'not_measured' => int]], 'overall' => ?int, 'grade' => ?string]`.
  - `Scorer::WEIGHTS`, `Scorer::LABELS`, `Scorer::grade(?int): ?string`.

- [ ] **Step 1: Write the failing tests**

`tests/unit/ScorerTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\Scorer;
use PHPUnit\Framework\TestCase;

final class ScorerTest extends TestCase
{
    private function row(string $group, int $sev, string $status): array
    {
        return ['group' => $group, 'severity' => $sev, 'status' => $status];
    }

    public function test_group_score_is_severity_weighted(): void
    {
        $out = Scorer::score([
            $this->row('onpage', 3, 'pass'),
            $this->row('onpage', 1, 'fail'),
        ]);
        $this->assertSame(75, $out['groups']['onpage']['score']);
        $this->assertSame(1, $out['groups']['onpage']['pass']);
        $this->assertSame(1, $out['groups']['onpage']['fail']);
    }

    public function test_warn_is_worth_half(): void
    {
        $out = Scorer::score([$this->row('ai', 2, 'warn')]);
        $this->assertSame(50, $out['groups']['ai']['score']);
    }

    public function test_not_measured_is_left_out_of_the_mean(): void
    {
        $out = Scorer::score([
            $this->row('technical', 3, 'pass'),
            $this->row('technical', 3, 'not_measured'),
        ]);
        $this->assertSame(100, $out['groups']['technical']['score']);
        $this->assertSame(1, $out['groups']['technical']['not_measured']);
    }

    public function test_unmeasured_group_is_null_and_overall_renormalises(): void
    {
        $out = Scorer::score([
            $this->row('onpage', 1, 'pass'),     // 100, weight 30
            $this->row('technical', 1, 'fail'),  // 0, weight 20
            $this->row('speed', 3, 'not_measured'),
        ]);
        $this->assertNull($out['groups']['speed']['score']);
        $this->assertNull($out['groups']['ai']['score']);
        $this->assertSame(60, $out['overall']); // 100*30 / (30+20)
    }

    public function test_education_rows_never_change_the_score(): void
    {
        $base = [$this->row('onpage', 1, 'pass')];
        $with = array_merge($base, [$this->row('education', 3, 'fail')]);
        $this->assertSame(Scorer::score($base)['overall'], Scorer::score($with)['overall']);
        $this->assertArrayNotHasKey('education', Scorer::score($with)['groups']);
    }

    public function test_nothing_measured_gives_no_score_and_no_grade(): void
    {
        $out = Scorer::score([$this->row('onpage', 1, 'not_measured')]);
        $this->assertNull($out['overall']);
        $this->assertNull($out['grade']);
    }

    /** @dataProvider grades */
    public function test_grade_bands(?int $score, ?string $grade): void
    {
        $this->assertSame($grade, Scorer::grade($score));
    }

    public function grades(): array
    {
        return [[100, 'A'], [90, 'A'], [89, 'B'], [70, 'B'], [69, 'C'], [50, 'C'], [49, 'D'], [30, 'D'], [29, 'F'], [0, 'F'], [null, null]];
    }
}
```

`tests/unit/BaseCheckTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use PHPUnit\Framework\TestCase;

final class BaseCheckTest extends TestCase
{
    public function test_metadata_and_fix_text_come_from_constants(): void
    {
        $check = new class extends BaseCheck {
            public const ID = 'demo';
            public const GROUP = 'onpage';
            public const SEVERITY = 2;
            public const TITLE = 'Demo';
            public const WHY = 'Because.';
            public const FIX_WARN = 'Tidy it.';
            public const FIX_FAIL = 'Add it.';
            public function run(Context $c): Result { return Result::pass('ok'); }
        };

        $this->assertSame('demo', $check->id());
        $this->assertSame('onpage', $check->group());
        $this->assertSame(2, $check->severity());
        $this->assertSame('Tidy it.', $check->fix(Result::WARN));
        $this->assertSame('Add it.', $check->fix(Result::FAIL));
        $this->assertSame('', $check->fix(Result::PASS));
        $this->assertSame('', $check->fix(Result::NOT_MEASURED));
    }

    public function test_warn_falls_back_to_fail_text(): void
    {
        $check = new class extends BaseCheck {
            public const ID = 'demo2';
            public const FIX_FAIL = 'Add it.';
            public function run(Context $c): Result { return Result::warn(); }
        };
        $this->assertSame('Add it.', $check->fix(Result::WARN));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'ScorerTest|BaseCheckTest'`
Expected: errors, `Class "HaloAudit\Scorer" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/Result.php`:

```php
<?php
namespace HaloAudit;

final class Result
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const NOT_MEASURED = 'not_measured';

    public string $status;
    public string $evidence;

    private function __construct(string $status, string $evidence)
    {
        $this->status = $status;
        $this->evidence = $evidence;
    }

    public static function pass(string $evidence = ''): self { return new self(self::PASS, $evidence); }
    public static function warn(string $evidence = ''): self { return new self(self::WARN, $evidence); }
    public static function fail(string $evidence = ''): self { return new self(self::FAIL, $evidence); }
    public static function notMeasured(string $evidence = ''): self { return new self(self::NOT_MEASURED, $evidence); }
}
```

`includes/engine/Check.php`:

```php
<?php
namespace HaloAudit;

interface Check
{
    public function id(): string;
    public function group(): string;
    public function severity(): int;
    public function title(): string;
    public function why(): string;
    public function fix(string $status): string;
    public function run(Context $c): Result;
}
```

`includes/engine/BaseCheck.php`:

```php
<?php
namespace HaloAudit;

abstract class BaseCheck implements Check
{
    public const ID = '';
    public const GROUP = '';
    public const SEVERITY = 1;
    public const TITLE = '';
    public const WHY = '';
    public const FIX_WARN = '';
    public const FIX_FAIL = '';

    public function id(): string { return static::ID; }
    public function group(): string { return static::GROUP; }
    public function severity(): int { return static::SEVERITY; }
    public function title(): string { return static::TITLE; }
    public function why(): string { return static::WHY; }

    public function fix(string $status): string
    {
        if ($status === Result::FAIL) {
            return static::FIX_FAIL;
        }
        if ($status === Result::WARN) {
            return static::FIX_WARN !== '' ? static::FIX_WARN : static::FIX_FAIL;
        }
        return '';
    }
}
```

`includes/engine/Scorer.php`:

```php
<?php
namespace HaloAudit;

final class Scorer
{
    public const WEIGHTS = ['onpage' => 30, 'technical' => 20, 'ai' => 30, 'speed' => 20];

    public const LABELS = [
        'onpage' => 'On-page for your keyword',
        'technical' => 'Technical',
        'ai' => 'AI readiness',
        'speed' => 'Speed',
        'education' => 'Education extras',
    ];

    private const POINTS = [Result::PASS => 100, Result::WARN => 50, Result::FAIL => 0];

    /** @param array<int,array{group:string,severity:int,status:string}> $rows */
    public static function score(array $rows): array
    {
        $groups = [];
        foreach (array_keys(self::WEIGHTS) as $g) {
            $groups[$g] = ['score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 0];
        }

        $sum = [];
        $weight = [];
        foreach ($rows as $row) {
            $g = $row['group'];
            if (!isset($groups[$g])) {
                continue;
            }
            $groups[$g][$row['status']]++;
            if ($row['status'] === Result::NOT_MEASURED) {
                continue;
            }
            $sev = (int) $row['severity'];
            $sum[$g] = ($sum[$g] ?? 0) + self::POINTS[$row['status']] * $sev;
            $weight[$g] = ($weight[$g] ?? 0) + $sev;
        }

        $total = 0;
        $totalWeight = 0;
        foreach (self::WEIGHTS as $g => $w) {
            if (empty($weight[$g])) {
                continue;
            }
            $groups[$g]['score'] = (int) round($sum[$g] / $weight[$g]);
            $total += $groups[$g]['score'] * $w;
            $totalWeight += $w;
        }

        $overall = $totalWeight > 0 ? (int) round($total / $totalWeight) : null;

        return ['groups' => $groups, 'overall' => $overall, 'grade' => self::grade($overall)];
    }

    public static function grade(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }
        if ($score >= 90) { return 'A'; }
        if ($score >= 70) { return 'B'; }
        if ($score >= 50) { return 'C'; }
        if ($score >= 30) { return 'D'; }
        return 'F';
    }
}
```

`BaseCheckTest` names `HaloAudit\Context`, which arrives in Task 7. Add a temporary empty class now so the test loads; Task 7 replaces it.

`includes/engine/Context.php`:

```php
<?php
namespace HaloAudit;

final class Context
{
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'ScorerTest|BaseCheckTest'`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add result, check and scorer core types"
```

---

### Task 4: `Text`, `Url` and `PageFacts`

**Files:**
- Create: `includes/engine/Text.php`, `Url.php`, `PageFacts.php`
- Test: `tests/unit/UrlTest.php`, `tests/unit/PageFactsTest.php`

**Interfaces:**
- Produces:
  - `Text::has(string $haystack, string $needle): bool` (case-insensitive, Unicode-safe, whitespace-normalised; empty needle is false). `Text::words(string $text): int`.
  - `Url::normalise(string $input): string`, `Url::host(string $url): string`, `Url::origin(string $url): string`, `Url::path(string $url): string`, `Url::sameSite(string $a, string $b): bool`, `Url::sameUrl(string $a, string $b): bool`, `Url::resolve(string $base, string $rel): string`.
  - `PageFacts::fromHtml(string $html, string $url): PageFacts` with methods: `url(): string`, `html(): string`, `title(): ?string`, `meta(string $name): ?string`, `property(string $property): ?string`, `headings(): array` (list of `['level' => int, 'text' => string]` in document order), `canonical(): ?string` (absolute), `links(): array` (list of `['href' => string, 'text' => string, 'internal' => bool]`), `images(): array` (list of `['src' => string, 'has_alt' => bool]`), `jsonLd(): array` (`['blocks' => int, 'invalid' => int, 'types' => string[]]`), `visibleText(): string`, `wordCount(): int`, `has(string $tag): bool`, `count(string $tag): int`, `feedLinks(): array` (string[]).

- [ ] **Step 1: Write the failing tests**

`tests/unit/UrlTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    /** @dataProvider normalised */
    public function test_normalise(string $in, string $out): void
    {
        $this->assertSame($out, Url::normalise($in));
    }

    public function normalised(): array
    {
        return [
            ['  Example.com/Page ', 'https://example.com/Page'],
            ['example.com', 'https://example.com'],
            ['http://example.com/a b', 'http://example.com/a%20b'],
            ['HTTPS://EXAMPLE.COM/x', 'https://example.com/x'],
            ['', ''],
        ];
    }

    /** @dataProvider resolved */
    public function test_resolve(string $base, string $rel, string $out): void
    {
        $this->assertSame($out, Url::resolve($base, $rel));
    }

    public function resolved(): array
    {
        $b = 'https://example.com/a/b/page.html?x=1';
        return [
            [$b, 'https://other.org/z', 'https://other.org/z'],
            [$b, '//cdn.example.com/i.png', 'https://cdn.example.com/i.png'],
            [$b, '/root', 'https://example.com/root'],
            [$b, 'next', 'https://example.com/a/b/next'],
            [$b, '../up', 'https://example.com/a/up'],
            [$b, './same?q=2', 'https://example.com/a/b/same?q=2'],
            [$b, '?only=query', 'https://example.com/a/b/page.html?only=query'],
            ['https://example.com', 'x', 'https://example.com/x'],
        ];
    }

    public function test_same_site_ignores_www_and_case(): void
    {
        $this->assertTrue(Url::sameSite('https://WWW.example.com/a', 'http://example.com/b'));
        $this->assertFalse(Url::sameSite('https://blog.example.com/', 'https://example.com/'));
    }

    public function test_same_url_ignores_trailing_slash_and_host_case(): void
    {
        $this->assertTrue(Url::sameUrl('https://Example.com/a/', 'https://example.com/a'));
        $this->assertFalse(Url::sameUrl('https://example.com/a', 'https://example.com/b'));
        $this->assertFalse(Url::sameUrl('https://example.com/a?x=1', 'https://example.com/a'));
    }

    public function test_origin_and_path(): void
    {
        $this->assertSame('https://example.com', Url::origin('https://Example.com/a?b'));
        $this->assertSame('/', Url::path('https://example.com'));
        $this->assertSame('/a', Url::path('https://example.com/a?b'));
    }
}
```

`tests/unit/PageFactsTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\PageFacts;
use HaloAudit\Text;
use PHPUnit\Framework\TestCase;

final class PageFactsTest extends TestCase
{
    private const URL = 'https://example.com/courses/mba/';

    private function page(string $head, string $body): PageFacts
    {
        return PageFacts::fromHtml("<!doctype html><html lang=\"en\"><head>$head</head><body>$body</body></html>", self::URL);
    }

    public function test_title_with_attributes_and_newlines(): void
    {
        $p = $this->page("<title data-x=\"1\">\n  MBA   Programme \n</title>", '');
        $this->assertSame('MBA Programme', $p->title());
    }

    public function test_missing_title_is_null(): void
    {
        $this->assertNull($this->page('', '<svg><title>icon</title></svg>')->title());
    }

    public function test_meta_and_property_are_case_insensitive(): void
    {
        $p = $this->page('<meta NAME="Description" content=" Hello "><meta property="og:title" content="OG">', '');
        $this->assertSame('Hello', $p->meta('description'));
        $this->assertSame('OG', $p->property('og:title'));
        $this->assertNull($p->meta('robots'));
    }

    public function test_headings_are_in_document_order(): void
    {
        $p = $this->page('', '<h2>B</h2><h1>A</h1><h4>D</h4>');
        $this->assertSame([2, 1, 4], array_column($p->headings(), 'level'));
        $this->assertSame('A', $p->headings()[1]['text']);
    }

    public function test_canonical_is_made_absolute(): void
    {
        $p = $this->page('<link rel="canonical" href="/courses/mba/">', '');
        $this->assertSame('https://example.com/courses/mba/', $p->canonical());
    }

    public function test_links_skip_non_pages_and_flag_internal(): void
    {
        $p = $this->page('', '<a href="#top">t</a><a href="mailto:a@b.c">m</a><a href="javascript:void(0)">j</a>'
            . '<a href="/apply">Apply now</a><a href="https://www.example.com/x">X</a><a href="https://other.org/">O</a>');
        $links = $p->links();
        $this->assertCount(3, $links);
        $this->assertSame('https://example.com/apply', $links[0]['href']);
        $this->assertSame('Apply now', $links[0]['text']);
        $this->assertTrue($links[1]['internal']);
        $this->assertFalse($links[2]['internal']);
    }

    public function test_images_report_alt_attribute_presence(): void
    {
        $p = $this->page('', '<img src="a.png" alt=""><img src="b.png">');
        $this->assertSame([true, false], array_column($p->images(), 'has_alt'));
    }

    public function test_json_ld_types_expand_graph_and_count_invalid(): void
    {
        $p = $this->page(
            '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization"},{"@type":["WebPage","FAQPage"]}]}</script>'
            . '<script type="application/ld+json">{not json}</script>',
            ''
        );
        $ld = $p->jsonLd();
        $this->assertSame(2, $ld['blocks']);
        $this->assertSame(1, $ld['invalid']);
        $this->assertEqualsCanonicalizing(['Organization', 'WebPage', 'FAQPage'], $ld['types']);
    }

    public function test_visible_text_drops_script_and_style_and_keeps_word_breaks(): void
    {
        $p = $this->page('', '<p>One</p><p>two</p><script>var hidden = 1;</script><style>.x{}</style><noscript>nope</noscript>');
        $this->assertSame('One two', $p->visibleText());
        $this->assertSame(2, $p->wordCount());
    }

    public function test_non_latin_text_is_counted(): void
    {
        $p = $this->page('<title>प्रवेश</title>', '<p>विश्वविद्यालय प्रवेश सूचना</p>');
        $this->assertSame(3, $p->wordCount());
        $this->assertTrue(Text::has($p->visibleText(), 'प्रवेश'));
    }

    public function test_windows_1252_page_is_parsed(): void
    {
        $html = "<html><head><title>Caf\xE9 MBA</title></head><body><p>Fees are \xA35000</p></body></html>";
        $p = PageFacts::fromHtml($html, self::URL);
        $this->assertSame('Café MBA', $p->title());
        $this->assertSame(3, $p->wordCount());
    }

    public function test_empty_document_does_not_error(): void
    {
        $p = PageFacts::fromHtml('', self::URL);
        $this->assertNull($p->title());
        $this->assertSame(0, $p->wordCount());
        $this->assertSame([], $p->links());
    }

    public function test_has_count_and_feed_links(): void
    {
        $p = $this->page('<link rel="alternate" type="application/rss+xml" href="/feed/">', '<main><details></details><details></details></main>');
        $this->assertTrue($p->has('main'));
        $this->assertFalse($p->has('article'));
        $this->assertSame(2, $p->count('details'));
        $this->assertSame(['https://example.com/feed/'], $p->feedLinks());
    }

    public function test_text_has_is_case_insensitive_and_rejects_empty(): void
    {
        $this->assertTrue(Text::has("Best  MBA\nProgramme", 'mba programme'));
        $this->assertFalse(Text::has('anything', '  '));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'UrlTest|PageFactsTest'`
Expected: errors, `Class "HaloAudit\Url" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/Text.php`:

```php
<?php
namespace HaloAudit;

final class Text
{
    public static function has(string $haystack, string $needle): bool
    {
        $needle = trim((string) preg_replace('/\s+/u', ' ', $needle));
        if ($needle === '') {
            return false;
        }
        $haystack = (string) preg_replace('/\s+/u', ' ', $haystack);
        return mb_stripos($haystack, $needle) !== false;
    }

    public static function words(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\'’-]*/u', $text);
    }
}
```

`includes/engine/Url.php`:

```php
<?php
namespace HaloAudit;

final class Url
{
    public static function normalise(string $input): string
    {
        $url = trim($input);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }
        $out = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        $out .= str_replace(' ', '%20', $parts['path'] ?? '');
        if (isset($parts['query'])) {
            $out .= '?' . str_replace(' ', '%20', $parts['query']);
        }
        return $out;
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    public static function origin(string $url): string
    {
        $p = parse_url($url) ?: [];
        $out = strtolower($p['scheme'] ?? 'https') . '://' . strtolower($p['host'] ?? '');
        return isset($p['port']) ? $out . ':' . $p['port'] : $out;
    }

    public static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function sameSite(string $a, string $b): bool
    {
        $strip = static fn(string $u): string => (string) preg_replace('/^www\./', '', self::host($u));
        return $strip($a) !== '' && $strip($a) === $strip($b);
    }

    public static function sameUrl(string $a, string $b): bool
    {
        $key = static function (string $u): string {
            $p = parse_url($u) ?: [];
            return strtolower($p['scheme'] ?? '') . '://' . strtolower($p['host'] ?? '')
                . rtrim($p['path'] ?? '', '/') . '?' . ($p['query'] ?? '');
        };
        return $key($a) === $key($b);
    }

    public static function resolve(string $base, string $rel): string
    {
        $rel = trim($rel);
        if ($rel === '') {
            return $base;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $rel)) {
            return $rel;
        }
        $b = parse_url($base) ?: [];
        $scheme = strtolower($b['scheme'] ?? 'https');
        $authority = strtolower($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        $basePath = isset($b['path']) && $b['path'] !== '' ? $b['path'] : '/';

        if (strpos($rel, '//') === 0) {
            return $scheme . ':' . $rel;
        }
        if ($rel[0] === '?') {
            return $scheme . '://' . $authority . $basePath . $rel;
        }
        if ($rel[0] === '#') {
            return (string) strtok($base, '#') . $rel;
        }

        $path = $rel[0] === '/' ? $rel : preg_replace('#/[^/]*$#', '/', $basePath) . $rel;

        $suffix = '';
        if (preg_match('/[?#].*$/', $path, $m)) {
            $suffix = $m[0];
            $path = substr($path, 0, -strlen($suffix));
        }

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }

        return $scheme . '://' . $authority . implode('/', $out) . $suffix;
    }
}
```

`includes/engine/PageFacts.php`:

```php
<?php
namespace HaloAudit;

final class PageFacts
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private \DOMDocument $dom;
    private \DOMXPath $xp;
    private string $html;
    private string $url;
    private ?string $text = null;
    private ?array $jsonLd = null;

    private function __construct()
    {
    }

    public static function fromHtml(string $html, string $url): self
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }

        $self = new self();
        $self->html = $html;
        $self->url = $url;
        $self->dom = new \DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $self->dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $self->xp = new \DOMXPath($self->dom);
        return $self;
    }

    public function url(): string { return $this->url; }
    public function html(): string { return $this->html; }

    public function title(): ?string
    {
        $node = $this->xp->query('//head/title')->item(0);
        if ($node === null) {
            return null;
        }
        $title = self::squash($node->textContent);
        return $title === '' ? null : $title;
    }

    public function meta(string $name): ?string
    {
        return $this->attr(sprintf('//meta[translate(@name,"%s","%s")="%s"]/@content', self::UPPER, self::LOWER, strtolower($name)));
    }

    public function property(string $property): ?string
    {
        return $this->attr(sprintf('//meta[translate(@property,"%s","%s")="%s"]/@content', self::UPPER, self::LOWER, strtolower($property)));
    }

    /** @return array<int,array{level:int,text:string}> */
    public function headings(): array
    {
        $out = [];
        foreach ($this->xp->query('//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
            $out[] = ['level' => (int) substr($node->nodeName, 1), 'text' => self::squash($node->textContent)];
        }
        return $out;
    }

    public function canonical(): ?string
    {
        $href = $this->attr(sprintf('//link[translate(@rel,"%s","%s")="canonical"]/@href', self::UPPER, self::LOWER));
        return $href === null ? null : Url::resolve($this->url, $href);
    }

    /** @return array<int,array{href:string,text:string,internal:bool}> */
    public function links(): array
    {
        $out = [];
        foreach ($this->xp->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || preg_match('/^(mailto|tel|javascript|data|sms|whatsapp):/i', $href)) {
                continue;
            }
            $abs = Url::resolve($this->url, $href);
            if (!preg_match('#^https?://#i', $abs)) {
                continue;
            }
            $out[] = ['href' => $abs, 'text' => self::squash($a->textContent), 'internal' => Url::sameSite($abs, $this->url)];
        }
        return $out;
    }

    /** @return array<int,array{src:string,has_alt:bool}> */
    public function images(): array
    {
        $out = [];
        foreach ($this->xp->query('//img') as $img) {
            $out[] = ['src' => $img->getAttribute('src'), 'has_alt' => $img->hasAttribute('alt')];
        }
        return $out;
    }

    /** @return array{blocks:int,invalid:int,types:string[]} */
    public function jsonLd(): array
    {
        if ($this->jsonLd !== null) {
            return $this->jsonLd;
        }
        $blocks = 0;
        $invalid = 0;
        $types = [];
        $query = sprintf('//script[translate(@type,"%s","%s")="application/ld+json"]', self::UPPER, self::LOWER);
        foreach ($this->xp->query($query) as $script) {
            $blocks++;
            $data = json_decode(trim($script->textContent), true);
            if (!is_array($data)) {
                $invalid++;
                continue;
            }
            self::collectTypes($data, $types);
        }
        return $this->jsonLd = ['blocks' => $blocks, 'invalid' => $invalid, 'types' => array_values(array_unique($types))];
    }

    public function visibleText(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }
        $parts = [];
        $query = '//body//text()[not(ancestor::script or ancestor::style or ancestor::noscript or ancestor::template or ancestor::svg)]';
        foreach ($this->xp->query($query) as $node) {
            $parts[] = $node->nodeValue;
        }
        return $this->text = self::squash(implode(' ', $parts));
    }

    public function wordCount(): int
    {
        return Text::words($this->visibleText());
    }

    public function has(string $tag): bool
    {
        return $this->count($tag) > 0;
    }

    public function count(string $tag): int
    {
        return $this->dom->getElementsByTagName($tag)->length;
    }

    /** @return string[] */
    public function feedLinks(): array
    {
        $out = [];
        foreach ($this->xp->query('//link[@href]') as $link) {
            $type = strtolower($link->getAttribute('type'));
            if (strtolower($link->getAttribute('rel')) === 'alternate' && ($type === 'application/rss+xml' || $type === 'application/atom+xml')) {
                $out[] = Url::resolve($this->url, $link->getAttribute('href'));
            }
        }
        return $out;
    }

    private function attr(string $query): ?string
    {
        $node = $this->xp->query($query)->item(0);
        if ($node === null) {
            return null;
        }
        $value = trim($node->nodeValue);
        return $value === '' ? null : $value;
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function collectTypes(array $data, array &$types): void
    {
        if (isset($data['@type'])) {
            foreach ((array) $data['@type'] as $type) {
                if (is_string($type)) {
                    $types[] = $type;
                }
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                self::collectTypes($value, $types);
            }
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'UrlTest|PageFactsTest'`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add URL helpers and HTML page facts parser"
```

---

### Task 5: `robots.txt` parser

**Files:**
- Create: `includes/engine/RobotsTxt.php`
- Test: `tests/unit/RobotsTxtTest.php`

**Interfaces:**
- Produces: `RobotsTxt::parse(string $body): RobotsTxt`; `isAllowed(string $agent, string $path): bool`; `sitemaps(): string[]`.

- [ ] **Step 1: Write the failing test**

`tests/unit/RobotsTxtTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\RobotsTxt;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    public function test_empty_file_allows_everything(): void
    {
        $this->assertTrue(RobotsTxt::parse('')->isAllowed('GPTBot', '/'));
    }

    public function test_file_without_star_group_allows_unnamed_agents(): void
    {
        $r = RobotsTxt::parse("User-agent: Bingbot\nDisallow: /");
        $this->assertTrue($r->isAllowed('GPTBot', '/page'));
        $this->assertFalse($r->isAllowed('bingbot', '/page'));
    }

    public function test_agent_match_is_case_insensitive(): void
    {
        $r = RobotsTxt::parse("user-agent: gptbot\ndisallow: /");
        $this->assertFalse($r->isAllowed('GPTBot', '/'));
    }

    public function test_grouped_user_agents_share_rules(): void
    {
        $r = RobotsTxt::parse("User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /private/\n\nUser-agent: *\nDisallow:");
        $this->assertFalse($r->isAllowed('ClaudeBot', '/private/x'));
        $this->assertTrue($r->isAllowed('ClaudeBot', '/public'));
        $this->assertTrue($r->isAllowed('CCBot', '/private/x'));
    }

    public function test_named_group_wins_over_star(): void
    {
        $r = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /");
        $this->assertTrue($r->isAllowed('GPTBot', '/a'));
        $this->assertFalse($r->isAllowed('PerplexityBot', '/a'));
    }

    public function test_longest_match_wins_and_allow_wins_ties(): void
    {
        $r = RobotsTxt::parse("User-agent: *\nDisallow: /courses/\nAllow: /courses/mba/\nDisallow: /tie\nAllow: /tie");
        $this->assertTrue($r->isAllowed('GPTBot', '/courses/mba/'));
        $this->assertFalse($r->isAllowed('GPTBot', '/courses/bba/'));
        $this->assertTrue($r->isAllowed('GPTBot', '/tie'));
    }

    public function test_wildcards_and_end_anchor(): void
    {
        $r = RobotsTxt::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /search*q=");
        $this->assertFalse($r->isAllowed('GPTBot', '/files/a.pdf'));
        $this->assertTrue($r->isAllowed('GPTBot', '/files/a.pdf.html'));
        $this->assertFalse($r->isAllowed('GPTBot', '/search?x=1&q=2'));
    }

    public function test_comments_blank_lines_and_crlf(): void
    {
        $r = RobotsTxt::parse("# hello\r\nUser-agent: * # everyone\r\n\r\nDisallow: /x # no\r\n");
        $this->assertFalse($r->isAllowed('GPTBot', '/x'));
    }

    public function test_sitemaps_are_collected(): void
    {
        $r = RobotsTxt::parse("Sitemap: https://example.com/sitemap.xml\nUser-agent: *\nDisallow:\nsitemap: https://example.com/news.xml");
        $this->assertSame(['https://example.com/sitemap.xml', 'https://example.com/news.xml'], $r->sitemaps());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RobotsTxtTest`
Expected: error, `Class "HaloAudit\RobotsTxt" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/RobotsTxt.php`:

```php
<?php
namespace HaloAudit;

/** RFC 9309 group matching: named group beats "*", longest pattern wins, Allow wins ties. */
final class RobotsTxt
{
    /** @var array<int,array{agents:string[],rules:array<int,array{allow:bool,pattern:string}>}> */
    private array $groups = [];
    /** @var string[] */
    private array $sitemaps = [];

    public static function parse(string $body): self
    {
        $self = new self();
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);

            if ($key === 'sitemap') {
                $self->sitemaps[] = $value;
                continue;
            }
            if ($key === 'user-agent') {
                if (!$lastWasAgent || $current === null) {
                    $self->groups[] = ['agents' => [], 'rules' => []];
                    $current = count($self->groups) - 1;
                }
                $self->groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            if (($key === 'allow' || $key === 'disallow') && $current !== null) {
                $self->groups[$current]['rules'][] = ['allow' => $key === 'allow', 'pattern' => $value];
            }
            $lastWasAgent = false;
        }

        return $self;
    }

    public function isAllowed(string $agent, string $path): bool
    {
        $rules = $this->rulesFor(strtolower($agent));
        if ($rules === null) {
            return true;
        }

        $best = null;
        foreach ($rules as $rule) {
            if ($rule['pattern'] === '' || !self::matches($rule['pattern'], $path)) {
                continue;
            }
            $length = strlen($rule['pattern']);
            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null ? true : $best['allow'];
    }

    /** @return string[] */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    private function rulesFor(string $agent): ?array
    {
        $named = null;
        $star = null;
        foreach ($this->groups as $group) {
            if (in_array($agent, $group['agents'], true)) {
                $named = array_merge($named ?? [], $group['rules']);
            }
            if (in_array('*', $group['agents'], true)) {
                $star = array_merge($star ?? [], $group['rules']);
            }
        }
        return $named ?? $star;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = substr($pattern, -1) === '$';
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';
        return (bool) preg_match($regex, $path);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter RobotsTxtTest`
Expected: `OK (9 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add RFC 9309 robots.txt parser"
```

---

### Task 6: `Response`, `HttpClient`, `UrlGuard` and `Fetcher`

**Files:**
- Create: `includes/engine/Response.php`, `HttpClient.php`, `UrlGuard.php`, `Fetcher.php`
- Create: `tests/unit/Support/FakeHttp.php`
- Test: `tests/unit/UrlGuardTest.php`, `tests/unit/FetcherTest.php`

**Interfaces:**
- Produces:
  - `Response::make(int $status, string $body = '', array $headers = []): Response`, `Response::error(string $message): Response`; public `int $status`, `array $headers` (lower-case keys), `string $body`, `string $finalUrl`, `array $chain` (list of `['status' => int, 'url' => string]`), `?string $error`; `header(string $name): ?string`; `ok(): bool` (no error and 2xx).
  - `interface HttpClient { request(string $method, string $url, int $timeout, int $maxBytes, array $headers = []): Response; }` One hop only. It must not follow redirects.
  - `UrlGuard::__construct(?callable $resolver = null)` where the resolver maps a host to a list of IP strings; `reject(string $url): ?string` returns a reason, or `null` when the URL is safe.
  - `Fetcher::__construct(HttpClient $http, UrlGuard $guard)`; `get(string $url, int $timeout = 5, int $maxBytes = 2000000): Response`; `setDeadline(float $unixTime): void`; constant `Fetcher::MAX_REDIRECTS = 3`.
  - Test double `HaloAudit\Tests\Support\FakeHttp` with public `array $routes` (url → `Response`) and `array $calls`.

- [ ] **Step 1: Write the test double and the failing tests**

`tests/unit/Support/FakeHttp.php`:

```php
<?php
namespace HaloAudit\Tests\Support;

use HaloAudit\HttpClient;
use HaloAudit\Response;

final class FakeHttp implements HttpClient
{
    /** @var array<string,Response> */
    public array $routes = [];
    /** @var string[] */
    public array $calls = [];

    public function request(string $method, string $url, int $timeout, int $maxBytes, array $headers = []): Response
    {
        $this->calls[] = $url;
        return isset($this->routes[$url]) ? clone $this->routes[$url] : Response::error('no route for ' . $url);
    }
}
```

`tests/unit/UrlGuardTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    private function guard(array $map = []): UrlGuard
    {
        return new UrlGuard(static fn(string $host): array => $map[$host] ?? []);
    }

    /** @dataProvider rejected */
    public function test_unsafe_urls_are_rejected(string $url, array $dns = []): void
    {
        $this->assertNotNull($this->guard($dns)->reject($url), $url);
    }

    public function rejected(): array
    {
        return [
            'loopback ip' => ['http://127.0.0.1/'],
            'private 10' => ['http://10.1.2.3/'],
            'private 192' => ['http://192.168.1.1/'],
            'private 172' => ['http://172.16.0.1/'],
            'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'cgnat' => ['http://100.64.0.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'localhost' => ['http://localhost/'],
            'dot local' => ['http://printer.local/'],
            'dot test' => ['http://site.test/'],
            'ftp scheme' => ['ftp://example.com/'],
            'file scheme' => ['file:///etc/passwd'],
            'odd port' => ['http://example.com:8080/', ['example.com' => ['93.184.216.34']]],
            'credentials' => ['http://user:pass@example.com/', ['example.com' => ['93.184.216.34']]],
            'unresolvable' => ['http://nope.invalid/'],
            'public name, private ip' => ['http://rebind.example/', ['rebind.example' => ['10.0.0.5']]],
            'one of two ips private' => ['http://mixed.example/', ['mixed.example' => ['93.184.216.34', '127.0.0.1']]],
            'no host' => ['http:///path'],
        ];
    }

    public function test_public_urls_pass(): void
    {
        $guard = $this->guard(['example.com' => ['93.184.216.34']]);
        $this->assertNull($guard->reject('https://example.com/a?b=1'));
        $this->assertNull($guard->reject('http://example.com:80/'));
        $this->assertNull($guard->reject('https://93.184.216.34/'));
    }
}
```

`tests/unit/FetcherTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\Fetcher;
use HaloAudit\Response;
use HaloAudit\Tests\Support\FakeHttp;
use HaloAudit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class FetcherTest extends TestCase
{
    private FakeHttp $http;
    private Fetcher $fetcher;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $guard = new UrlGuard(static fn(string $host): array => $host === 'evil.example' ? ['10.0.0.1'] : ['93.184.216.34']);
        $this->fetcher = new Fetcher($this->http, $guard);
    }

    public function test_returns_body_and_final_url(): void
    {
        $this->http->routes['https://example.com/'] = Response::make(200, 'hi', ['Content-Type' => 'text/html']);
        $r = $this->fetcher->get('https://example.com/');
        $this->assertTrue($r->ok());
        $this->assertSame('hi', $r->body);
        $this->assertSame('https://example.com/', $r->finalUrl);
        $this->assertSame('text/html', $r->header('content-type'));
        $this->assertSame([], $r->chain);
    }

    public function test_each_url_is_fetched_once(): void
    {
        $this->http->routes['https://example.com/'] = Response::make(200, 'hi');
        $this->fetcher->get('https://example.com/');
        $this->fetcher->get('https://example.com/');
        $this->assertCount(1, $this->http->calls);
    }

    public function test_redirects_are_followed_and_recorded(): void
    {
        $this->http->routes['http://example.com/'] = Response::make(301, '', ['Location' => 'https://example.com/']);
        $this->http->routes['https://example.com/'] = Response::make(302, '', ['Location' => '/home']);
        $this->http->routes['https://example.com/home'] = Response::make(200, 'home');
        $r = $this->fetcher->get('http://example.com/');
        $this->assertSame('home', $r->body);
        $this->assertSame('https://example.com/home', $r->finalUrl);
        $this->assertSame([301, 302], array_column($r->chain, 'status'));
    }

    public function test_more_than_three_redirects_is_an_error(): void
    {
        foreach ([1, 2, 3, 4] as $i) {
            $this->http->routes["https://example.com/$i"] = Response::make(301, '', ['Location' => '/' . ($i + 1)]);
        }
        $r = $this->fetcher->get('https://example.com/1');
        $this->assertSame('too many redirects', $r->error);
    }

    public function test_redirect_to_private_host_is_blocked(): void
    {
        $this->http->routes['https://example.com/'] = Response::make(302, '', ['Location' => 'http://evil.example/admin']);
        $r = $this->fetcher->get('https://example.com/');
        $this->assertStringStartsWith('blocked', (string) $r->error);
        $this->assertNotContains('http://evil.example/admin', $this->http->calls);
    }

    public function test_blocked_start_url_is_never_requested(): void
    {
        $r = $this->fetcher->get('http://127.0.0.1/');
        $this->assertStringStartsWith('blocked', (string) $r->error);
        $this->assertSame([], $this->http->calls);
    }

    public function test_transport_error_is_passed_through(): void
    {
        $r = $this->fetcher->get('https://example.com/missing-route');
        $this->assertFalse($r->ok());
        $this->assertNotNull($r->error);
    }

    public function test_deadline_stops_further_fetches(): void
    {
        $this->http->routes['https://example.com/slow'] = Response::make(200, 'x');
        $this->fetcher->setDeadline(microtime(true) - 1);
        $r = $this->fetcher->get('https://example.com/slow');
        $this->assertSame('time budget used up', $r->error);
        $this->assertSame([], $this->http->calls);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'UrlGuardTest|FetcherTest'`
Expected: errors, `Interface "HaloAudit\HttpClient" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/Response.php`:

```php
<?php
namespace HaloAudit;

final class Response
{
    public int $status = 0;
    /** @var array<string,string> lower-case header names */
    public array $headers = [];
    public string $body = '';
    public string $finalUrl = '';
    /** @var array<int,array{status:int,url:string}> */
    public array $chain = [];
    public ?string $error = null;

    public static function make(int $status, string $body = '', array $headers = []): self
    {
        $r = new self();
        $r->status = $status;
        $r->body = $body;
        foreach ($headers as $name => $value) {
            $r->headers[strtolower((string) $name)] = (string) $value;
        }
        return $r;
    }

    public static function error(string $message): self
    {
        $r = new self();
        $r->error = $message;
        return $r;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }
}
```

`includes/engine/HttpClient.php`:

```php
<?php
namespace HaloAudit;

interface HttpClient
{
    /** One hop only. Implementations must not follow redirects. */
    public function request(string $method, string $url, int $timeout, int $maxBytes, array $headers = []): Response;
}
```

`includes/engine/UrlGuard.php`:

```php
<?php
namespace HaloAudit;

final class UrlGuard
{
    /** @var callable(string):string[] */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            return $ips;
        };
    }

    public function reject(string $url): ?string
    {
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || empty($p['scheme'])) {
            return 'not a valid address';
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'only http and https are allowed';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'credentials in the address are not allowed';
        }
        if (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)) {
            return 'only ports 80 and 443 are allowed';
        }

        $host = strtolower(trim($p['host'], '[]'));
        if ($host === 'localhost' || preg_match('/\.(local|localhost|test|internal|lan|home|corp)$/', $host)) {
            return 'local host names are not allowed';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            return 'the host name does not resolve';
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return 'private or reserved addresses are not allowed';
            }
        }
        return null;
    }

    private static function isPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            // 100.64.0.0/10 carrier-grade NAT is not covered by the PHP flags.
            if (($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        }
        return true;
    }
}
```

`includes/engine/Fetcher.php`:

```php
<?php
namespace HaloAudit;

final class Fetcher
{
    public const MAX_REDIRECTS = 3;

    private HttpClient $http;
    private UrlGuard $guard;
    /** @var array<string,Response> */
    private array $cache = [];
    private ?float $deadline = null;

    public function __construct(HttpClient $http, UrlGuard $guard)
    {
        $this->http = $http;
        $this->guard = $guard;
    }

    public function setDeadline(float $unixTime): void
    {
        $this->deadline = $unixTime;
    }

    public function get(string $url, int $timeout = 5, int $maxBytes = 2000000): Response
    {
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }
        return $this->cache[$url] = $this->fetch($url, $timeout, $maxBytes);
    }

    private function fetch(string $url, int $timeout, int $maxBytes): Response
    {
        $chain = [];
        $current = $url;

        for ($hop = 0; ; $hop++) {
            if ($this->deadline !== null && microtime(true) > $this->deadline) {
                return $this->fail('time budget used up', $current, $chain);
            }
            $reason = $this->guard->reject($current);
            if ($reason !== null) {
                return $this->fail('blocked: ' . $reason, $current, $chain);
            }

            $response = $this->http->request('GET', $current, $timeout, $maxBytes);
            if ($response->error !== null) {
                $response->finalUrl = $current;
                $response->chain = $chain;
                return $response;
            }

            $location = $response->header('location');
            if (in_array($response->status, [301, 302, 303, 307, 308], true) && $location !== null && $location !== '') {
                $chain[] = ['status' => $response->status, 'url' => $current];
                if ($hop >= self::MAX_REDIRECTS) {
                    return $this->fail('too many redirects', $current, $chain);
                }
                $current = Url::resolve($current, $location);
                continue;
            }

            $response->finalUrl = $current;
            $response->chain = $chain;
            return $response;
        }
    }

    private function fail(string $message, string $url, array $chain): Response
    {
        $r = Response::error($message);
        $r->finalUrl = $url;
        $r->chain = $chain;
        return $r;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'UrlGuardTest|FetcherTest'`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add guarded fetcher with redirect and time limits"
```

---

### Task 7: `SiteFacts`, `Context`, `CertProbe` and `SiteProbe`

**Files:**
- Create: `includes/engine/SiteFacts.php`, `CertProbe.php`, `SiteProbe.php`
- Modify: `includes/engine/Context.php` (replace the Task 3 stub)
- Create: `tests/unit/Support/Make.php`, `tests/unit/Support/FixedCert.php`
- Test: `tests/unit/SiteProbeTest.php`

**Interfaces:**
- Consumes: `Fetcher::get()`, `PageFacts`, `Response`, `RobotsTxt`, `Url`.
- Produces:
  - `SiteFacts` public properties: `Response $page`; `?int $robotsStatus` (null = fetch failed); `?RobotsTxt $robots` (set only for a real 200 text file); `?int $sitemapStatus`; `bool $sitemapValid`; `?int $sitemapNewest` (unix time); `?int $llmsStatus`; `string $llmsBody`; `?int $certDaysLeft`; `?array $ogImage` (`['status' => int, 'width' => ?int, 'height' => ?int]`, null = not fetched); `bool $feedFound`; `?array $psi` (`['score' => int, 'lcp_ms' => float, 'cls' => float]`).
  - `Context::__construct(string $url, string $keyword, PageFacts $page, SiteFacts $site, int $now)` with those five public properties.
  - `interface CertProbe { daysLeft(string $host): ?int; }`
  - `SiteProbe::collect(Fetcher $fetcher, CertProbe $cert, PageFacts $page, Response $pageResponse): SiteFacts`.
  - Test helpers: `Make::page(string $head = '', string $body = '', string $url = Make::URL): PageFacts`; `Make::site(array $overrides = []): SiteFacts`; `Make::ctx(PageFacts $page, ?SiteFacts $site = null, string $keyword = 'mba', ?string $url = null): Context`; `Make::NOW`; `FixedCert`.

- [ ] **Step 1: Write the helpers and the failing test**

`tests/unit/Support/FixedCert.php`:

```php
<?php
namespace HaloAudit\Tests\Support;

use HaloAudit\CertProbe;

final class FixedCert implements CertProbe
{
    public ?int $days;
    /** @var string[] */
    public array $asked = [];

    public function __construct(?int $days)
    {
        $this->days = $days;
    }

    public function daysLeft(string $host): ?int
    {
        $this->asked[] = $host;
        return $this->days;
    }
}
```

`tests/unit/Support/Make.php`:

```php
<?php
namespace HaloAudit\Tests\Support;

use HaloAudit\Context;
use HaloAudit\PageFacts;
use HaloAudit\Response;
use HaloAudit\SiteFacts;

final class Make
{
    public const URL = 'https://example.com/courses/mba/';
    public const NOW = 1760000000; // 2025-10-09

    public static function page(string $head = '', string $body = '', string $url = self::URL): PageFacts
    {
        return PageFacts::fromHtml("<!doctype html><html lang=\"en\"><head>$head</head><body>$body</body></html>", $url);
    }

    public static function site(array $overrides = []): SiteFacts
    {
        $site = new SiteFacts();
        $site->page = Response::make(200, '', ['content-type' => 'text/html']);
        $site->page->finalUrl = self::URL;
        foreach ($overrides as $key => $value) {
            $site->$key = $value;
        }
        return $site;
    }

    public static function ctx(PageFacts $page, ?SiteFacts $site = null, string $keyword = 'mba', ?string $url = null): Context
    {
        return new Context($url ?? $page->url(), $keyword, $page, $site ?? self::site(), self::NOW);
    }

    public static function words(int $n): string
    {
        return '<p>' . implode(' ', array_fill(0, $n, 'word')) . '</p>';
    }
}
```

`tests/unit/SiteProbeTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\Fetcher;
use HaloAudit\Response;
use HaloAudit\SiteProbe;
use HaloAudit\Tests\Support\FakeHttp;
use HaloAudit\Tests\Support\FixedCert;
use HaloAudit\Tests\Support\Make;
use HaloAudit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class SiteProbeTest extends TestCase
{
    private FakeHttp $http;
    private Fetcher $fetcher;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->fetcher = new Fetcher($this->http, new UrlGuard(static fn(): array => ['93.184.216.34']));
    }

    private function collect(string $head = '', ?FixedCert $cert = null)
    {
        $pageResponse = Response::make(200, '', ['content-type' => 'text/html']);
        $pageResponse->finalUrl = Make::URL;
        return SiteProbe::collect($this->fetcher, $cert ?? new FixedCert(90), Make::page($head), $pageResponse);
    }

    public function test_robots_is_parsed_and_its_sitemap_is_used(): void
    {
        $this->http->routes['https://example.com/robots.txt'] = Response::make(200, "User-agent: GPTBot\nDisallow: /\nSitemap: https://example.com/map.xml", ['content-type' => 'text/plain']);
        $this->http->routes['https://example.com/map.xml'] = Response::make(200, '<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc><lastmod>2025-10-01</lastmod></url><url><lastmod>2025-09-01T10:00:00+00:00</lastmod></url></urlset>');
        $site = $this->collect();
        $this->assertSame(200, $site->robotsStatus);
        $this->assertFalse($site->robots->isAllowed('GPTBot', '/'));
        $this->assertTrue($site->sitemapValid);
        $this->assertSame(strtotime('2025-10-01'), $site->sitemapNewest);
    }

    public function test_html_robots_is_not_parsed_as_rules(): void
    {
        $this->http->routes['https://example.com/robots.txt'] = Response::make(200, '<!DOCTYPE html><html><body>User-agent: * Disallow: /</body></html>', ['content-type' => 'text/html']);
        $site = $this->collect();
        $this->assertSame(404, $site->robotsStatus);
        $this->assertNull($site->robots);
    }

    public function test_missing_robots_is_404_with_no_rules(): void
    {
        $this->http->routes['https://example.com/robots.txt'] = Response::make(404, 'nope');
        $site = $this->collect();
        $this->assertSame(404, $site->robotsStatus);
        $this->assertNull($site->robots);
    }

    public function test_failed_robots_fetch_is_null(): void
    {
        $site = $this->collect();
        $this->assertNull($site->robotsStatus);
    }

    public function test_sitemap_falls_back_to_standard_paths(): void
    {
        $this->http->routes['https://example.com/sitemap.xml'] = Response::make(404, '');
        $this->http->routes['https://example.com/sitemap_index.xml'] = Response::make(200, '<sitemapindex><sitemap><loc>x</loc></sitemap></sitemapindex>');
        $site = $this->collect();
        $this->assertTrue($site->sitemapValid);
        $this->assertSame(200, $site->sitemapStatus);
        $this->assertNull($site->sitemapNewest);
    }

    public function test_html_sitemap_is_not_valid(): void
    {
        $this->http->routes['https://example.com/sitemap.xml'] = Response::make(200, '<html><body>Not found</body></html>');
        $this->http->routes['https://example.com/sitemap_index.xml'] = Response::make(404, '');
        $this->http->routes['https://example.com/wp-sitemap.xml'] = Response::make(404, '');
        $site = $this->collect();
        $this->assertFalse($site->sitemapValid);
        $this->assertSame(404, $site->sitemapStatus);
    }

    public function test_llms_txt_body_is_kept(): void
    {
        $this->http->routes['https://example.com/llms.txt'] = Response::make(200, "# Example\n> About us");
        $site = $this->collect();
        $this->assertSame(200, $site->llmsStatus);
        $this->assertStringStartsWith('# Example', $site->llmsBody);
    }

    public function test_certificate_is_asked_for_the_final_host(): void
    {
        $cert = new FixedCert(12);
        $site = $this->collect('', $cert);
        $this->assertSame(12, $site->certDaysLeft);
        $this->assertSame(['example.com'], $cert->asked);
    }

    public function test_og_image_size_is_read(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->http->routes['https://example.com/og.png'] = Response::make(200, $png);
        $site = $this->collect('<meta property="og:image" content="/og.png">');
        $this->assertSame(['status' => 200, 'width' => 1, 'height' => 1], $site->ogImage);
    }

    public function test_og_image_missing_from_page_leaves_null(): void
    {
        $this->assertNull($this->collect()->ogImage);
    }

    public function test_feed_from_head_link_needs_no_fetch(): void
    {
        $site = $this->collect('<link rel="alternate" type="application/rss+xml" href="/feed/">');
        $this->assertTrue($site->feedFound);
        $this->assertNotContains('https://example.com/feed/', $this->http->calls);
    }

    public function test_feed_is_sniffed_from_known_paths(): void
    {
        $this->http->routes['https://example.com/rss.xml'] = Response::make(200, '<?xml version="1.0"?><rss version="2.0"></rss>');
        $this->assertTrue($this->collect()->feedFound);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SiteProbeTest`
Expected: errors, `Class "HaloAudit\SiteFacts" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/Context.php` (replace the stub):

```php
<?php
namespace HaloAudit;

final class Context
{
    public string $url;
    public string $keyword;
    public PageFacts $page;
    public SiteFacts $site;
    public int $now;

    public function __construct(string $url, string $keyword, PageFacts $page, SiteFacts $site, int $now)
    {
        $this->url = $url;
        $this->keyword = $keyword;
        $this->page = $page;
        $this->site = $site;
        $this->now = $now;
    }
}
```

`includes/engine/SiteFacts.php`:

```php
<?php
namespace HaloAudit;

/** Plain data about the site. A null status means the fetch itself failed. */
final class SiteFacts
{
    public Response $page;

    public ?int $robotsStatus = null;
    public ?RobotsTxt $robots = null;

    public ?int $sitemapStatus = null;
    public bool $sitemapValid = false;
    public ?int $sitemapNewest = null;

    public ?int $llmsStatus = null;
    public string $llmsBody = '';

    public ?int $certDaysLeft = null;

    /** @var array{status:int,width:?int,height:?int}|null */
    public ?array $ogImage = null;

    public bool $feedFound = false;

    /** @var array{score:int,lcp_ms:float,cls:float}|null */
    public ?array $psi = null;
}
```

`includes/engine/CertProbe.php`:

```php
<?php
namespace HaloAudit;

interface CertProbe
{
    /** Days until the TLS certificate of $host expires, or null when it cannot be read. */
    public function daysLeft(string $host): ?int;
}
```

`includes/engine/SiteProbe.php`:

```php
<?php
namespace HaloAudit;

final class SiteProbe
{
    private const SITEMAP_PATHS = ['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'];
    private const FEED_PATHS = ['/feed/', '/rss.xml', '/atom.xml', '/feed.xml'];

    public static function collect(Fetcher $fetcher, CertProbe $cert, PageFacts $page, Response $pageResponse): SiteFacts
    {
        $site = new SiteFacts();
        $site->page = $pageResponse;
        $url = $pageResponse->finalUrl !== '' ? $pageResponse->finalUrl : $page->url();
        $origin = Url::origin($url);

        self::robots($site, $fetcher, $origin);
        self::sitemap($site, $fetcher, $origin);
        self::llms($site, $fetcher, $origin);

        if (stripos($url, 'https://') === 0) {
            $site->certDaysLeft = $cert->daysLeft(Url::host($url));
        }

        self::ogImage($site, $fetcher, $page, $url);
        self::feed($site, $fetcher, $page, $origin);

        return $site;
    }

    private static function looksLikeHtml(Response $r): bool
    {
        $type = strtolower((string) $r->header('content-type'));
        return strpos($type, 'text/html') !== false || preg_match('/^\s*<(!doctype|html)/i', $r->body) === 1;
    }

    private static function robots(SiteFacts $site, Fetcher $fetcher, string $origin): void
    {
        $r = $fetcher->get($origin . '/robots.txt');
        if ($r->error !== null) {
            return;
        }
        if ($r->status === 200 && self::looksLikeHtml($r)) {
            $site->robotsStatus = 404; // soft 404
            return;
        }
        $site->robotsStatus = $r->status;
        if ($r->status === 200) {
            $site->robots = RobotsTxt::parse($r->body);
        }
    }

    private static function sitemap(SiteFacts $site, Fetcher $fetcher, string $origin): void
    {
        $candidates = $site->robots !== null ? $site->robots->sitemaps() : [];
        foreach (self::SITEMAP_PATHS as $path) {
            $candidates[] = $origin . $path;
        }

        foreach (array_slice(array_unique($candidates), 0, 4) as $candidate) {
            $r = $fetcher->get($candidate);
            if ($r->error !== null) {
                continue;
            }
            if ($r->status === 200 && preg_match('/<(urlset|sitemapindex)[\s>]/i', $r->body)) {
                $site->sitemapStatus = 200;
                $site->sitemapValid = true;
                $site->sitemapNewest = self::newestLastmod($r->body);
                return;
            }
            $site->sitemapStatus = $r->status === 200 ? 404 : $r->status;
        }
    }

    private static function newestLastmod(string $xml): ?int
    {
        if (!preg_match_all('#<lastmod>\s*([^<\s]+)\s*</lastmod>#i', $xml, $m)) {
            return null;
        }
        $newest = null;
        foreach ($m[1] as $value) {
            $time = strtotime($value);
            if ($time !== false && ($newest === null || $time > $newest)) {
                $newest = $time;
            }
        }
        return $newest;
    }

    private static function llms(SiteFacts $site, Fetcher $fetcher, string $origin): void
    {
        $r = $fetcher->get($origin . '/llms.txt');
        if ($r->error !== null) {
            return;
        }
        $site->llmsStatus = $r->status;
        $site->llmsBody = $r->status === 200 ? $r->body : '';
    }

    private static function ogImage(SiteFacts $site, Fetcher $fetcher, PageFacts $page, string $url): void
    {
        $image = $page->property('og:image');
        if ($image === null) {
            return;
        }
        $r = $fetcher->get(Url::resolve($url, $image), 5, 262144);
        if ($r->error !== null) {
            return;
        }
        $size = $r->status === 200 ? @getimagesizefromstring($r->body) : false;
        $site->ogImage = [
            'status' => $r->status,
            'width' => $size ? (int) $size[0] : null,
            'height' => $size ? (int) $size[1] : null,
        ];
    }

    private static function feed(SiteFacts $site, Fetcher $fetcher, PageFacts $page, string $origin): void
    {
        if ($page->feedLinks() !== []) {
            $site->feedFound = true;
            return;
        }
        foreach (self::FEED_PATHS as $path) {
            $r = $fetcher->get($origin . $path, 5, 4096);
            if ($r->ok() && preg_match('/<(rss|feed)[\s>]/i', $r->body)) {
                $site->feedFound = true;
                return;
            }
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: all tests pass, including `BaseCheckTest` against the real `Context`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add site facts and side-file probe"
```

---

### Task 8: On-page checks (12)

**Files:**
- Create: `includes/engine/Checks/OnPage/` with `TitleLength.php`, `KeywordInTitle.php`, `KeywordInH1.php`, `KeywordInUrl.php`, `MetaDescription.php`, `SingleH1.php`, `HeadingOrder.php`, `Canonical.php`, `Noindex.php`, `WordCount.php`, `ImageAlt.php`, `InternalLinks.php`
- Test: `tests/unit/Checks/OnPageTest.php`

**Interfaces:**
- Consumes: `BaseCheck`, `Context`, `Result`, `Text`, `Url`, `PageFacts`, `Make`.
- Produces: 12 classes in namespace `HaloAudit\Checks\OnPage`, each `extends BaseCheck`, with these IDs: `title_length`, `keyword_in_title`, `keyword_in_h1`, `keyword_in_url`, `meta_description`, `single_h1`, `heading_order`, `canonical`, `noindex`, `word_count`, `image_alt`, `internal_links`.

- [ ] **Step 1: Write the failing test**

`tests/unit/Checks/OnPageTest.php`:

```php
<?php
namespace HaloAudit\Tests\Checks;

use HaloAudit\Checks\OnPage;
use HaloAudit\Response;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class OnPageTest extends TestCase
{
    /** @dataProvider cases */
    public function test_status(string $class, string $head, string $body, string $expected, string $keyword = 'mba'): void
    {
        $result = (new $class())->run(Make::ctx(Make::page($head, $body), null, $keyword));
        $this->assertSame($expected, $result->status, $class . ' evidence: ' . $result->evidence);
    }

    public function cases(): array
    {
        $t = static fn(int $n): string => '<title>' . str_repeat('a', $n) . '</title>';
        $d = static fn(int $n): string => '<meta name="description" content="' . str_repeat('a', $n) . '">';
        $imgs = static fn(int $with, int $without): string => str_repeat('<img src="a" alt="x">', $with) . str_repeat('<img src="b">', $without);

        return [
            'title 55' => [OnPage\TitleLength::class, $t(55), '', 'pass'],
            'title 50' => [OnPage\TitleLength::class, $t(50), '', 'pass'],
            'title 60' => [OnPage\TitleLength::class, $t(60), '', 'pass'],
            'title 49' => [OnPage\TitleLength::class, $t(49), '', 'warn'],
            'title 30' => [OnPage\TitleLength::class, $t(30), '', 'warn'],
            'title 65' => [OnPage\TitleLength::class, $t(65), '', 'warn'],
            'title 29' => [OnPage\TitleLength::class, $t(29), '', 'fail'],
            'title 66' => [OnPage\TitleLength::class, $t(66), '', 'fail'],
            'title none' => [OnPage\TitleLength::class, '', '', 'fail'],

            'kw title yes' => [OnPage\KeywordInTitle::class, '<title>Best MBA Programme</title>', '', 'pass'],
            'kw title no' => [OnPage\KeywordInTitle::class, '<title>Home</title>', '', 'fail'],
            'kw title blank kw' => [OnPage\KeywordInTitle::class, '<title>Home</title>', '', 'not_measured', ' '],

            'kw h1 yes' => [OnPage\KeywordInH1::class, '', '<h1>Our mba</h1>', 'pass'],
            'kw h1 no' => [OnPage\KeywordInH1::class, '', '<h1>Welcome</h1><h2>MBA</h2>', 'fail'],

            'kw url yes' => [OnPage\KeywordInUrl::class, '', '', 'pass'],
            'kw url multiword' => [OnPage\KeywordInUrl::class, '', '', 'pass', 'MBA courses'],
            'kw url no' => [OnPage\KeywordInUrl::class, '', '', 'fail', 'law degree'],

            'desc 150' => [OnPage\MetaDescription::class, $d(150), '', 'pass'],
            'desc 140' => [OnPage\MetaDescription::class, $d(140), '', 'pass'],
            'desc 160' => [OnPage\MetaDescription::class, $d(160), '', 'pass'],
            'desc 139' => [OnPage\MetaDescription::class, $d(139), '', 'warn'],
            'desc 70' => [OnPage\MetaDescription::class, $d(70), '', 'warn'],
            'desc 69' => [OnPage\MetaDescription::class, $d(69), '', 'fail'],
            'desc 161' => [OnPage\MetaDescription::class, $d(161), '', 'fail'],
            'desc none' => [OnPage\MetaDescription::class, '', '', 'fail'],

            'h1 one' => [OnPage\SingleH1::class, '', '<h1>A</h1>', 'pass'],
            'h1 none' => [OnPage\SingleH1::class, '', '<h2>A</h2>', 'fail'],
            'h1 two' => [OnPage\SingleH1::class, '', '<h1>A</h1><h1>B</h1>', 'fail'],

            'order ok' => [OnPage\HeadingOrder::class, '', '<h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2>', 'pass'],
            'order skip' => [OnPage\HeadingOrder::class, '', '<h1>A</h1><h3>C</h3>', 'warn'],
            'order none' => [OnPage\HeadingOrder::class, '', '<p>x</p>', 'pass'],

            'canonical self' => [OnPage\Canonical::class, '<link rel="canonical" href="https://example.com/courses/mba">', '', 'pass'],
            'canonical other' => [OnPage\Canonical::class, '<link rel="canonical" href="https://example.com/courses/">', '', 'warn'],
            'canonical none' => [OnPage\Canonical::class, '', '', 'fail'],

            'noindex clean' => [OnPage\Noindex::class, '<meta name="robots" content="index, follow">', '', 'pass'],
            'noindex meta' => [OnPage\Noindex::class, '<meta name="robots" content="NOINDEX, follow">', '', 'fail'],

            'words 300' => [OnPage\WordCount::class, '', Make::words(300), 'pass'],
            'words 299' => [OnPage\WordCount::class, '', Make::words(299), 'warn'],
            'words 200' => [OnPage\WordCount::class, '', Make::words(200), 'warn'],
            'words 199' => [OnPage\WordCount::class, '', Make::words(199), 'fail'],

            'alt all' => [OnPage\ImageAlt::class, '', $imgs(5, 0), 'pass'],
            'alt none on page' => [OnPage\ImageAlt::class, '', '<p>x</p>', 'pass'],
            'alt 80' => [OnPage\ImageAlt::class, '', $imgs(4, 1), 'warn'],
            'alt 60' => [OnPage\ImageAlt::class, '', $imgs(3, 2), 'fail'],

            'links 2' => [OnPage\InternalLinks::class, '', '<a href="/a">a</a><a href="/b">b</a>', 'pass'],
            'links 1' => [OnPage\InternalLinks::class, '', '<a href="/a">a</a><a href="https://other.org">o</a>', 'warn'],
            'links 0' => [OnPage\InternalLinks::class, '', '<a href="mailto:a@b.c">m</a><a href="#x">x</a>', 'fail'],
        ];
    }

    public function test_noindex_in_response_header_fails(): void
    {
        $site = Make::site();
        $site->page = Response::make(200, '', ['X-Robots-Tag' => 'noindex']);
        $result = (new OnPage\Noindex())->run(Make::ctx(Make::page(), $site));
        $this->assertSame('fail', $result->status);
    }

    public function test_every_check_has_copy(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/includes/engine/Checks/OnPage/*.php') as $file) {
            $class = 'HaloAudit\\Checks\\OnPage\\' . basename($file, '.php');
            $check = new $class();
            $this->assertSame('onpage', $check->group(), $class);
            $this->assertNotSame('', $check->id(), $class);
            $this->assertNotSame('', $check->title(), $class);
            $this->assertNotSame('', $check->why(), $class);
            $this->assertNotSame('', $check->fix('fail'), $class);
            $this->assertStringNotContainsString('—', $check->why() . $check->fix('fail') . $check->fix('warn'), $class);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter OnPageTest`
Expected: errors, `Class "HaloAudit\Checks\OnPage\TitleLength" not found`.

- [ ] **Step 3: Write the 12 checks**

Each block below is one file. The file name is the class name, in `includes/engine/Checks/OnPage/`.

`TitleLength.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class TitleLength extends BaseCheck
{
    public const ID = 'title_length';
    public const GROUP = 'onpage';
    public const SEVERITY = 3;
    public const TITLE = 'Title length';
    public const WHY = 'Search results show about 60 characters of a title. A longer title is cut off and a very short one says too little.';
    public const FIX_WARN = 'Rewrite the title so that it is between 50 and 60 characters.';
    public const FIX_FAIL = 'Give the page a title of 50 to 60 characters that names its topic.';

    public function run(Context $c): Result
    {
        $title = $c->page->title();
        if ($title === null) {
            return Result::fail('No title tag found');
        }
        $n = mb_strlen($title);
        $evidence = $n . ' characters';
        if ($n >= 50 && $n <= 60) {
            return Result::pass($evidence);
        }
        if (($n >= 30 && $n < 50) || ($n > 60 && $n <= 65)) {
            return Result::warn($evidence);
        }
        return Result::fail($evidence);
    }
}
```

`KeywordInTitle.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Text;

final class KeywordInTitle extends BaseCheck
{
    public const ID = 'keyword_in_title';
    public const GROUP = 'onpage';
    public const SEVERITY = 3;
    public const TITLE = 'Keyword in the title';
    public const WHY = 'The title is the strongest on-page signal of what a page is about.';
    public const FIX_FAIL = 'Put your keyword in the title, near the start.';

    public function run(Context $c): Result
    {
        if (trim($c->keyword) === '') {
            return Result::notMeasured('No keyword given');
        }
        $title = (string) $c->page->title();
        return Text::has($title, $c->keyword) ? Result::pass('Found in the title') : Result::fail('Not found in the title');
    }
}
```

`KeywordInH1.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Text;

final class KeywordInH1 extends BaseCheck
{
    public const ID = 'keyword_in_h1';
    public const GROUP = 'onpage';
    public const SEVERITY = 2;
    public const TITLE = 'Keyword in the main heading';
    public const WHY = 'The main heading tells readers and search engines the topic of the page.';
    public const FIX_FAIL = 'Use your keyword in the H1 heading of the page.';

    public function run(Context $c): Result
    {
        if (trim($c->keyword) === '') {
            return Result::notMeasured('No keyword given');
        }
        foreach ($c->page->headings() as $heading) {
            if ($heading['level'] === 1 && Text::has($heading['text'], $c->keyword)) {
                return Result::pass('Found in the H1');
            }
        }
        return Result::fail('Not found in any H1');
    }
}
```

`KeywordInUrl.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Url;

final class KeywordInUrl extends BaseCheck
{
    public const ID = 'keyword_in_url';
    public const GROUP = 'onpage';
    public const SEVERITY = 1;
    public const TITLE = 'Keyword in the address';
    public const WHY = 'A readable address that names the topic helps people and search engines.';
    public const FIX_FAIL = 'For new pages, use the keyword words in the address. Do not rename a page that already ranks without a redirect.';

    public function run(Context $c): Result
    {
        $words = array_values(array_filter((array) preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($c->keyword))));
        if ($words === []) {
            return Result::notMeasured('No keyword given');
        }
        $haystack = mb_strtolower(rawurldecode(Url::host($c->url) . Url::path($c->url)));
        foreach ($words as $word) {
            if (mb_strpos($haystack, $word) === false) {
                return Result::fail('"' . $word . '" is not in the address');
            }
        }
        return Result::pass('All keyword words are in the address');
    }
}
```

`MetaDescription.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class MetaDescription extends BaseCheck
{
    public const ID = 'meta_description';
    public const GROUP = 'onpage';
    public const SEVERITY = 2;
    public const TITLE = 'Meta description';
    public const WHY = 'The description is often the text shown under your title in search results.';
    public const FIX_WARN = 'Lengthen the meta description to between 140 and 160 characters.';
    public const FIX_FAIL = 'Write a meta description of 140 to 160 characters that says what the page offers.';

    public function run(Context $c): Result
    {
        $description = $c->page->meta('description');
        if ($description === null) {
            return Result::fail('No meta description found');
        }
        $n = mb_strlen($description);
        $evidence = $n . ' characters';
        if ($n >= 140 && $n <= 160) {
            return Result::pass($evidence);
        }
        if ($n >= 70 && $n < 140) {
            return Result::warn($evidence);
        }
        return Result::fail($evidence);
    }
}
```

`SingleH1.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SingleH1 extends BaseCheck
{
    public const ID = 'single_h1';
    public const GROUP = 'onpage';
    public const SEVERITY = 3;
    public const TITLE = 'One main heading';
    public const WHY = 'A page needs exactly one H1 so that its main topic is clear.';
    public const FIX_FAIL = 'Use exactly one H1 heading on the page. Change any others to H2.';

    public function run(Context $c): Result
    {
        $count = count(array_filter($c->page->headings(), static fn(array $h): bool => $h['level'] === 1));
        return $count === 1 ? Result::pass('1 H1 heading') : Result::fail($count . ' H1 headings');
    }
}
```

`HeadingOrder.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class HeadingOrder extends BaseCheck
{
    public const ID = 'heading_order';
    public const GROUP = 'onpage';
    public const SEVERITY = 1;
    public const TITLE = 'Heading order';
    public const WHY = 'Headings that go down one level at a time give the page a clear outline.';
    public const FIX_WARN = 'Do not skip heading levels. Put an H2 before an H3, and an H3 before an H4.';
    public const FIX_FAIL = 'Do not skip heading levels. Put an H2 before an H3, and an H3 before an H4.';

    public function run(Context $c): Result
    {
        $skips = 0;
        $previous = 0;
        foreach ($c->page->headings() as $heading) {
            if ($previous > 0 && $heading['level'] > $previous + 1) {
                $skips++;
            }
            $previous = $heading['level'];
        }
        return $skips === 0 ? Result::pass('No skipped levels') : Result::warn($skips . ' skipped level(s)');
    }
}
```

`Canonical.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Url;

final class Canonical extends BaseCheck
{
    public const ID = 'canonical';
    public const GROUP = 'onpage';
    public const SEVERITY = 2;
    public const TITLE = 'Canonical tag';
    public const WHY = 'The canonical tag tells search engines which address is the true one for this page.';
    public const FIX_WARN = 'The canonical tag points to another address. Confirm that this is intended. If it is not, point it to this page.';
    public const FIX_FAIL = 'Add a canonical tag that points to the full address of this page.';

    public function run(Context $c): Result
    {
        $canonical = $c->page->canonical();
        if ($canonical === null) {
            return Result::fail('No canonical tag found');
        }
        return Url::sameUrl($canonical, $c->url) ? Result::pass($canonical) : Result::warn('Points to ' . $canonical);
    }
}
```

`Noindex.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Noindex extends BaseCheck
{
    public const ID = 'noindex';
    public const GROUP = 'onpage';
    public const SEVERITY = 3;
    public const TITLE = 'Page can be indexed';
    public const WHY = 'A noindex instruction removes the page from search results.';
    public const FIX_FAIL = 'Remove the noindex instruction if you want this page to appear in search results.';

    public function run(Context $c): Result
    {
        $meta = strtolower((string) $c->page->meta('robots'));
        if (strpos($meta, 'noindex') !== false) {
            return Result::fail('noindex in the robots meta tag');
        }
        $header = strtolower((string) $c->site->page->header('x-robots-tag'));
        if (strpos($header, 'noindex') !== false) {
            return Result::fail('noindex in the X-Robots-Tag header');
        }
        return Result::pass('No noindex instruction');
    }
}
```

`WordCount.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class WordCount extends BaseCheck
{
    public const ID = 'word_count';
    public const GROUP = 'onpage';
    public const SEVERITY = 2;
    public const TITLE = 'Amount of content';
    public const WHY = 'A page with very little text gives search engines little to rank.';
    public const FIX_WARN = 'Add more useful content. Aim for at least 300 words that answer what a visitor wants to know.';
    public const FIX_FAIL = 'This page has very little text. Add at least 300 words that answer what a visitor wants to know.';

    public function run(Context $c): Result
    {
        $n = $c->page->wordCount();
        $evidence = $n . ' words';
        if ($n >= 300) {
            return Result::pass($evidence);
        }
        return $n >= 200 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

`ImageAlt.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class ImageAlt extends BaseCheck
{
    public const ID = 'image_alt';
    public const GROUP = 'onpage';
    public const SEVERITY = 1;
    public const TITLE = 'Image alt text';
    public const WHY = 'Alt text describes an image to search engines and to people who use screen readers.';
    public const FIX_WARN = 'Add an alt attribute to every image. Use alt="" for images that are decoration only.';
    public const FIX_FAIL = 'Many images have no alt attribute. Add one to every image. Use alt="" for images that are decoration only.';

    public function run(Context $c): Result
    {
        $images = $c->page->images();
        $total = count($images);
        if ($total === 0) {
            return Result::pass('No images on the page');
        }
        $with = count(array_filter($images, static fn(array $i): bool => $i['has_alt']));
        $evidence = $with . ' of ' . $total . ' images have an alt attribute';
        if ($with === $total) {
            return Result::pass($evidence);
        }
        return ($with / $total) >= 0.8 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

`InternalLinks.php`:

```php
<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class InternalLinks extends BaseCheck
{
    public const ID = 'internal_links';
    public const GROUP = 'onpage';
    public const SEVERITY = 1;
    public const TITLE = 'Links to your other pages';
    public const WHY = 'Links between your pages help visitors and search engines find related content.';
    public const FIX_WARN = 'Add at least one more link to a related page on your site.';
    public const FIX_FAIL = 'Add links to at least two related pages on your site.';

    public function run(Context $c): Result
    {
        $n = count(array_filter($c->page->links(), static fn(array $l): bool => $l['internal']));
        $evidence = $n . ' internal link(s)';
        if ($n >= 2) {
            return Result::pass($evidence);
        }
        return $n === 1 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter OnPageTest`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add on-page checks"
```

---

### Task 9: Technical checks (8)

**Files:**
- Create: `includes/engine/Checks/Technical/` with `Https.php`, `Certificate.php`, `RedirectChain.php`, `RobotsReachable.php`, `Sitemap.php`, `SecurityHeaders.php`, `StagingLeaks.php`, `JsRendering.php`
- Test: `tests/unit/Checks/TechnicalTest.php`

**Interfaces:**
- Consumes: `BaseCheck`, `Context`, `Result`, `SiteFacts`, `Response`, `Make`.
- Produces: 8 classes in `HaloAudit\Checks\Technical` with IDs `https`, `certificate`, `redirect_chain`, `robots_reachable`, `sitemap`, `security_headers`, `staging_leaks`, `js_rendering`.

- [ ] **Step 1: Write the failing test**

`tests/unit/Checks/TechnicalTest.php`:

```php
<?php
namespace HaloAudit\Tests\Checks;

use HaloAudit\Checks\Technical;
use HaloAudit\Response;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class TechnicalTest extends TestCase
{
    private function status(string $class, array $site = [], string $body = '', ?string $url = null): string
    {
        $page = Make::page('', $body, $url ?? Make::URL);
        return (new $class())->run(Make::ctx($page, Make::site($site)))->status;
    }

    private function pageResponse(array $headers = [], array $chain = [], string $finalUrl = Make::URL): Response
    {
        $r = Response::make(200, '', $headers);
        $r->chain = $chain;
        $r->finalUrl = $finalUrl;
        return $r;
    }

    public function test_https(): void
    {
        $this->assertSame('pass', $this->status(Technical\Https::class));
        $this->assertSame('fail', $this->status(Technical\Https::class, ['page' => $this->pageResponse([], [], 'http://example.com/')], '', 'http://example.com/'));
    }

    /** @dataProvider certs */
    public function test_certificate(?int $days, string $expected): void
    {
        $this->assertSame($expected, $this->status(Technical\Certificate::class, ['certDaysLeft' => $days]));
    }

    public function certs(): array
    {
        return [[90, 'pass'], [30, 'pass'], [29, 'warn'], [14, 'warn'], [13, 'fail'], [-2, 'fail'], [null, 'not_measured']];
    }

    public function test_certificate_is_not_measured_on_http(): void
    {
        $site = ['certDaysLeft' => null, 'page' => $this->pageResponse([], [], 'http://example.com/')];
        $this->assertSame('not_measured', $this->status(Technical\Certificate::class, $site, '', 'http://example.com/'));
    }

    /** @dataProvider chains */
    public function test_redirect_chain(array $statuses, string $expected): void
    {
        $chain = array_map(static fn(int $s): array => ['status' => $s, 'url' => 'https://example.com/x'], $statuses);
        $this->assertSame($expected, $this->status(Technical\RedirectChain::class, ['page' => $this->pageResponse([], $chain)]));
    }

    public function chains(): array
    {
        return [
            'none' => [[], 'pass'],
            'one permanent' => [[301], 'pass'],
            'one temporary' => [[302], 'warn'],
            'one 307' => [[307], 'warn'],
            'two' => [[301, 301], 'warn'],
            'three' => [[301, 301, 308], 'fail'],
        ];
    }

    /** @dataProvider robots */
    public function test_robots_reachable(?int $status, string $expected): void
    {
        $this->assertSame($expected, $this->status(Technical\RobotsReachable::class, ['robotsStatus' => $status]));
    }

    public function robots(): array
    {
        return [[200, 'pass'], [404, 'warn'], [403, 'warn'], [500, 'not_measured'], [503, 'not_measured'], [null, 'not_measured']];
    }

    public function test_sitemap(): void
    {
        $this->assertSame('pass', $this->status(Technical\Sitemap::class, ['sitemapStatus' => 200, 'sitemapValid' => true]));
        $this->assertSame('warn', $this->status(Technical\Sitemap::class, ['sitemapStatus' => 404, 'sitemapValid' => false]));
        $this->assertSame('not_measured', $this->status(Technical\Sitemap::class, ['sitemapStatus' => null]));
    }

    /** @dataProvider headers */
    public function test_security_headers(int $count, string $expected): void
    {
        $all = [
            'Strict-Transport-Security' => 'max-age=1', 'Content-Security-Policy' => "default-src 'self'",
            'X-Frame-Options' => 'DENY', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin', 'Permissions-Policy' => 'camera=()',
        ];
        $site = ['page' => $this->pageResponse(array_slice($all, 0, $count, true))];
        $this->assertSame($expected, $this->status(Technical\SecurityHeaders::class, $site));
    }

    public function headers(): array
    {
        return [[6, 'pass'], [5, 'pass'], [4, 'warn'], [2, 'warn'], [1, 'fail'], [0, 'fail']];
    }

    /** @dataProvider leaks */
    public function test_staging_leaks(string $body, string $expected): void
    {
        $this->assertSame($expected, $this->status(Technical\StagingLeaks::class, [], $body));
    }

    public function leaks(): array
    {
        return [
            'clean' => ['<a href="https://example.com/a">a</a><p>We love local food and kinsta.</p>', 'pass'],
            'kinsta' => ['<img src="https://stg-site.kinsta.cloud/a.png">', 'fail'],
            'localhost' => ['<script src="http://localhost:3000/app.js"></script>', 'fail'],
            'dot local' => ['<link href="//mysite.local/style.css">', 'fail'],
            'wpengine' => ['<a href="https://mysite.wpengine.com/x">x</a>', 'fail'],
        ];
    }

    public function test_js_rendering(): void
    {
        $this->assertSame('pass', $this->status(Technical\JsRendering::class, [], Make::words(200)));
        $this->assertSame('warn', $this->status(Technical\JsRendering::class, [], Make::words(199)));
        $this->assertSame('warn', $this->status(Technical\JsRendering::class, [], Make::words(50)));
        $this->assertSame('fail', $this->status(Technical\JsRendering::class, [], '<div id="root"></div>'));
    }

    public function test_every_check_has_copy(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/includes/engine/Checks/Technical/*.php') as $file) {
            $class = 'HaloAudit\\Checks\\Technical\\' . basename($file, '.php');
            $check = new $class();
            $this->assertSame('technical', $check->group(), $class);
            $this->assertNotSame('', $check->id() . '', $class);
            $this->assertNotSame('', $check->title(), $class);
            $this->assertNotSame('', $check->why(), $class);
            $this->assertNotSame('', $check->fix('fail'), $class);
            $this->assertStringNotContainsString('—', $check->why() . $check->fix('fail') . $check->fix('warn'), $class);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TechnicalTest`
Expected: errors, `Class "HaloAudit\Checks\Technical\Https" not found`.

- [ ] **Step 3: Write the 8 checks**

Each block is one file in `includes/engine/Checks/Technical/`.

`Https.php`:

```php
<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Https extends BaseCheck
{
    public const ID = 'https';
    public const GROUP = 'technical';
    public const SEVERITY = 3;
    public const TITLE = 'Secure connection';
    public const WHY = 'Browsers warn visitors away from pages that do not use HTTPS.';
    public const FIX_FAIL = 'Serve the page over HTTPS and redirect the HTTP address to it.';

    public function run(Context $c): Result
    {
        $final = $c->site->page->finalUrl !== '' ? $c->site->page->finalUrl : $c->url;
        return stripos($final, 'https://') === 0 ? Result::pass('Served over HTTPS') : Result::fail('Served over HTTP');
    }
}
```

`Certificate.php`:

```php
<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Certificate extends BaseCheck
{
    public const ID = 'certificate';
    public const GROUP = 'technical';
    public const SEVERITY = 3;
    public const TITLE = 'Security certificate';
    public const WHY = 'When a certificate expires, browsers block the site with a full-page warning.';
    public const FIX_WARN = 'Renew the security certificate soon. It expires in less than 30 days.';
    public const FIX_FAIL = 'Renew the security certificate now. It has expired or expires in less than 14 days.';

    public function run(Context $c): Result
    {
        $days = $c->site->certDaysLeft;
        if ($days === null) {
            return Result::notMeasured('The certificate could not be read');
        }
        $evidence = $days < 0 ? 'Expired ' . abs($days) . ' day(s) ago' : $days . ' day(s) left';
        if ($days >= 30) {
            return Result::pass($evidence);
        }
        return $days >= 14 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

`RedirectChain.php`:

```php
<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class RedirectChain extends BaseCheck
{
    public const ID = 'redirect_chain';
    public const GROUP = 'technical';
    public const SEVERITY = 1;
    public const TITLE = 'Redirects';
    public const WHY = 'Each redirect adds delay. Temporary redirects also pass less ranking value.';
    public const FIX_WARN = 'Link straight to the final address. Use a permanent (301) redirect in place of a temporary one.';
    public const FIX_FAIL = 'Remove the redirect chain. Send the first address straight to the final address with one permanent (301) redirect.';

    public function run(Context $c): Result
    {
        $chain = $c->site->page->chain;
        $hops = count($chain);
        if ($hops === 0) {
            return Result::pass('No redirect');
        }
        $statuses = array_column($chain, 'status');
        $evidence = $hops . ' redirect(s): ' . implode(', ', $statuses);
        if ($hops > 2) {
            return Result::fail($evidence);
        }
        $temporary = array_intersect($statuses, [302, 303, 307]) !== [];
        return ($hops === 2 || $temporary) ? Result::warn($evidence) : Result::pass($evidence);
    }
}
```

`RobotsReachable.php`:

```php
<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class RobotsReachable extends BaseCheck
{
    public const ID = 'robots_reachable';
    public const GROUP = 'technical';
    public const SEVERITY = 1;
    public const TITLE = 'robots.txt file';
    public const WHY = 'The robots.txt file tells crawlers what they may read and where the sitemap is.';
    public const FIX_WARN = 'Add a robots.txt file at the root of the site and list your sitemap in it.';
    public const FIX_FAIL = 'Add a robots.txt file at the root of the site and list your sitemap in it.';

    public function run(Context $c): Result
    {
        $status = $c->site->robotsStatus;
        if ($status === null || $status >= 500) {
            return Result::notMeasured('The robots.txt file could not be fetched');
        }
        return $status === 200 ? Result::pass('Found') : Result::warn('Not found (HTTP ' . $status . ')');
    }
}
```

`Sitemap.php`:

```php
<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Sitemap extends BaseCheck
{
    public const ID = 'sitemap';
    public const GROUP = 'technical';
    public const SEVERITY = 2;
    public const TITLE = 'XML sitemap';
    public const WHY = 'A sitemap helps search engines find all of your pages.';
    public const FIX_WARN = 'Publish an XML sitemap and list its address in robots.txt.';
    public const FIX_FAIL = 'Publish an XML sitemap and list its address in robots.txt.';

    public function run(Context $c): Result
    {
        if ($c->site->sitemapStatus === null) {
            return Result::notMeasured('The sitemap could not be fetched');
        }
        return $c->site->sitemapValid ? Result::pass('A valid XML sitemap was found') : Result::warn('No valid XML sitemap was found');
    }
}
```

`SecurityHeaders.php`:

```php
<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SecurityHeaders extends BaseCheck
{
    public const ID = 'security_headers';
    public const GROUP = 'technical';
    public const SEVERITY = 1;
    public const TITLE = 'Security headers';
    public const WHY = 'Security headers protect visitors from common attacks such as clickjacking.';
    public const FIX_WARN = 'Ask your developer or host to add the missing security headers.';
    public const FIX_FAIL = 'Almost no security headers are set. Ask your developer or host to add them.';

    private const HEADERS = [
        'strict-transport-security', 'content-security-policy', 'x-frame-options',
        'x-content-type-options', 'referrer-policy', 'permissions-policy',
    ];

    public function run(Context $c): Result
    {
        $missing = [];
        foreach (self::HEADERS as $name) {
            if ($c->site->page->header($name) === null) {
                $missing[] = $name;
            }
        }
        $present = count(self::HEADERS) - count($missing);
        $evidence = $present . ' of 6 present' . ($missing === [] ? '' : '. Missing: ' . implode(', ', $missing));
        if ($present >= 5) {
            return Result::pass($evidence);
        }
        return $present >= 2 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

`StagingLeaks.php`:

```php
<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class StagingLeaks extends BaseCheck
{
    public const ID = 'staging_leaks';
    public const GROUP = 'technical';
    public const SEVERITY = 2;
    public const TITLE = 'Test-site links';
    public const WHY = 'Links to a test or staging site break for visitors and can get the test site indexed.';
    public const FIX_FAIL = 'Replace every link, image and script address that points to a test or staging host with the live address.';

    private const PATTERN = '#(?:https?:)?//((?:localhost|127\.0\.0\.1)|[a-z0-9.-]+\.(?:local|test|kinsta\.cloud|wpengine\.com|wpenginepowered\.com|flywheelsites\.com|flywheelstaging\.com|pantheonsite\.io))(?=[/:"\'\s?\#]|$)#i';

    public function run(Context $c): Result
    {
        if (!preg_match_all(self::PATTERN, $c->page->html(), $m)) {
            return Result::pass('No test-site addresses found');
        }
        $hosts = array_slice(array_values(array_unique(array_map('strtolower', $m[1]))), 0, 3);
        return Result::fail('Found: ' . implode(', ', $hosts));
    }
}
```

`JsRendering.php`:

```php
<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class JsRendering extends BaseCheck
{
    public const ID = 'js_rendering';
    public const GROUP = 'technical';
    public const SEVERITY = 2;
    public const TITLE = 'Content without JavaScript';
    public const WHY = 'Many crawlers, and most AI crawlers, do not run JavaScript. They only read the text in the first HTML response.';
    public const FIX_WARN = 'Only a little text is in the first HTML response. Render the main content on the server.';
    public const FIX_FAIL = 'Almost no text is in the first HTML response. Render the main content on the server so that crawlers can read it.';

    public function run(Context $c): Result
    {
        $n = $c->page->wordCount();
        $evidence = $n . ' words in the first HTML response';
        if ($n >= 200) {
            return Result::pass($evidence);
        }
        return $n >= 50 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter TechnicalTest`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add technical checks"
```

---

### Task 10: AI-readiness checks (10)

**Files:**
- Create: `includes/engine/Checks/Ai/` with `AiCrawlers.php`, `LlmsTxt.php`, `JsonLd.php`, `SchemaTypes.php`, `FaqSchema.php`, `SemanticHtml.php`, `OpenGraph.php`, `TwitterCard.php`, `OgImage.php`, `Feed.php`
- Test: `tests/unit/Checks/AiTest.php`

**Interfaces:**
- Consumes: `BaseCheck`, `Context`, `Result`, `RobotsTxt`, `Url`, `Make`.
- Produces: 10 classes in `HaloAudit\Checks\Ai` with IDs `ai_crawlers`, `llms_txt`, `json_ld`, `schema_types`, `faq_schema`, `semantic_html`, `open_graph`, `twitter_card`, `og_image`, `feed`. Public constant `AiCrawlers::AGENTS` (7 names).

- [ ] **Step 1: Write the failing test**

`tests/unit/Checks/AiTest.php`:

```php
<?php
namespace HaloAudit\Tests\Checks;

use HaloAudit\Checks\Ai;
use HaloAudit\RobotsTxt;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class AiTest extends TestCase
{
    private function status(string $class, string $head = '', string $body = '', array $site = []): string
    {
        return (new $class())->run(Make::ctx(Make::page($head, $body), Make::site($site)))->status;
    }

    private static function ld(string $json): string
    {
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /** @dataProvider crawlers */
    public function test_ai_crawlers(?int $status, ?string $robots, string $expected): void
    {
        $site = ['robotsStatus' => $status, 'robots' => $robots === null ? null : RobotsTxt::parse($robots)];
        $this->assertSame($expected, $this->status(Ai\AiCrawlers::class, '', '', $site));
    }

    public function crawlers(): array
    {
        return [
            'fetch failed' => [null, null, 'not_measured'],
            'server error' => [503, null, 'not_measured'],
            'no robots file' => [404, null, 'pass'],
            'all allowed' => [200, "User-agent: *\nDisallow: /wp-admin/", 'pass'],
            'no star group' => [200, "User-agent: Bingbot\nDisallow: /", 'pass'],
            'one blocked' => [200, "User-agent: GPTBot\nDisallow: /", 'warn'],
            'two blocked' => [200, "User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /", 'warn'],
            'three blocked' => [200, "User-agent: GPTBot\nUser-agent: CCBot\nUser-agent: ClaudeBot\nDisallow: /", 'fail'],
            'star blocks all' => [200, "User-agent: *\nDisallow: /", 'fail'],
            'only this path blocked' => [200, "User-agent: GPTBot\nDisallow: /courses/", 'warn'],
            'other path blocked' => [200, "User-agent: GPTBot\nDisallow: /private/", 'pass'],
        ];
    }

    /** @dataProvider llms */
    public function test_llms_txt(?int $status, string $body, string $expected): void
    {
        $this->assertSame($expected, $this->status(Ai\LlmsTxt::class, '', '', ['llmsStatus' => $status, 'llmsBody' => $body]));
    }

    public function llms(): array
    {
        return [
            'good' => [200, "# Example\n> Summary", 'pass'],
            'bom then heading' => [200, "\xEF\xBB\xBF# Example", 'pass'],
            'no heading' => [200, 'Just some text', 'warn'],
            'html soft 404' => [200, '<!DOCTYPE html><html><body>Not found</body></html>', 'fail'],
            'missing' => [404, '', 'fail'],
            'fetch failed' => [null, '', 'not_measured'],
        ];
    }

    public function test_json_ld(): void
    {
        $this->assertSame('pass', $this->status(Ai\JsonLd::class, self::ld('{"@type":"Organization"}')));
        $this->assertSame('warn', $this->status(Ai\JsonLd::class));
        $this->assertSame('fail', $this->status(Ai\JsonLd::class, self::ld('{"@type":"Organization"}') . self::ld('{oops')));
    }

    public function test_schema_types(): void
    {
        $this->assertSame('pass', $this->status(Ai\SchemaTypes::class, self::ld('{"@graph":[{"@type":"Organization"},{"@type":"Course"}]}')));
        $this->assertSame('warn', $this->status(Ai\SchemaTypes::class, self::ld('{"@type":"WebSite"}')));
        $this->assertSame('warn', $this->status(Ai\SchemaTypes::class, self::ld('{"@type":"Article"}')));
        $this->assertSame('fail', $this->status(Ai\SchemaTypes::class));
    }

    public function test_faq_schema(): void
    {
        $details = str_repeat('<details><summary>Q?</summary>A</details>', 5);
        $this->assertSame('pass', $this->status(Ai\FaqSchema::class, '', '<p>No questions here</p>'));
        $this->assertSame('pass', $this->status(Ai\FaqSchema::class, self::ld('{"@type":"FAQPage"}'), $details));
        $this->assertSame('fail', $this->status(Ai\FaqSchema::class, '', $details));
        $this->assertSame('warn', $this->status(Ai\FaqSchema::class, '', '<h2>Frequently Asked Questions</h2>'));
        $this->assertSame('warn', $this->status(Ai\FaqSchema::class, '', '<h2>FAQs</h2><details></details>'));
    }

    public function test_semantic_html(): void
    {
        $this->assertSame('pass', $this->status(Ai\SemanticHtml::class, '', '<header></header><nav></nav><main><article></article></main><footer></footer>'));
        $this->assertSame('pass', $this->status(Ai\SemanticHtml::class, '', '<header></header><nav></nav><main></main><footer></footer>'));
        $this->assertSame('warn', $this->status(Ai\SemanticHtml::class, '', '<header></header><footer></footer>'));
        $this->assertSame('fail', $this->status(Ai\SemanticHtml::class, '', '<div><nav></nav></div>'));
    }

    public function test_open_graph_and_twitter(): void
    {
        $og = '<meta property="og:title" content="t"><meta property="og:description" content="d"><meta property="og:image" content="/i.png"><meta property="og:url" content="https://example.com/">';
        $this->assertSame('pass', $this->status(Ai\OpenGraph::class, $og));
        $this->assertSame('fail', $this->status(Ai\OpenGraph::class, '<meta property="og:title" content="t">'));
        $this->assertSame('pass', $this->status(Ai\TwitterCard::class, '<meta name="twitter:card" content="summary_large_image">'));
        $this->assertSame('warn', $this->status(Ai\TwitterCard::class));
    }

    /** @dataProvider ogImages */
    public function test_og_image(bool $declared, ?array $probe, string $expected): void
    {
        $head = $declared ? '<meta property="og:image" content="/i.png">' : '';
        $this->assertSame($expected, $this->status(Ai\OgImage::class, $head, '', ['ogImage' => $probe]));
    }

    public function ogImages(): array
    {
        return [
            'not declared' => [false, null, 'fail'],
            'fetch failed' => [true, null, 'not_measured'],
            'broken' => [true, ['status' => 404, 'width' => null, 'height' => null], 'fail'],
            'right size' => [true, ['status' => 200, 'width' => 1200, 'height' => 630], 'pass'],
            'other size' => [true, ['status' => 200, 'width' => 800, 'height' => 800], 'warn'],
            'size unknown' => [true, ['status' => 200, 'width' => null, 'height' => null], 'warn'],
        ];
    }

    public function test_feed(): void
    {
        $this->assertSame('pass', $this->status(Ai\Feed::class, '', '', ['feedFound' => true]));
        $this->assertSame('warn', $this->status(Ai\Feed::class, '', '', ['feedFound' => false]));
    }

    public function test_every_check_has_copy(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/includes/engine/Checks/Ai/*.php') as $file) {
            $class = 'HaloAudit\\Checks\\Ai\\' . basename($file, '.php');
            $check = new $class();
            $this->assertSame('ai', $check->group(), $class);
            $this->assertNotSame('', $check->id() . '', $class);
            $this->assertNotSame('', $check->title(), $class);
            $this->assertNotSame('', $check->why(), $class);
            $this->assertNotSame('', $check->fix('warn') . $check->fix('fail'), $class);
            $this->assertStringNotContainsString('—', $check->why() . $check->fix('fail') . $check->fix('warn'), $class);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AiTest`
Expected: errors, `Class "HaloAudit\Checks\Ai\AiCrawlers" not found`.

- [ ] **Step 3: Write the 10 checks**

Each block is one file in `includes/engine/Checks/Ai/`.

`AiCrawlers.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Url;

final class AiCrawlers extends BaseCheck
{
    public const ID = 'ai_crawlers';
    public const GROUP = 'ai';
    public const SEVERITY = 3;
    public const TITLE = 'AI crawlers can read the page';
    public const WHY = 'ChatGPT, Claude, Perplexity and Gemini can only cite pages that their crawlers are allowed to read.';
    public const FIX_WARN = 'Your robots.txt blocks some AI crawlers from this page. Remove those rules if you want AI assistants to cite you.';
    public const FIX_FAIL = 'Your robots.txt blocks most AI crawlers from this page. Remove those rules if you want AI assistants to cite you.';

    public const AGENTS = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'CCBot'];

    public function run(Context $c): Result
    {
        $status = $c->site->robotsStatus;
        if ($status === null || $status >= 500) {
            return Result::notMeasured('The robots.txt file could not be fetched');
        }
        if ($c->site->robots === null) {
            return Result::pass('No robots.txt rules, so all crawlers are allowed');
        }

        $path = Url::path($c->url);
        $blocked = [];
        foreach (self::AGENTS as $agent) {
            if (!$c->site->robots->isAllowed($agent, $path)) {
                $blocked[] = $agent;
            }
        }
        if ($blocked === []) {
            return Result::pass('All 7 AI crawlers are allowed');
        }
        $evidence = 'Blocked: ' . implode(', ', $blocked);
        return count($blocked) >= 3 ? Result::fail($evidence) : Result::warn($evidence);
    }
}
```

`LlmsTxt.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class LlmsTxt extends BaseCheck
{
    public const ID = 'llms_txt';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'llms.txt file';
    public const WHY = 'An llms.txt file gives AI assistants a short, clean guide to your most important pages.';
    public const FIX_WARN = 'Your llms.txt file does not start with a "# Site name" heading. Follow the llms.txt format.';
    public const FIX_FAIL = 'Add an llms.txt file at the root of the site. Start it with "# Site name" and list your key pages.';

    public function run(Context $c): Result
    {
        $status = $c->site->llmsStatus;
        if ($status === null) {
            return Result::notMeasured('The llms.txt file could not be fetched');
        }
        if ($status !== 200) {
            return Result::fail('Not found (HTTP ' . $status . ')');
        }
        $body = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $c->site->llmsBody));
        if (preg_match('/^<(!doctype|html)/i', $body)) {
            return Result::fail('The address returns a web page, not a text file');
        }
        return strpos($body, '#') === 0 ? Result::pass('Found and well formed') : Result::warn('Found, but it does not start with a heading');
    }
}
```

`JsonLd.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class JsonLd extends BaseCheck
{
    public const ID = 'json_ld';
    public const GROUP = 'ai';
    public const SEVERITY = 2;
    public const TITLE = 'Structured data';
    public const WHY = 'Structured data states facts about your page in a form that search engines and AI assistants read directly.';
    public const FIX_WARN = 'Add JSON-LD structured data that describes your organisation and this page.';
    public const FIX_FAIL = 'One or more structured data blocks contain a syntax error and are ignored. Correct the JSON.';

    public function run(Context $c): Result
    {
        $ld = $c->page->jsonLd();
        if ($ld['invalid'] > 0) {
            return Result::fail($ld['invalid'] . ' of ' . $ld['blocks'] . ' block(s) cannot be read');
        }
        return $ld['blocks'] === 0 ? Result::warn('No JSON-LD found') : Result::pass($ld['blocks'] . ' valid block(s)');
    }
}
```

`SchemaTypes.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SchemaTypes extends BaseCheck
{
    public const ID = 'schema_types';
    public const GROUP = 'ai';
    public const SEVERITY = 2;
    public const TITLE = 'Schema types';
    public const WHY = 'AI assistants trust a page more when it states who publishes it and what kind of page it is.';
    public const FIX_WARN = 'Add the missing half: one schema type for your organisation or site, and one for this page (for example Article, Course or Product).';
    public const FIX_FAIL = 'Add schema for your organisation (Organization) and for this page (for example Article, Course or Product).';

    private const SITE_TYPES = [
        'Organization', 'WebSite', 'Corporation', 'LocalBusiness', 'NGO',
        'EducationalOrganization', 'CollegeOrUniversity', 'School', 'HighSchool',
    ];

    public function run(Context $c): Result
    {
        $types = $c->page->jsonLd()['types'];
        $site = array_intersect($types, self::SITE_TYPES);
        $page = array_diff($types, self::SITE_TYPES);
        $evidence = $types === [] ? 'No schema types found' : 'Found: ' . implode(', ', array_slice($types, 0, 8));

        if ($site !== [] && $page !== []) {
            return Result::pass($evidence);
        }
        return ($site !== [] || $page !== []) ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

`FaqSchema.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class FaqSchema extends BaseCheck
{
    public const ID = 'faq_schema';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'FAQ schema';
    public const WHY = 'Question-and-answer content is what AI assistants quote most. FAQ schema marks it up for them.';
    public const FIX_WARN = 'This page has an FAQ section. Add FAQPage schema once it has five or more questions.';
    public const FIX_FAIL = 'This page has five or more questions and no FAQPage schema. Add FAQPage schema that matches the visible questions.';

    public function run(Context $c): Result
    {
        if (in_array('FAQPage', $c->page->jsonLd()['types'], true)) {
            return Result::pass('FAQPage schema found');
        }
        $questions = $c->page->count('details');
        if ($questions >= 5) {
            return Result::fail($questions . ' questions and no FAQPage schema');
        }
        foreach ($c->page->headings() as $heading) {
            if (preg_match('/\bfaqs?\b|frequently asked/i', $heading['text'])) {
                return Result::warn('An FAQ heading and no FAQPage schema');
            }
        }
        return Result::pass('No FAQ section on this page');
    }
}
```

`SemanticHtml.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SemanticHtml extends BaseCheck
{
    public const ID = 'semantic_html';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'Page structure tags';
    public const WHY = 'Tags such as main, article and nav tell a machine which part of the page is the real content.';
    public const FIX_WARN = 'Use more structure tags: header, nav, main, article and footer.';
    public const FIX_FAIL = 'The page is built almost only from div tags. Wrap the parts in header, nav, main, article and footer.';

    private const TAGS = ['main', 'article', 'nav', 'header', 'footer'];

    public function run(Context $c): Result
    {
        $present = array_values(array_filter(self::TAGS, static fn(string $tag): bool => $c->page->has($tag)));
        $n = count($present);
        $evidence = $n . ' of 5 present' . ($n > 0 ? ': ' . implode(', ', $present) : '');
        if ($n >= 4) {
            return Result::pass($evidence);
        }
        return $n >= 2 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

`OpenGraph.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class OpenGraph extends BaseCheck
{
    public const ID = 'open_graph';
    public const GROUP = 'ai';
    public const SEVERITY = 2;
    public const TITLE = 'Open Graph tags';
    public const WHY = 'Open Graph tags control the title, text and image shown when the page is shared or previewed.';
    public const FIX_FAIL = 'Add the missing Open Graph tags. A page needs og:title, og:description, og:image and og:url.';

    private const REQUIRED = ['og:title', 'og:description', 'og:image', 'og:url'];

    public function run(Context $c): Result
    {
        $missing = array_values(array_filter(self::REQUIRED, static fn(string $p): bool => $c->page->property($p) === null));
        return $missing === [] ? Result::pass('All four tags present') : Result::fail('Missing: ' . implode(', ', $missing));
    }
}
```

`TwitterCard.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class TwitterCard extends BaseCheck
{
    public const ID = 'twitter_card';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'Twitter card tag';
    public const WHY = 'The twitter:card tag gives the page a large preview on X and in several chat apps.';
    public const FIX_WARN = 'Add <meta name="twitter:card" content="summary_large_image"> to the page head.';

    public function run(Context $c): Result
    {
        $card = $c->page->meta('twitter:card') ?? $c->page->property('twitter:card');
        return $card !== null ? Result::pass($card) : Result::warn('No twitter:card tag');
    }
}
```

`OgImage.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class OgImage extends BaseCheck
{
    public const ID = 'og_image';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'Share image';
    public const WHY = 'A broken or wrongly sized share image makes the page look bad when it is shared.';
    public const FIX_WARN = 'Use a share image of 1200 by 630 pixels.';
    public const FIX_FAIL = 'Set og:image to an image address that loads. Use an image of 1200 by 630 pixels.';

    public function run(Context $c): Result
    {
        if ($c->page->property('og:image') === null) {
            return Result::fail('No og:image tag');
        }
        $image = $c->site->ogImage;
        if ($image === null) {
            return Result::notMeasured('The image could not be fetched');
        }
        if ($image['status'] >= 400) {
            return Result::fail('The image address returns HTTP ' . $image['status']);
        }
        if ($image['width'] === 1200 && $image['height'] === 630) {
            return Result::pass('1200 by 630 pixels');
        }
        $size = $image['width'] === null ? 'size unknown' : $image['width'] . ' by ' . $image['height'] . ' pixels';
        return Result::warn('The image loads, ' . $size);
    }
}
```

`Feed.php`:

```php
<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Feed extends BaseCheck
{
    public const ID = 'feed';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'RSS or Atom feed';
    public const WHY = 'A feed lets crawlers and AI tools pick up your new content quickly.';
    public const FIX_WARN = 'Publish an RSS or Atom feed and link to it from the page head.';

    public function run(Context $c): Result
    {
        return $c->site->feedFound ? Result::pass('A feed was found') : Result::warn('No feed was found');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AiTest`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add AI-readiness checks"
```

---

### Task 11: Speed checks (3) and `PsiClient`

**Files:**
- Create: `includes/engine/PsiClient.php`, `includes/engine/Checks/Speed/PsiScore.php`, `Lcp.php`, `Cls.php`
- Test: `tests/unit/PsiClientTest.php`, `tests/unit/Checks/SpeedTest.php`

**Interfaces:**
- Consumes: `HttpClient`, `Response`, `BaseCheck`, `Context`, `Result`, `SiteFacts::$psi`.
- Produces:
  - `PsiClient::__construct(HttpClient $http, string $apiKey)`; `run(string $url): ?array` returns `['score' => int, 'lcp_ms' => float, 'cls' => float]` or `null`; `PsiClient::parse(string $json): ?array`; `PsiClient::endpoint(string $url, string $apiKey): string`.
  - 3 classes in `HaloAudit\Checks\Speed` with IDs `psi_score`, `lcp`, `cls`.

- [ ] **Step 1: Write the failing tests**

`tests/unit/PsiClientTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\PsiClient;
use HaloAudit\Response;
use HaloAudit\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

final class PsiClientTest extends TestCase
{
    private const GOOD = '{"lighthouseResult":{"categories":{"performance":{"score":0.87}},"audits":{"largest-contentful-paint":{"numericValue":2310.5},"cumulative-layout-shift":{"numericValue":0.04}}}}';

    public function test_parse_reads_score_lcp_and_cls(): void
    {
        $this->assertSame(['score' => 87, 'lcp_ms' => 2310.5, 'cls' => 0.04], PsiClient::parse(self::GOOD));
    }

    /** @dataProvider bad */
    public function test_parse_returns_null_for_unusable_payloads(string $json): void
    {
        $this->assertNull(PsiClient::parse($json));
    }

    public function bad(): array
    {
        return [
            'not json' => ['<html>'],
            'error payload' => ['{"error":{"code":429}}'],
            'null score' => ['{"lighthouseResult":{"categories":{"performance":{"score":null}},"audits":{}}}'],
        ];
    }

    public function test_endpoint_encodes_url_and_adds_key(): void
    {
        $endpoint = PsiClient::endpoint('https://example.com/a b?x=1', 'KEY');
        $this->assertStringStartsWith('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?', $endpoint);
        $this->assertStringContainsString('url=https%3A%2F%2Fexample.com%2Fa%20b%3Fx%3D1', $endpoint);
        $this->assertStringContainsString('strategy=mobile', $endpoint);
        $this->assertStringContainsString('category=performance', $endpoint);
        $this->assertStringContainsString('key=KEY', $endpoint);
    }

    public function test_endpoint_without_key_has_no_key_param(): void
    {
        $this->assertStringNotContainsString('key=', PsiClient::endpoint('https://example.com/', ''));
    }

    public function test_run_returns_parsed_result(): void
    {
        $http = new FakeHttp();
        $http->routes[PsiClient::endpoint('https://example.com/', 'KEY')] = Response::make(200, self::GOOD);
        $this->assertSame(87, (new PsiClient($http, 'KEY'))->run('https://example.com/')['score']);
    }

    public function test_run_returns_null_on_http_failure(): void
    {
        $http = new FakeHttp();
        $http->routes[PsiClient::endpoint('https://example.com/', 'KEY')] = Response::make(429, '{"error":{}}');
        $this->assertNull((new PsiClient($http, 'KEY'))->run('https://example.com/'));
        $this->assertNull((new PsiClient(new FakeHttp(), 'KEY'))->run('https://example.com/'));
    }
}
```

`tests/unit/Checks/SpeedTest.php`:

```php
<?php
namespace HaloAudit\Tests\Checks;

use HaloAudit\Checks\Speed;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class SpeedTest extends TestCase
{
    private function status(string $class, ?array $psi): string
    {
        return (new $class())->run(Make::ctx(Make::page(), Make::site(['psi' => $psi])))->status;
    }

    private function psi(int $score = 95, float $lcp = 2000.0, float $cls = 0.05): array
    {
        return ['score' => $score, 'lcp_ms' => $lcp, 'cls' => $cls];
    }

    public function test_all_are_not_measured_without_data(): void
    {
        foreach ([Speed\PsiScore::class, Speed\Lcp::class, Speed\Cls::class] as $class) {
            $this->assertSame('not_measured', $this->status($class, null), $class);
        }
    }

    public function test_psi_score_bands(): void
    {
        $this->assertSame('pass', $this->status(Speed\PsiScore::class, $this->psi(90)));
        $this->assertSame('warn', $this->status(Speed\PsiScore::class, $this->psi(89)));
        $this->assertSame('warn', $this->status(Speed\PsiScore::class, $this->psi(50)));
        $this->assertSame('fail', $this->status(Speed\PsiScore::class, $this->psi(49)));
    }

    public function test_lcp_bands(): void
    {
        $this->assertSame('pass', $this->status(Speed\Lcp::class, $this->psi(95, 2499.0)));
        $this->assertSame('warn', $this->status(Speed\Lcp::class, $this->psi(95, 2500.0)));
        $this->assertSame('warn', $this->status(Speed\Lcp::class, $this->psi(95, 4000.0)));
        $this->assertSame('fail', $this->status(Speed\Lcp::class, $this->psi(95, 4001.0)));
    }

    public function test_cls_bands(): void
    {
        $this->assertSame('pass', $this->status(Speed\Cls::class, $this->psi(95, 2000.0, 0.09)));
        $this->assertSame('warn', $this->status(Speed\Cls::class, $this->psi(95, 2000.0, 0.1)));
        $this->assertSame('warn', $this->status(Speed\Cls::class, $this->psi(95, 2000.0, 0.25)));
        $this->assertSame('fail', $this->status(Speed\Cls::class, $this->psi(95, 2000.0, 0.26)));
    }

    public function test_every_check_has_copy(): void
    {
        foreach ([Speed\PsiScore::class, Speed\Lcp::class, Speed\Cls::class] as $class) {
            $check = new $class();
            $this->assertSame('speed', $check->group());
            $this->assertNotSame('', $check->title());
            $this->assertNotSame('', $check->why());
            $this->assertNotSame('', $check->fix('fail'));
            $this->assertNotSame('', $check->fix('warn'));
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'PsiClientTest|SpeedTest'`
Expected: errors, `Class "HaloAudit\PsiClient" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/PsiClient.php`:

```php
<?php
namespace HaloAudit;

final class PsiClient
{
    private HttpClient $http;
    private string $apiKey;

    public function __construct(HttpClient $http, string $apiKey)
    {
        $this->http = $http;
        $this->apiKey = $apiKey;
    }

    /** @return array{score:int,lcp_ms:float,cls:float}|null */
    public function run(string $url): ?array
    {
        $response = $this->http->request('GET', self::endpoint($url, $this->apiKey), 90, 5000000);
        return $response->ok() ? self::parse($response->body) : null;
    }

    public static function endpoint(string $url, string $apiKey): string
    {
        $query = 'url=' . rawurlencode($url) . '&strategy=mobile&category=performance';
        if ($apiKey !== '') {
            $query .= '&key=' . rawurlencode($apiKey);
        }
        return 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . $query;
    }

    /** @return array{score:int,lcp_ms:float,cls:float}|null */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
        $lcp = $data['lighthouseResult']['audits']['largest-contentful-paint']['numericValue'] ?? null;
        $cls = $data['lighthouseResult']['audits']['cumulative-layout-shift']['numericValue'] ?? null;
        if (!is_numeric($score) || !is_numeric($lcp) || !is_numeric($cls)) {
            return null;
        }
        return ['score' => (int) round($score * 100), 'lcp_ms' => (float) $lcp, 'cls' => (float) $cls];
    }
}
```

`includes/engine/Checks/Speed/PsiScore.php`:

```php
<?php
namespace HaloAudit\Checks\Speed;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class PsiScore extends BaseCheck
{
    public const ID = 'psi_score';
    public const GROUP = 'speed';
    public const SEVERITY = 3;
    public const TITLE = 'Mobile speed score';
    public const WHY = 'Most visitors arrive on a phone. A slow page loses them before they read anything.';
    public const FIX_WARN = 'Improve mobile speed. Start with large images, unused JavaScript and render-blocking files.';
    public const FIX_FAIL = 'The page is slow on a phone. Compress and resize images, remove unused JavaScript, and cache pages on the server.';

    public function run(Context $c): Result
    {
        if ($c->site->psi === null) {
            return Result::notMeasured('Google PageSpeed did not return a result');
        }
        $score = $c->site->psi['score'];
        $evidence = $score . ' out of 100 (Google PageSpeed, mobile)';
        if ($score >= 90) {
            return Result::pass($evidence);
        }
        return $score >= 50 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

`includes/engine/Checks/Speed/Lcp.php`:

```php
<?php
namespace HaloAudit\Checks\Speed;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Lcp extends BaseCheck
{
    public const ID = 'lcp';
    public const GROUP = 'speed';
    public const SEVERITY = 2;
    public const TITLE = 'Time until the main content shows';
    public const WHY = 'Largest Contentful Paint measures how long a visitor waits to see the main part of the page.';
    public const FIX_WARN = 'Bring the main content in under 2.5 seconds. Preload the main image and reduce server response time.';
    public const FIX_FAIL = 'The main content takes more than 4 seconds to show. Shrink the main image, preload it, and reduce server response time.';

    public function run(Context $c): Result
    {
        if ($c->site->psi === null) {
            return Result::notMeasured('Google PageSpeed did not return a result');
        }
        $ms = $c->site->psi['lcp_ms'];
        $evidence = number_format($ms / 1000, 1) . ' seconds';
        if ($ms < 2500) {
            return Result::pass($evidence);
        }
        return $ms <= 4000 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

`includes/engine/Checks/Speed/Cls.php`:

```php
<?php
namespace HaloAudit\Checks\Speed;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Cls extends BaseCheck
{
    public const ID = 'cls';
    public const GROUP = 'speed';
    public const SEVERITY = 2;
    public const TITLE = 'Layout stability';
    public const WHY = 'Cumulative Layout Shift measures how much the page jumps while it loads. Jumps cause wrong taps.';
    public const FIX_WARN = 'Set a width and height on images, embeds and ad slots so that the layout does not move.';
    public const FIX_FAIL = 'The page jumps a lot while it loads. Set a width and height on images, embeds and ad slots, and reserve space for banners.';

    public function run(Context $c): Result
    {
        if ($c->site->psi === null) {
            return Result::notMeasured('Google PageSpeed did not return a result');
        }
        $cls = $c->site->psi['cls'];
        $evidence = number_format($cls, 2);
        if ($cls < 0.1) {
            return Result::pass($evidence);
        }
        return $cls <= 0.25 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'PsiClientTest|SpeedTest'`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add PageSpeed client and speed checks"
```

---

### Task 12: Education detector and extras (4)

**Files:**
- Create: `includes/engine/Education.php`, `includes/engine/Checks/Education/ApplyLink.php`, `FeeVisible.php`, `EducationSchema.php`, `SitemapFreshness.php`
- Test: `tests/unit/EducationTest.php`

**Interfaces:**
- Consumes: `BaseCheck`, `Context` (`$now`), `Result`, `PageFacts`, `SiteFacts::$sitemapStatus`, `$sitemapNewest`.
- Produces:
  - `Education::detect(string $host, array $types): bool`; constant `Education::INSTITUTION_TYPES`.
  - 4 classes in `HaloAudit\Checks\Education` with IDs `apply_link`, `fee_visible`, `education_schema`, `sitemap_freshness`, all in group `education`.

- [ ] **Step 1: Write the failing test**

`tests/unit/EducationTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\Checks\Education as Checks;
use HaloAudit\Education;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class EducationTest extends TestCase
{
    /** @dataProvider detection */
    public function test_detect(string $host, array $types, bool $expected): void
    {
        $this->assertSame($expected, Education::detect($host, $types));
    }

    public function detection(): array
    {
        return [
            ['mit.edu', [], true],
            ['www.ashoka.edu.in', [], true],
            ['ox.ac.uk', [], true],
            ['example.com', ['CollegeOrUniversity'], true],
            ['example.com', ['School'], true],
            ['example.com', ['Organization'], false],
            ['education.example.com', [], false],
            ['acme.academy', [], false],
        ];
    }

    private function status(string $class, string $head = '', string $body = '', array $site = []): string
    {
        return (new $class())->run(Make::ctx(Make::page($head, $body), Make::site($site)))->status;
    }

    public function test_apply_link(): void
    {
        $this->assertSame('pass', $this->status(Checks\ApplyLink::class, '', '<a href="/x">Apply Now</a>'));
        $this->assertSame('pass', $this->status(Checks\ApplyLink::class, '', '<a href="/admissions/">Start here</a>'));
        $this->assertSame('warn', $this->status(Checks\ApplyLink::class, '', '<a href="/about">About</a><a href="/applied-sciences">Applied Sciences</a>'));
    }

    public function test_fee_visible(): void
    {
        $this->assertSame('pass', $this->status(Checks\FeeVisible::class, '', '<p>Tuition fee: ₹ 4,50,000 per year</p>'));
        $this->assertSame('pass', $this->status(Checks\FeeVisible::class, '', '<p>$12,000 annual tuition</p>'));
        $this->assertSame('pass', $this->status(Checks\FeeVisible::class, '', '<p>Fees: Rs. 90000</p>'));
        $this->assertSame('warn', $this->status(Checks\FeeVisible::class, '', '<p>Contact us for fee details.</p>'));
        $this->assertSame('warn', $this->status(Checks\FeeVisible::class, '', '<p>We raised $2,000,000 for research.</p>'));
    }

    public function test_education_schema(): void
    {
        $ld = static fn(string $type): string => '<script type="application/ld+json">{"@type":"' . $type . '"}</script>';
        $this->assertSame('pass', $this->status(Checks\EducationSchema::class, $ld('Course')));
        $this->assertSame('pass', $this->status(Checks\EducationSchema::class, $ld('CollegeOrUniversity')));
        $this->assertSame('warn', $this->status(Checks\EducationSchema::class, $ld('Organization')));
    }

    /** @dataProvider freshness */
    public function test_sitemap_freshness(?int $daysAgo, string $expected, string $bucket): void
    {
        $site = ['sitemapStatus' => 200, 'sitemapValid' => true, 'sitemapNewest' => $daysAgo === null ? null : Make::NOW - $daysAgo * 86400];
        $result = (new Checks\SitemapFreshness())->run(Make::ctx(Make::page(), Make::site($site)));
        $this->assertSame($expected, $result->status);
        $this->assertStringContainsString($bucket, $result->evidence);
    }

    public function freshness(): array
    {
        return [
            [3, 'pass', 'ACTIVE'], [14, 'pass', 'ACTIVE'], [15, 'pass', 'MODERATE'], [90, 'pass', 'MODERATE'],
            [91, 'warn', 'STALE'], [365, 'warn', 'STALE'], [366, 'warn', 'DORMANT'], [null, 'not_measured', 'UNKNOWN'],
        ];
    }

    public function test_every_extra_is_in_the_education_group_with_copy(): void
    {
        foreach ([Checks\ApplyLink::class, Checks\FeeVisible::class, Checks\EducationSchema::class, Checks\SitemapFreshness::class] as $class) {
            $check = new $class();
            $this->assertSame('education', $check->group());
            $this->assertNotSame('', $check->title());
            $this->assertNotSame('', $check->why());
            $this->assertNotSame('', $check->fix('warn'));
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter EducationTest`
Expected: errors, `Class "HaloAudit\Education" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/Education.php`:

```php
<?php
namespace HaloAudit;

final class Education
{
    public const INSTITUTION_TYPES = [
        'CollegeOrUniversity', 'EducationalOrganization', 'School',
        'HighSchool', 'MiddleSchool', 'ElementarySchool', 'Preschool',
    ];

    /** @param string[] $types JSON-LD types found on the page */
    public static function detect(string $host, array $types): bool
    {
        if (preg_match('/(\.edu|\.edu\.[a-z]{2}|\.ac\.[a-z]{2})$/', strtolower($host))) {
            return true;
        }
        return array_intersect($types, self::INSTITUTION_TYPES) !== [];
    }
}
```

`includes/engine/Checks/Education/ApplyLink.php`:

```php
<?php
namespace HaloAudit\Checks\Education;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class ApplyLink extends BaseCheck
{
    public const ID = 'apply_link';
    public const GROUP = 'education';
    public const SEVERITY = 2;
    public const TITLE = 'Apply link on this page';
    public const WHY = 'A student who is ready to apply must not have to search for the way in.';
    public const FIX_WARN = 'Add a clear "Apply" or "Admissions" link to this page, near the top.';

    private const PATTERN = '/\b(apply|admissions?|enrol|enroll|enrolment|enrollment)\b/i';

    public function run(Context $c): Result
    {
        foreach ($c->page->links() as $link) {
            $slug = str_replace(['-', '_', '/'], ' ', (string) parse_url($link['href'], PHP_URL_PATH));
            if (preg_match(self::PATTERN, $link['text']) || preg_match(self::PATTERN, $slug)) {
                return Result::pass('Found: "' . ($link['text'] !== '' ? $link['text'] : $link['href']) . '"');
            }
        }
        return Result::warn('No apply or admissions link on this page');
    }
}
```

`includes/engine/Checks/Education/FeeVisible.php`:

```php
<?php
namespace HaloAudit\Checks\Education;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class FeeVisible extends BaseCheck
{
    public const ID = 'fee_visible';
    public const GROUP = 'education';
    public const SEVERITY = 1;
    public const TITLE = 'Fee shown on this page';
    public const WHY = 'Students and parents look for the fee early. AI assistants also quote fees that pages state plainly.';
    public const FIX_WARN = 'If this is a course or admissions page, state the fee as a number with its currency.';

    private const MONEY = '(?:₹|rs\.?|inr|\$|usd|£|gbp|€|eur)\s?\d[\d,.]*';
    private const WORD = '(?:fees?|tuition)';

    public function run(Context $c): Result
    {
        $text = $c->page->visibleText();
        $near = '[^.\n]{0,60}';
        $pattern = '/' . self::WORD . $near . self::MONEY . '|' . self::MONEY . $near . self::WORD . '/iu';
        return preg_match($pattern, $text) ? Result::pass('A fee amount is shown') : Result::warn('No fee amount was found in the page text');
    }
}
```

`includes/engine/Checks/Education/EducationSchema.php`:

```php
<?php
namespace HaloAudit\Checks\Education;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Education;
use HaloAudit\Result;

final class EducationSchema extends BaseCheck
{
    public const ID = 'education_schema';
    public const GROUP = 'education';
    public const SEVERITY = 2;
    public const TITLE = 'Education schema';
    public const WHY = 'Education schema tells search engines and AI assistants that you are an institution and what you teach.';
    public const FIX_WARN = 'Add CollegeOrUniversity or School schema for the institution, and Course schema on course pages.';

    private const PAGE_TYPES = ['Course', 'EducationalOccupationalProgram', 'EducationEvent'];

    public function run(Context $c): Result
    {
        $found = array_intersect($c->page->jsonLd()['types'], array_merge(Education::INSTITUTION_TYPES, self::PAGE_TYPES));
        return $found !== [] ? Result::pass('Found: ' . implode(', ', $found)) : Result::warn('No education schema types found');
    }
}
```

`includes/engine/Checks/Education/SitemapFreshness.php`:

```php
<?php
namespace HaloAudit\Checks\Education;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SitemapFreshness extends BaseCheck
{
    public const ID = 'sitemap_freshness';
    public const GROUP = 'education';
    public const SEVERITY = 1;
    public const TITLE = 'How recently the site was updated';
    public const WHY = 'Admissions information changes every cycle. A site with no recent updates looks out of date to students and to search engines.';
    public const FIX_WARN = 'The sitemap shows no update in the last 90 days. Publish current admissions dates, fees and news.';

    public function run(Context $c): Result
    {
        $newest = $c->site->sitemapNewest;
        if ($newest === null) {
            return Result::notMeasured('UNKNOWN: the sitemap has no last-modified dates');
        }
        $days = max(0, (int) floor(($c->now - $newest) / 86400));
        $text = 'last update ' . $days . ' day(s) ago';
        if ($days <= 14) {
            return Result::pass('ACTIVE: ' . $text);
        }
        if ($days <= 90) {
            return Result::pass('MODERATE: ' . $text);
        }
        return Result::warn(($days <= 365 ? 'STALE: ' : 'DORMANT: ') . $text);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter EducationTest`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add education detection and extras"
```

---

### Task 13: `CheckRegistry`, `Analyzer` and `ReportView`

**Files:**
- Create: `includes/engine/CheckRegistry.php`, `Analyzer.php`, `ReportView.php`
- Test: `tests/unit/CheckRegistryTest.php`, `tests/unit/AnalyzerTest.php`

**Interfaces:**
- Consumes: everything from Tasks 3 to 12.
- Produces:
  - `CheckRegistry::all(): Check[]` (37 checks, ordered by group then importance); `CheckRegistry::speed(): Check[]`.
  - `Analyzer::VERSION = '2.0.0'`; `__construct(Fetcher $fetcher, CertProbe $cert, ?callable $clock = null)`; `analyze(string $url, string $keyword): array`.
    - Failure shape: `['ok' => false, 'code' => 'invalid'|'blocked'|'unreachable'|'http_error'|'not_html', 'message' => string]`.
    - Success shape: `['ok' => true, 'report' => array]` where the report is `['engine_version' => string, 'url' => string, 'keyword' => string, 'created_at' => int, 'is_education' => bool, 'speed_done' => bool, 'checks' => row[], 'summary' => Scorer output]` and each row is `['id', 'group', 'severity', 'title', 'why', 'status', 'evidence', 'message']`.
  - `Analyzer::applySpeed(array $report, ?array $psi): array` (replaces the three speed rows, sets `speed_done` true, rescoring).
  - `ReportView::free(array $report): array` (`url, keyword, is_education, speed_done, summary, labels`); `ReportView::full(array $report): array` (free plus `checks`).

- [ ] **Step 1: Write the failing tests**

`tests/unit/CheckRegistryTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\CheckRegistry;
use PHPUnit\Framework\TestCase;

final class CheckRegistryTest extends TestCase
{
    public function test_has_37_checks_with_unique_ids(): void
    {
        $ids = array_map(static fn($c) => $c->id(), CheckRegistry::all());
        $this->assertCount(37, $ids);
        $this->assertSame($ids, array_unique($ids));
    }

    public function test_group_sizes_match_the_spec(): void
    {
        $counts = array_count_values(array_map(static fn($c) => $c->group(), CheckRegistry::all()));
        $this->assertSame(['onpage' => 12, 'technical' => 8, 'ai' => 10, 'speed' => 3, 'education' => 4], $counts);
    }

    public function test_every_check_file_is_registered(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/includes/engine/Checks/*/*.php');
        $this->assertCount(count(CheckRegistry::all()), $files);
    }

    public function test_severities_are_one_to_three(): void
    {
        foreach (CheckRegistry::all() as $check) {
            $this->assertContains($check->severity(), [1, 2, 3], $check->id());
        }
    }
}
```

`tests/unit/AnalyzerTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use HaloAudit\Analyzer;
use HaloAudit\Fetcher;
use HaloAudit\ReportView;
use HaloAudit\Response;
use HaloAudit\Tests\Support\FakeHttp;
use HaloAudit\Tests\Support\FixedCert;
use HaloAudit\Tests\Support\Make;
use HaloAudit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private FakeHttp $http;
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $guard = new UrlGuard(static fn(string $host): array => $host === 'intranet.example' ? ['10.0.0.9'] : ['93.184.216.34']);
        $this->analyzer = new Analyzer(new Fetcher($this->http, $guard), new FixedCert(90), static fn(): int => Make::NOW);
    }

    private function html(string $extraHead = ''): string
    {
        return '<!doctype html><html lang="en"><head><title>MBA Programme at Example Business School India</title>' . $extraHead
            . '</head><body><main><h1>MBA Programme</h1>' . Make::words(320) . '<a href="/a">a</a><a href="/b">b</a></main></body></html>';
    }

    private function servePage(string $url = 'https://example.com/courses/mba/', string $extraHead = ''): void
    {
        $this->http->routes[$url] = Response::make(200, $this->html($extraHead), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function test_successful_report_shape(): void
    {
        $this->servePage();
        $out = $this->analyzer->analyze('example.com/courses/mba/', 'MBA');
        $this->assertTrue($out['ok']);
        $report = $out['report'];
        $this->assertSame('2.0.0', $report['engine_version']);
        $this->assertSame('https://example.com/courses/mba/', $report['url']);
        $this->assertSame('MBA', $report['keyword']);
        $this->assertSame(Make::NOW, $report['created_at']);
        $this->assertFalse($report['speed_done']);
        $this->assertFalse($report['is_education']);
        $this->assertCount(33, $report['checks']); // 37 minus 4 education extras
        $this->assertSame(['id', 'group', 'severity', 'title', 'why', 'status', 'evidence', 'message'], array_keys($report['checks'][0]));
        $this->assertIsInt($report['summary']['overall']);
        $this->assertNull($report['summary']['groups']['speed']['score']);
    }

    public function test_side_file_failures_are_not_measured_not_failed(): void
    {
        $this->servePage(); // robots, sitemap, llms all have no route: transport error
        $rows = array_column($this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report']['checks'], 'status', 'id');
        $this->assertSame('not_measured', $rows['ai_crawlers']);
        $this->assertSame('not_measured', $rows['robots_reachable']);
        $this->assertSame('not_measured', $rows['sitemap']);
        $this->assertSame('not_measured', $rows['llms_txt']);
    }

    public function test_education_site_gets_extras_and_same_score(): void
    {
        $this->servePage();
        $plain = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];

        $this->servePage('https://example.edu.in/courses/mba/');
        $edu = $this->analyzer->analyze('https://example.edu.in/courses/mba/', 'mba')['report'];

        $this->assertTrue($edu['is_education']);
        $this->assertCount(37, $edu['checks']);
        $this->assertSame($plain['summary']['overall'], $edu['summary']['overall']);
    }

    /** @dataProvider failures */
    public function test_unreadable_pages_give_no_score(string $url, ?Response $response, string $code): void
    {
        if ($response !== null) {
            $this->http->routes[$url] = $response;
        }
        $out = $this->analyzer->analyze($url, 'mba');
        $this->assertFalse($out['ok']);
        $this->assertSame($code, $out['code']);
        $this->assertNotSame('', $out['message']);
        $this->assertArrayNotHasKey('report', $out);
    }

    public function failures(): array
    {
        return [
            'empty' => ['', null, 'invalid'],
            'private host' => ['https://intranet.example/', null, 'blocked'],
            'timeout' => ['https://example.com/down', null, 'unreachable'],
            '404' => ['https://example.com/missing', Response::make(404, 'nope', ['Content-Type' => 'text/html']), 'http_error'],
            'bot wall' => ['https://example.com/wall', Response::make(403, 'denied', ['Content-Type' => 'text/html']), 'http_error'],
            'pdf' => ['https://example.com/file.pdf', Response::make(200, '%PDF-1.7', ['Content-Type' => 'application/pdf']), 'not_html'],
        ];
    }

    public function test_http_error_message_names_the_status(): void
    {
        $this->http->routes['https://example.com/wall'] = Response::make(403, 'denied', ['Content-Type' => 'text/html']);
        $this->assertStringContainsString('403', $this->analyzer->analyze('https://example.com/wall', 'mba')['message']);
    }

    public function test_apply_speed_fills_the_group_and_rescales(): void
    {
        $this->servePage();
        $report = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];

        $done = Analyzer::applySpeed($report, ['score' => 95, 'lcp_ms' => 1800.0, 'cls' => 0.01]);
        $this->assertTrue($done['speed_done']);
        $this->assertSame(100, $done['summary']['groups']['speed']['score']);
        $this->assertCount(33, $done['checks']);

        $failed = Analyzer::applySpeed($report, null);
        $this->assertTrue($failed['speed_done']);
        $this->assertNull($failed['summary']['groups']['speed']['score']);
        $this->assertSame($report['summary']['overall'], $failed['summary']['overall']);
    }

    public function test_free_view_hides_findings_and_full_view_shows_them(): void
    {
        $this->servePage();
        $report = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];
        $free = ReportView::free($report);
        $this->assertArrayNotHasKey('checks', $free);
        $this->assertSame($report['summary'], $free['summary']);
        $this->assertSame('AI readiness', $free['labels']['ai']);
        $this->assertStringNotContainsString('evidence', json_encode($free));
        $this->assertCount(33, ReportView::full($report)['checks']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'CheckRegistryTest|AnalyzerTest'`
Expected: errors, `Class "HaloAudit\CheckRegistry" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/CheckRegistry.php`:

```php
<?php
namespace HaloAudit;

use HaloAudit\Checks\Ai;
use HaloAudit\Checks\Education as Edu;
use HaloAudit\Checks\OnPage;
use HaloAudit\Checks\Speed;
use HaloAudit\Checks\Technical;

/** The ordered list of checks. To add a check: one new class file, one new line here. */
final class CheckRegistry
{
    /** @return Check[] */
    public static function all(): array
    {
        return array_merge([
            new OnPage\TitleLength(),
            new OnPage\KeywordInTitle(),
            new OnPage\MetaDescription(),
            new OnPage\SingleH1(),
            new OnPage\KeywordInH1(),
            new OnPage\HeadingOrder(),
            new OnPage\KeywordInUrl(),
            new OnPage\Canonical(),
            new OnPage\Noindex(),
            new OnPage\WordCount(),
            new OnPage\ImageAlt(),
            new OnPage\InternalLinks(),

            new Technical\Https(),
            new Technical\Certificate(),
            new Technical\RedirectChain(),
            new Technical\RobotsReachable(),
            new Technical\Sitemap(),
            new Technical\SecurityHeaders(),
            new Technical\StagingLeaks(),
            new Technical\JsRendering(),

            new Ai\AiCrawlers(),
            new Ai\LlmsTxt(),
            new Ai\JsonLd(),
            new Ai\SchemaTypes(),
            new Ai\FaqSchema(),
            new Ai\SemanticHtml(),
            new Ai\OpenGraph(),
            new Ai\TwitterCard(),
            new Ai\OgImage(),
            new Ai\Feed(),
        ], self::speed(), [
            new Edu\ApplyLink(),
            new Edu\FeeVisible(),
            new Edu\EducationSchema(),
            new Edu\SitemapFreshness(),
        ]);
    }

    /** @return Check[] */
    public static function speed(): array
    {
        return [new Speed\PsiScore(), new Speed\Lcp(), new Speed\Cls()];
    }
}
```

`includes/engine/Analyzer.php`:

```php
<?php
namespace HaloAudit;

final class Analyzer
{
    public const VERSION = '2.0.0';
    private const SIDE_FILE_BUDGET_SECONDS = 10;

    private Fetcher $fetcher;
    private CertProbe $cert;
    /** @var callable():int */
    private $clock;

    public function __construct(Fetcher $fetcher, CertProbe $cert, ?callable $clock = null)
    {
        $this->fetcher = $fetcher;
        $this->cert = $cert;
        $this->clock = $clock ?? 'time';
    }

    public function analyze(string $url, string $keyword): array
    {
        $url = Url::normalise($url);
        if ($url === '' || Url::host($url) === '') {
            return self::failure('invalid', 'Enter the full address of a web page.');
        }

        $response = $this->fetcher->get($url, 15, 3000000);
        if ($response->error !== null) {
            if (strpos($response->error, 'blocked') === 0) {
                return self::failure('blocked', 'This address cannot be checked.');
            }
            return self::failure('unreachable', 'We could not read this page. The site did not answer in time.');
        }
        if ($response->status >= 400) {
            return self::failure('http_error', 'We could not read this page. The site answered with HTTP ' . $response->status . '.');
        }
        $type = strtolower((string) $response->header('content-type'));
        if ($type !== '' && strpos($type, 'html') === false) {
            return self::failure('not_html', 'This address is not a web page.');
        }

        $this->fetcher->setDeadline(microtime(true) + self::SIDE_FILE_BUDGET_SECONDS);

        $finalUrl = $response->finalUrl !== '' ? $response->finalUrl : $url;
        $page = PageFacts::fromHtml($response->body, $finalUrl);
        $site = SiteProbe::collect($this->fetcher, $this->cert, $page, $response);
        $context = new Context($finalUrl, trim($keyword), $page, $site, (int) ($this->clock)());

        $isEducation = Education::detect(Url::host($finalUrl), $page->jsonLd()['types']);

        $rows = [];
        foreach (CheckRegistry::all() as $check) {
            if ($check->group() === 'education' && !$isEducation) {
                continue;
            }
            $rows[] = self::row($check, $context);
        }

        return ['ok' => true, 'report' => [
            'engine_version' => self::VERSION,
            'url' => $finalUrl,
            'keyword' => trim($keyword),
            'created_at' => $context->now,
            'is_education' => $isEducation,
            'speed_done' => false,
            'checks' => $rows,
            'summary' => Scorer::score($rows),
        ]];
    }

    /** @param array{score:int,lcp_ms:float,cls:float}|null $psi */
    public static function applySpeed(array $report, ?array $psi): array
    {
        $site = new SiteFacts();
        $site->page = Response::make(200);
        $site->psi = $psi;
        $context = new Context($report['url'], $report['keyword'], PageFacts::fromHtml('', $report['url']), $site, (int) $report['created_at']);

        $fresh = [];
        foreach (CheckRegistry::speed() as $check) {
            $fresh[$check->id()] = self::row($check, $context);
        }
        foreach ($report['checks'] as $i => $row) {
            if (isset($fresh[$row['id']])) {
                $report['checks'][$i] = $fresh[$row['id']];
            }
        }

        $report['speed_done'] = true;
        $report['summary'] = Scorer::score($report['checks']);
        return $report;
    }

    private static function row(Check $check, Context $context): array
    {
        try {
            $result = $check->run($context);
        } catch (\Throwable $e) {
            $result = Result::notMeasured('This check could not run');
        }
        return [
            'id' => $check->id(),
            'group' => $check->group(),
            'severity' => $check->severity(),
            'title' => $check->title(),
            'why' => $check->why(),
            'status' => $result->status,
            'evidence' => $result->evidence,
            'message' => $check->fix($result->status),
        ];
    }

    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
```

`includes/engine/ReportView.php`:

```php
<?php
namespace HaloAudit;

final class ReportView
{
    /** What a visitor sees before the email gate: scores and counts only. */
    public static function free(array $report): array
    {
        return [
            'url' => $report['url'],
            'keyword' => $report['keyword'],
            'is_education' => (bool) $report['is_education'],
            'speed_done' => (bool) $report['speed_done'],
            'summary' => $report['summary'],
            'labels' => Scorer::LABELS,
        ];
    }

    /** The free view plus every finding. */
    public static function full(array $report): array
    {
        return self::free($report) + ['checks' => $report['checks']];
    }
}
```

- [ ] **Step 4: Run the whole unit suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add check registry, analyzer and report views"
```

---

### Task 14: WordPress adapters and the new bootstrap

This task removes the old public stack from the load path. The old admin class stays loaded until Task 20. The front-end form returns in Task 19.

**Files:**
- Create: `includes/engine/Wp/WpHttpClient.php`, `Wp/StreamCertProbe.php`, `Wp/Plugin.php`
- Modify: `seo-analyzer.php` (replace the whole file)
- Delete: `tests/integration/HotfixTest.php` methods that no longer apply (see Step 4)
- Test: `tests/integration/AdaptersTest.php`

**Interfaces:**
- Consumes: `HttpClient`, `Response`, `CertProbe`, `Fetcher`, `UrlGuard`, `Analyzer`.
- Produces:
  - `HaloAudit\Wp\WpHttpClient implements HttpClient`.
  - `HaloAudit\Wp\StreamCertProbe implements CertProbe`.
  - `HaloAudit\Wp\Plugin::boot(): void`; `Plugin::analyzer(): Analyzer`; `Plugin::http(): HttpClient`. Filters `halo_audit_dns_resolver` (callable or null) and `halo_audit_cert_probe` (`CertProbe`).
  - Constants `SEO_ANALYZER_VERSION = '2.0.0'`, `SEO_ANALYZER_PLUGIN_DIR`, `SEO_ANALYZER_PLUGIN_URL`, `SEO_ANALYZER_PLUGIN_FILE`.

- [ ] **Step 1: Write the failing test**

`tests/integration/AdaptersTest.php`:

```php
<?php
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\WpHttpClient;

class Halo_Adapters_Test extends WP_UnitTestCase
{
    public function test_http_client_maps_a_response_and_does_not_follow_redirects(): void
    {
        $seen = [];
        add_filter('pre_http_request', static function ($pre, $args, $url) use (&$seen) {
            $seen = $args;
            return ['headers' => ['Location' => 'https://example.com/next', 'X-A' => ['1', '2']], 'body' => 'hello', 'response' => ['code' => 301, 'message' => 'Moved'], 'cookies' => []];
        }, 10, 3);

        $response = (new WpHttpClient())->request('GET', 'https://example.com/', 7, 1234);

        $this->assertSame(301, $response->status);
        $this->assertSame('hello', $response->body);
        $this->assertSame('https://example.com/next', $response->header('location'));
        $this->assertSame('1, 2', $response->header('x-a'));
        $this->assertSame(0, $seen['redirection']);
        $this->assertSame(7, $seen['timeout']);
        $this->assertSame(1234, $seen['limit_response_size']);
        $this->assertTrue($seen['sslverify']);
        $this->assertTrue($seen['reject_unsafe_urls']);
        $this->assertStringContainsString('HaloAudit', $seen['user-agent']);
    }

    public function test_http_client_turns_wp_error_into_error_response(): void
    {
        add_filter('pre_http_request', static fn() => new WP_Error('http_request_failed', 'cURL error 28'));
        $response = (new WpHttpClient())->request('GET', 'https://example.com/', 5, 1000);
        $this->assertSame('cURL error 28', $response->error);
    }

    public function test_plugin_builds_an_analyzer_that_blocks_private_hosts(): void
    {
        $out = Plugin::analyzer()->analyze('http://127.0.0.1/', 'x');
        $this->assertFalse($out['ok']);
        $this->assertSame('blocked', $out['code']);
    }

    public function test_old_public_ajax_actions_are_gone(): void
    {
        foreach (['perform_seo_analysis', 'fetch_page_title', 'send_otp', 'verify_otp_and_save_data', 'download_pdf_report', 'test_wp_mail'] as $action) {
            $this->assertFalse(has_action('wp_ajax_nopriv_' . $action), $action);
        }
        $this->assertSame('2.0.0', SEO_ANALYZER_VERSION);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run the integration command with `--filter Halo_Adapters_Test`.
Expected: errors, `Class "HaloAudit\Wp\WpHttpClient" not found`.

- [ ] **Step 3: Write the adapters and the bootstrap**

`includes/engine/Wp/WpHttpClient.php`:

```php
<?php
namespace HaloAudit\Wp;

use HaloAudit\HttpClient;
use HaloAudit\Response;

final class WpHttpClient implements HttpClient
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; HaloAudit/2.0; +https://thrivemattic.com/ai-tools/seo-analysis/)';

    public function request(string $method, string $url, int $timeout, int $maxBytes, array $headers = []): Response
    {
        $result = wp_safe_remote_request($url, [
            'method' => $method,
            'timeout' => $timeout,
            'redirection' => 0,
            'limit_response_size' => $maxBytes,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'user-agent' => self::USER_AGENT,
            'headers' => $headers,
        ]);

        if (is_wp_error($result)) {
            return Response::error($result->get_error_message());
        }

        $flat = [];
        foreach (wp_remote_retrieve_headers($result) as $name => $value) {
            $flat[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return Response::make((int) wp_remote_retrieve_response_code($result), (string) wp_remote_retrieve_body($result), $flat);
    }
}
```

`includes/engine/Wp/StreamCertProbe.php`:

```php
<?php
namespace HaloAudit\Wp;

use HaloAudit\CertProbe;

final class StreamCertProbe implements CertProbe
{
    public function daysLeft(string $host): ?int
    {
        // Peer verification is off on purpose: the expiry of an invalid certificate must still be readable.
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $socket = @stream_socket_client('ssl://' . $host . ':443', $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return null;
        }
        $params = stream_context_get_params($socket);
        fclose($socket);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $certificate ? openssl_x509_parse($certificate) : false;
        if (!$info || empty($info['validTo_time_t'])) {
            return null;
        }
        return (int) floor(($info['validTo_time_t'] - time()) / 86400);
    }
}
```

`includes/engine/Wp/Plugin.php`:

```php
<?php
namespace HaloAudit\Wp;

use HaloAudit\Analyzer;
use HaloAudit\Fetcher;
use HaloAudit\HttpClient;
use HaloAudit\UrlGuard;

final class Plugin
{
    public static function boot(): void
    {
    }

    public static function http(): HttpClient
    {
        return new WpHttpClient();
    }

    public static function analyzer(): Analyzer
    {
        $guard = new UrlGuard(apply_filters('halo_audit_dns_resolver', null));
        $cert = apply_filters('halo_audit_cert_probe', new StreamCertProbe());
        return new Analyzer(new Fetcher(self::http(), $guard), $cert);
    }
}
```

`seo-analyzer.php` (replace the whole file):

```php
<?php
/**
 * Plugin Name: SEO Analyzer
 * Plugin URI: https://thrivemattic.com/ai-tools/seo-analysis/
 * Description: Halo Audit. Scores one page for on-page SEO, technical health, AI readiness and speed.
 * Version: 2.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: thrivemattic
 * Author URI: https://thrivemattic.com/
 * Text Domain: seo-analyzer
 */

if (!defined('WPINC')) {
    die;
}

define('SEO_ANALYZER_VERSION', '2.0.0');
define('SEO_ANALYZER_PLUGIN_FILE', __FILE__);
define('SEO_ANALYZER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SEO_ANALYZER_PLUGIN_URL', plugin_dir_url(__FILE__));

if (!file_exists(SEO_ANALYZER_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>SEO Analyzer: run <code>composer install --no-dev</code> in the plugin folder.</p></div>';
    });
    return;
}

require_once SEO_ANALYZER_PLUGIN_DIR . 'vendor/autoload.php';
require_once SEO_ANALYZER_PLUGIN_DIR . 'includes/class-seo-analyzer-mailer.php';

// Old admin screens. Replaced in a later commit.
function seo_analyzer_is_addon_openai_active() {
    return false;
}
require_once SEO_ANALYZER_PLUGIN_DIR . 'admin/class-seo-analyzer-admin.php';
if (is_admin()) {
    new Seo_Analyzer_Admin('seo-analyzer', SEO_ANALYZER_VERSION);
}

\HaloAudit\Wp\Plugin::boot();
```

- [ ] **Step 4: Trim the hotfix test**

In `tests/integration/HotfixTest.php` delete the methods `test_open_endpoints_are_gone` and `test_cleanup_hook_is_gone`. `AdaptersTest` now covers the first; the second cannot regress because the function no longer exists.

- [ ] **Step 5: Run both suites**

```bash
vendor/bin/phpunit
npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin vendor/bin/phpunit -c phpunit.integration.xml
```

Expected: both pass.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: add WordPress HTTP and certificate adapters, new bootstrap"
```

---

### Task 15: `Installer` and `ReportStore`

**Files:**
- Create: `includes/engine/Wp/Installer.php`, `Wp/ReportStore.php`
- Modify: `includes/engine/Wp/Plugin.php` (`boot`)
- Test: `tests/integration/ReportStoreTest.php`

**Interfaces:**
- Consumes: report array from `Analyzer`.
- Produces:
  - `Installer::DB_VERSION = '2'`; `Installer::CRON_HOOK = 'halo_audit_purge'`; `Installer::maybeUpgrade(): void`; `Installer::resultsTable(): string`; `Installer::otpTable(): string`.
  - Option `halo_audit_psi_key` (migrated from the old `seo_analyzer_api_keys` table, which is then dropped).
  - `ReportStore::create(array $report): string` (32 hex chars); `get(string $key): ?array` returning `['key', 'report', 'name', 'email', 'lead_verified_at']`; `update(string $key, array $report): void`; `attachLead(string $key, string $name, string $email): void`; `purgeUnverified(int $days = 7): int`; `ReportStore::isKey(string $key): bool`.

- [ ] **Step 1: Write the failing test**

`tests/integration/ReportStoreTest.php`:

```php
<?php
use HaloAudit\Wp\Installer;
use HaloAudit\Wp\ReportStore;

class Halo_Report_Store_Test extends WP_UnitTestCase
{
    private function report(): array
    {
        return [
            'engine_version' => '2.0.0', 'url' => 'https://example.com/', 'keyword' => 'mba', 'created_at' => time(),
            'is_education' => true, 'speed_done' => false,
            'checks' => [['id' => 'https', 'group' => 'technical', 'severity' => 3, 'title' => 'T', 'why' => 'W', 'status' => 'pass', 'evidence' => 'E', 'message' => '']],
            'summary' => ['groups' => [], 'overall' => 81, 'grade' => 'B'],
        ];
    }

    public function test_schema_has_the_new_columns(): void
    {
        global $wpdb;
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . Installer::resultsTable());
        foreach (['report_key', 'engine_version', 'grade', 'is_education', 'lead_verified_at', 'scan_id'] as $column) {
            $this->assertContains($column, $columns);
        }
        $otp = $wpdb->get_col('SHOW COLUMNS FROM ' . Installer::otpTable());
        $this->assertContains('code_hash', $otp);
        $this->assertContains('attempts', $otp);
        $this->assertNotContains('otp', $otp);
        $this->assertSame(Installer::DB_VERSION, get_option('halo_audit_db_version'));
        $this->assertNotFalse(wp_next_scheduled(Installer::CRON_HOOK));
    }

    public function test_create_and_get_round_trip(): void
    {
        $store = new ReportStore();
        $key = $store->create($this->report());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);

        $row = $store->get($key);
        $this->assertSame('https://example.com/', $row['report']['url']);
        $this->assertSame(81, $row['report']['summary']['overall']);
        $this->assertNull($row['email']);
        $this->assertNull($row['lead_verified_at']);
    }

    public function test_get_rejects_bad_and_unknown_keys(): void
    {
        $store = new ReportStore();
        $this->assertNull($store->get("1' OR '1'='1"));
        $this->assertNull($store->get(str_repeat('a', 32)));
        $this->assertFalse(ReportStore::isKey('ABC'));
    }

    public function test_update_replaces_the_report(): void
    {
        $store = new ReportStore();
        $key = $store->create($this->report());
        $changed = $this->report();
        $changed['speed_done'] = true;
        $changed['summary']['overall'] = 90;
        $changed['summary']['grade'] = 'A';
        $store->update($key, $changed);

        global $wpdb;
        $this->assertTrue($store->get($key)['report']['speed_done']);
        $this->assertSame('A', $wpdb->get_var($wpdb->prepare('SELECT grade FROM ' . Installer::resultsTable() . ' WHERE report_key = %s', $key)));
    }

    public function test_attach_lead_sets_name_email_and_time(): void
    {
        $store = new ReportStore();
        $key = $store->create($this->report());
        $store->attachLead($key, 'Asha Rao', 'asha@example.com');
        $row = $store->get($key);
        $this->assertSame('Asha Rao', $row['name']);
        $this->assertSame('asha@example.com', $row['email']);
        $this->assertNotNull($row['lead_verified_at']);
    }

    public function test_purge_removes_only_old_unverified_new_style_reports(): void
    {
        global $wpdb;
        $store = new ReportStore();
        $table = Installer::resultsTable();

        $oldUnverified = $store->create($this->report());
        $oldVerified = $store->create($this->report());
        $fresh = $store->create($this->report());
        $store->attachLead($oldVerified, 'A', 'a@example.com');
        $wpdb->query($wpdb->prepare("UPDATE $table SET created_at = %s WHERE report_key IN (%s, %s)", gmdate('Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS), $oldUnverified, $oldVerified));
        $wpdb->insert($table, ['name' => 'Legacy', 'email' => 'l@example.com', 'url' => 'https://old.example/', 'keyword' => 'k', 'results' => '{}', 'created_at' => '2024-01-01 00:00:00']);

        $this->assertSame(1, $store->purgeUnverified());
        $this->assertNull($store->get($oldUnverified));
        $this->assertNotNull($store->get($oldVerified));
        $this->assertNotNull($store->get($fresh));
        $this->assertSame('1', $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE name = 'Legacy'"));
    }

    public function test_psi_key_is_migrated_from_the_old_table(): void
    {
        global $wpdb;
        $old = $wpdb->prefix . 'seo_analyzer_api_keys';
        $wpdb->query("CREATE TABLE $old (id mediumint(9) NOT NULL AUTO_INCREMENT, api_key varchar(255) NOT NULL, PRIMARY KEY (id))");
        $wpdb->insert($old, ['api_key' => 'OLDKEY']);
        delete_option('halo_audit_psi_key');
        delete_option('halo_audit_db_version');

        Installer::maybeUpgrade();

        $this->assertSame('OLDKEY', get_option('halo_audit_psi_key'));
        $this->assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old)));
    }
}
```

Note: `WP_UnitTestCase` turns `CREATE TABLE` into `CREATE TEMPORARY TABLE` inside tests. `SHOW TABLES` does not list temporary tables, so the last assertion holds whether or not the drop ran. The option assertion is the one that proves the migration. `ALTER TABLE` inside `Installer::upgrade()` commits the test transaction, so keep this method last in the class.

- [ ] **Step 2: Run test to verify it fails**

Run the integration command with `--filter Halo_Report_Store_Test`.
Expected: errors, `Class "HaloAudit\Wp\Installer" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/Wp/Installer.php`:

```php
<?php
namespace HaloAudit\Wp;

final class Installer
{
    public const DB_VERSION = '2';
    public const CRON_HOOK = 'halo_audit_purge';

    public static function resultsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'seo_analyzer_results';
    }

    public static function otpTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'seo_analyzer_otp';
    }

    public static function maybeUpgrade(): void
    {
        if (get_option('halo_audit_db_version') !== self::DB_VERSION) {
            self::upgrade();
            update_option('halo_audit_db_version', self::DB_VERSION, false);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    private static function upgrade(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $results = self::resultsTable();
        $otp = self::otpTable();

        dbDelta("CREATE TABLE $results (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            report_key char(32) DEFAULT NULL,
            name varchar(100) DEFAULT NULL,
            email varchar(100) DEFAULT NULL,
            url varchar(255) NOT NULL,
            keyword varchar(255) NOT NULL,
            competitor_url varchar(255) DEFAULT NULL,
            results longtext NOT NULL,
            competitor_results longtext,
            overall_score float DEFAULT NULL,
            competitor_overall_score float DEFAULT NULL,
            grade char(1) DEFAULT NULL,
            engine_version varchar(20) DEFAULT NULL,
            is_education tinyint(1) NOT NULL DEFAULT 0,
            lead_verified_at datetime DEFAULT NULL,
            scan_id varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY report_key (report_key),
            KEY created_at (created_at)
        ) $charset;");

        // dbDelta does not relax NOT NULL on existing columns.
        $wpdb->query("ALTER TABLE $results MODIFY name varchar(100) DEFAULT NULL, MODIFY email varchar(100) DEFAULT NULL");

        // Codes live 15 minutes, so the old table holds nothing worth keeping.
        $wpdb->query("DROP TABLE IF EXISTS $otp");
        dbDelta("CREATE TABLE $otp (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            code_hash varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY email (email)
        ) $charset;");

        self::migratePsiKey();
    }

    private static function migratePsiKey(): void
    {
        global $wpdb;
        $old = $wpdb->prefix . 'seo_analyzer_api_keys';
        $suppress = $wpdb->suppress_errors(true);
        $key = $wpdb->get_var("SELECT api_key FROM $old ORDER BY id ASC LIMIT 1");
        $wpdb->suppress_errors($suppress);

        if (is_string($key) && $key !== '' && !get_option('halo_audit_psi_key')) {
            update_option('halo_audit_psi_key', $key, false);
        }
        $wpdb->query("DROP TABLE IF EXISTS $old");
    }
}
```

`includes/engine/Wp/ReportStore.php`:

```php
<?php
namespace HaloAudit\Wp;

final class ReportStore
{
    public static function isKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $key) === 1;
    }

    public function create(array $report): string
    {
        global $wpdb;
        $key = bin2hex(random_bytes(16));
        $wpdb->insert(Installer::resultsTable(), [
            'report_key' => $key,
            'url' => mb_substr($report['url'], 0, 255),
            'keyword' => mb_substr($report['keyword'], 0, 255),
            'results' => wp_json_encode($report),
            'overall_score' => $report['summary']['overall'],
            'grade' => $report['summary']['grade'],
            'engine_version' => $report['engine_version'],
            'is_education' => $report['is_education'] ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $key;
    }

    public function get(string $key): ?array
    {
        global $wpdb;
        if (!self::isKey($key)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::resultsTable() . ' WHERE report_key = %s', $key), ARRAY_A);
        if (!$row) {
            return null;
        }
        $report = json_decode($row['results'], true);
        if (!is_array($report)) {
            return null;
        }
        return [
            'key' => $key,
            'report' => $report,
            'name' => $row['name'],
            'email' => $row['email'],
            'lead_verified_at' => $row['lead_verified_at'],
        ];
    }

    public function update(string $key, array $report): void
    {
        global $wpdb;
        if (!self::isKey($key)) {
            return;
        }
        $wpdb->update(Installer::resultsTable(), [
            'results' => wp_json_encode($report),
            'overall_score' => $report['summary']['overall'],
            'grade' => $report['summary']['grade'],
        ], ['report_key' => $key]);
    }

    public function attachLead(string $key, string $name, string $email): void
    {
        global $wpdb;
        if (!self::isKey($key)) {
            return;
        }
        $wpdb->update(Installer::resultsTable(), [
            'name' => mb_substr($name, 0, 100),
            'email' => mb_substr($email, 0, 100),
            'lead_verified_at' => gmdate('Y-m-d H:i:s'),
        ], ['report_key' => $key]);
    }

    public function purgeUnverified(int $days = 7): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Installer::resultsTable() . ' WHERE report_key IS NOT NULL AND lead_verified_at IS NULL AND created_at < %s',
            $cutoff
        ));
    }
}
```

In `includes/engine/Wp/Plugin.php` replace the empty `boot` method with:

```php
    public static function boot(): void
    {
        Installer::maybeUpgrade();
        add_action(Installer::CRON_HOOK, static function (): void {
            (new ReportStore())->purgeUnverified();
        });
        register_deactivation_hook(SEO_ANALYZER_PLUGIN_FILE, static function (): void {
            wp_clear_scheduled_hook(Installer::CRON_HOOK);
        });
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run the integration command with `--filter Halo_Report_Store_Test`.
Expected: `OK (7 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add schema migration and server-side report store"
```

---

### Task 16: `RateLimiter` and `Gate`

**Files:**
- Create: `includes/engine/Wp/RateLimiter.php`, `Wp/Gate.php`
- Test: `tests/integration/GateTest.php`

**Interfaces:**
- Consumes: `ReportStore::attachLead()`, `Installer::otpTable()`, `Seo_Analyzer_Mailer::send_email()`.
- Produces:
  - `RateLimiter::allow(string $bucket, string $key, int $limit, int $windowSeconds): bool` (counts the call when it returns true).
  - `Gate::COOKIE = 'halo_audit_unlock'`; `Gate::__construct(ReportStore $store)`; `send(string $email, string $ip)` returns `true` or `WP_Error`; `verify(string $email, string $code, string $name, string $reportKey)` returns a cookie token `string` or `WP_Error`; `token(string $name, string $email, int $expires): string`; `parse(string $token): ?array` (`['name', 'email']`); `current(): ?array`.
  - Filter `halo_audit_send_code` `(null, string $email, string $code)`: return a bool to replace the mailer (used by tests).
  - Error codes: `halo_email` 400, `halo_rate` 429, `halo_mail` 502, `halo_code` 400, `halo_code_void` 400.

- [ ] **Step 1: Write the failing test**

`tests/integration/GateTest.php`:

```php
<?php
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Installer;
use HaloAudit\Wp\RateLimiter;
use HaloAudit\Wp\ReportStore;

class Halo_Gate_Test extends WP_UnitTestCase
{
    private Gate $gate;
    private ReportStore $store;
    private string $code = '';
    private string $key;

    public function set_up(): void
    {
        parent::set_up();
        $this->store = new ReportStore();
        $this->gate = new Gate($this->store);
        add_filter('halo_audit_send_code', function ($sent, $email, $code) {
            $this->code = $code;
            return true;
        }, 10, 3);
        $this->key = $this->store->create([
            'engine_version' => '2.0.0', 'url' => 'https://example.com/', 'keyword' => 'k', 'created_at' => time(),
            'is_education' => false, 'speed_done' => false, 'checks' => [], 'summary' => ['groups' => [], 'overall' => 50, 'grade' => 'C'],
        ]);
    }

    public function test_rate_limiter_counts_and_blocks(): void
    {
        $this->assertTrue(RateLimiter::allow('t', 'a', 2, 60));
        $this->assertTrue(RateLimiter::allow('t', 'a', 2, 60));
        $this->assertFalse(RateLimiter::allow('t', 'a', 2, 60));
        $this->assertTrue(RateLimiter::allow('t', 'b', 2, 60));
    }

    public function test_code_is_six_digits_and_stored_only_as_a_hash(): void
    {
        global $wpdb;
        $this->assertTrue($this->gate->send('asha@example.com', '203.0.113.5'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->code);
        $row = $wpdb->get_row('SELECT * FROM ' . Installer::otpTable(), ARRAY_A);
        $this->assertNotSame($this->code, $row['code_hash']);
        $this->assertStringNotContainsString($this->code, $row['code_hash']);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->assertSame('halo_email', $this->gate->send('not-an-email', '203.0.113.5')->get_error_code());
    }

    public function test_fourth_code_for_one_email_in_an_hour_is_refused(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->assertTrue($this->gate->send('asha@example.com', '203.0.113.' . $i));
        }
        $error = $this->gate->send('asha@example.com', '203.0.113.9');
        $this->assertSame('halo_rate', $error->get_error_code());
        $this->assertSame(429, $error->get_error_data()['status']);
    }

    public function test_eleventh_code_from_one_ip_is_refused(): void
    {
        foreach (range(1, 10) as $i) {
            $this->assertTrue($this->gate->send("user$i@example.com", '203.0.113.5'));
        }
        $this->assertSame('halo_rate', $this->gate->send('user11@example.com', '203.0.113.5')->get_error_code());
    }

    public function test_right_code_saves_the_lead_and_returns_a_token(): void
    {
        $this->gate->send('Asha@Example.com', '203.0.113.5');
        $token = $this->gate->verify('asha@example.com', $this->code, 'Asha Rao', $this->key);

        $this->assertIsString($token);
        $this->assertSame(['name' => 'Asha Rao', 'email' => 'asha@example.com'], $this->gate->parse($token));
        $row = $this->store->get($this->key);
        $this->assertSame('asha@example.com', $row['email']);
        $this->assertNotNull($row['lead_verified_at']);
    }

    public function test_a_code_works_once(): void
    {
        $this->gate->send('asha@example.com', '203.0.113.5');
        $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key);
        $this->assertWPError($this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key));
    }

    public function test_five_wrong_tries_void_the_code(): void
    {
        $this->gate->send('asha@example.com', '203.0.113.5');
        foreach (range(1, 5) as $i) {
            $this->assertSame('halo_code', $this->gate->verify('asha@example.com', '000000', 'Asha', $this->key)->get_error_code());
        }
        $this->assertSame('halo_code_void', $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key)->get_error_code());
        $this->assertNull($this->store->get($this->key)['email']);
    }

    public function test_expired_code_is_refused(): void
    {
        global $wpdb;
        $this->gate->send('asha@example.com', '203.0.113.5');
        $wpdb->query('UPDATE ' . Installer::otpTable() . " SET expires_at = '2000-01-01 00:00:00'");
        $this->assertSame('halo_code', $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key)->get_error_code());
    }

    public function test_tampered_or_expired_tokens_do_not_parse(): void
    {
        $good = $this->gate->token('Asha', 'asha@example.com', time() + 60);
        $this->assertNotNull($this->gate->parse($good));
        $this->assertNull($this->gate->parse($good . 'x'));
        $this->assertNull($this->gate->parse(str_replace('.', '.A', $good)));
        $this->assertNull($this->gate->parse($this->gate->token('Asha', 'asha@example.com', time() - 1)));
        $this->assertNull($this->gate->parse('garbage'));
    }

    public function test_current_reads_the_cookie(): void
    {
        $this->assertNull($this->gate->current());
        $_COOKIE[Gate::COOKIE] = $this->gate->token('Asha', 'asha@example.com', time() + 60);
        $this->assertSame('asha@example.com', $this->gate->current()['email']);
        unset($_COOKIE[Gate::COOKIE]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run the integration command with `--filter Halo_Gate_Test`.
Expected: errors, `Class "HaloAudit\Wp\Gate" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/Wp/RateLimiter.php`:

```php
<?php
namespace HaloAudit\Wp;

final class RateLimiter
{
    /** Fixed window. The window starts at the first counted call and does not slide. */
    public static function allow(string $bucket, string $key, int $limit, int $windowSeconds): bool
    {
        $name = 'halo_rl_' . md5($bucket . '|' . $key);
        $state = get_transient($name);
        $now = time();

        if (!is_array($state) || $state['until'] <= $now) {
            $state = ['count' => 0, 'until' => $now + $windowSeconds];
        }
        if ($state['count'] >= $limit) {
            return false;
        }
        $state['count']++;
        set_transient($name, $state, max(1, $state['until'] - $now));
        return true;
    }
}
```

`includes/engine/Wp/Gate.php`:

```php
<?php
namespace HaloAudit\Wp;

final class Gate
{
    public const COOKIE = 'halo_audit_unlock';
    public const UNLOCK_SECONDS = 30 * DAY_IN_SECONDS;
    public const CODE_SECONDS = 15 * MINUTE_IN_SECONDS;
    public const MAX_ATTEMPTS = 5;

    private ReportStore $store;

    public function __construct(ReportStore $store)
    {
        $this->store = $store;
    }

    /** @return true|\WP_Error */
    public function send(string $email, string $ip)
    {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if (!is_email($email)) {
            return new \WP_Error('halo_email', 'Enter a valid email address.', ['status' => 400]);
        }
        if (!RateLimiter::allow('otp_email', $email, 3, HOUR_IN_SECONDS) || !RateLimiter::allow('otp_ip', $ip, 10, HOUR_IN_SECONDS)) {
            return new \WP_Error('halo_rate', 'Too many codes were requested. Try again in one hour.', ['status' => 429]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $table = Installer::otpTable();
        $wpdb->delete($table, ['email' => $email]);
        $wpdb->insert($table, [
            'email' => $email,
            'code_hash' => wp_hash_password($code),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::CODE_SECONDS),
            'attempts' => 0,
        ]);

        $sent = apply_filters('halo_audit_send_code', null, $email, $code);
        if ($sent === null) {
            $sent = \Seo_Analyzer_Mailer::send_email($email, 'Your Halo Audit code', "Your Halo Audit code is: $code\n\nThe code is valid for 15 minutes.");
        }
        return $sent ? true : new \WP_Error('halo_mail', 'We could not send the code. Try again.', ['status' => 502]);
    }

    /** @return string|\WP_Error the unlock cookie token */
    public function verify(string $email, string $code, string $name, string $reportKey)
    {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        $name = sanitize_text_field($name);
        $table = Installer::otpTable();
        $bad = new \WP_Error('halo_code', 'That code is not correct or has expired.', ['status' => 400]);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email = %s ORDER BY id DESC LIMIT 1", $email), ARRAY_A);
        if (!$row || strtotime($row['expires_at'] . ' UTC') < time()) {
            return $bad;
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            $wpdb->delete($table, ['email' => $email]);
            return new \WP_Error('halo_code_void', 'Too many wrong tries. Request a new code.', ['status' => 400]);
        }
        if (!preg_match('/^\d{6}$/', $code) || !wp_check_password($code, $row['code_hash'])) {
            $wpdb->query($wpdb->prepare("UPDATE $table SET attempts = attempts + 1 WHERE id = %d", $row['id']));
            return $bad;
        }

        $wpdb->delete($table, ['email' => $email]);
        $this->store->attachLead($reportKey, $name, $email);
        return $this->token($name, $email, time() + self::UNLOCK_SECONDS);
    }

    public function token(string $name, string $email, int $expires): string
    {
        $payload = rtrim(strtr(base64_encode((string) wp_json_encode(['n' => $name, 'e' => $email, 'x' => $expires])), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    /** @return array{name:string,email:string}|null */
    public function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], wp_salt('auth')), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!is_array($data) || empty($data['e']) || empty($data['x']) || (int) $data['x'] < time()) {
            return null;
        }
        return ['name' => (string) ($data['n'] ?? ''), 'email' => (string) $data['e']];
    }

    /** @return array{name:string,email:string}|null */
    public function current(): ?array
    {
        if (empty($_COOKIE[self::COOKIE]) || !is_string($_COOKIE[self::COOKIE])) {
            return null;
        }
        return $this->parse(wp_unslash($_COOKIE[self::COOKIE]));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run the integration command with `--filter Halo_Gate_Test`.
Expected: `OK (11 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add rate limiter and server-side email gate"
```

---

### Task 17: REST routes and the scan slot

**Files:**
- Create: `includes/engine/ScanClient.php`, `includes/engine/NullScanClient.php`, `includes/engine/Wp/RestController.php`
- Modify: `includes/engine/Wp/Plugin.php`
- Test: `tests/integration/RestTest.php`

**Interfaces:**
- Consumes: `Plugin::analyzer()`, `Plugin::http()`, `ReportStore`, `Gate`, `RateLimiter`, `PsiClient`, `Analyzer::applySpeed()`, `ReportView`.
- Produces:
  - `interface ScanClient { start(string $reportKey): array; status(string $scanId): array; result(string $scanId): array; }` Each returns at least `['available' => bool]`. `NullScanClient` always returns `['available' => false]`.
  - `RestController::NS = 'halo-audit/v1'`; `RestController::NONCE_ACTION = 'halo_audit'`; header `X-Halo-Nonce`; `RestController::__construct(ReportStore $store, Gate $gate, ScanClient $scan)`; `register(): void`; `RestController::clientIp(): string`.
  - Routes: `GET /nonce`, `POST /analyze`, `POST /speed`, `POST /gate/send`, `POST /gate/verify`, `GET /report/(?P<id>[a-f0-9]{32})`, `GET /report/(?P<id>[a-f0-9]{32})/pdf` (added in Task 18), `POST /scan`, `GET /scan/(?P<id>[A-Za-z0-9_-]{1,64})`.
  - Filters: `halo_audit_client_ip` (string), `halo_audit_scan_client` (`ScanClient`).
  - `Plugin::store(): ReportStore`, `Plugin::gate(): Gate`.

- [ ] **Step 1: Write the failing test**

`tests/integration/RestTest.php`:

```php
<?php
use HaloAudit\CertProbe;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

class Halo_Rest_Test extends WP_UnitTestCase
{
    private string $code = '';

    public function set_up(): void
    {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');

        add_filter('halo_audit_dns_resolver', static fn() => static fn(string $host): array => ['93.184.216.34']);
        add_filter('halo_audit_cert_probe', static fn() => new class implements CertProbe {
            public function daysLeft(string $host): ?int { return 90; }
        });
        add_filter('halo_audit_send_code', function ($sent, $email, $code) {
            $this->code = $code;
            return true;
        }, 10, 3);
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    }

    public function tear_down(): void
    {
        unset($_COOKIE[Gate::COOKIE]);
        parent::tear_down();
    }

    public function fake_http($pre, $args, $url)
    {
        $ok = static fn(string $body, string $type = 'text/html') => ['headers' => ['content-type' => $type], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        if ($url === 'https://example.com/page') {
            return $ok('<html><head><title>MBA Programme at Example Business School India</title></head><body><h1>MBA</h1><p>' . str_repeat('word ', 320) . '</p></body></html>');
        }
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false) {
            return $ok('{"lighthouseResult":{"categories":{"performance":{"score":0.95}},"audits":{"largest-contentful-paint":{"numericValue":1800},"cumulative-layout-shift":{"numericValue":0.01}}}}', 'application/json');
        }
        return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => []];
    }

    private function call(string $method, string $route, array $params = [], bool $withNonce = true): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/' . RestController::NS . $route);
        if ($withNonce) {
            $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        }
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    private function analyze(): array
    {
        return $this->call('POST', '/analyze', ['url' => 'example.com/page', 'keyword' => 'mba'])->get_data();
    }

    public function test_nonce_route_returns_a_valid_nonce(): void
    {
        $data = $this->call('GET', '/nonce', [], false)->get_data();
        $this->assertSame(1, wp_verify_nonce($data['nonce'], RestController::NONCE_ACTION));
    }

    public function test_routes_refuse_a_missing_nonce(): void
    {
        $this->assertSame(403, $this->call('POST', '/analyze', ['url' => 'example.com/page', 'keyword' => 'mba'], false)->get_status());
    }

    public function test_analyze_returns_the_free_view_only(): void
    {
        $data = $this->analyze();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $data['report_id']);
        $this->assertFalse($data['unlocked']);
        $this->assertIsInt($data['summary']['overall']);
        $this->assertArrayNotHasKey('checks', $data);
    }

    public function test_analyze_reports_unreadable_pages_with_422(): void
    {
        $response = $this->call('POST', '/analyze', ['url' => 'https://example.com/missing', 'keyword' => 'mba']);
        $this->assertSame(422, $response->get_status());
        $this->assertStringContainsString('404', $response->get_data()['message']);
    }

    public function test_eleventh_analysis_from_one_ip_is_refused(): void
    {
        foreach (range(1, 10) as $i) {
            $this->assertSame(200, $this->call('POST', '/analyze', ['url' => 'example.com/page', 'keyword' => 'mba'])->get_status());
        }
        $this->assertSame(429, $this->call('POST', '/analyze', ['url' => 'example.com/page', 'keyword' => 'mba'])->get_status());
    }

    public function test_client_ip_filter_is_used(): void
    {
        add_filter('halo_audit_client_ip', static fn() => '198.51.100.7');
        $this->assertSame('198.51.100.7', RestController::clientIp());
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        remove_all_filters('halo_audit_client_ip');
        $this->assertSame('203.0.113.5', RestController::clientIp());
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function test_full_report_is_refused_without_unlock(): void
    {
        $id = $this->analyze()['report_id'];
        $this->assertSame(403, $this->call('GET', '/report/' . $id)->get_status());
    }

    public function test_gate_flow_unlocks_the_full_report_and_saves_the_lead(): void
    {
        $id = $this->analyze()['report_id'];
        $this->assertSame(200, $this->call('POST', '/gate/send', ['email' => 'asha@example.com', 'report_id' => $id])->get_status());

        $verified = $this->call('POST', '/gate/verify', ['email' => 'asha@example.com', 'code' => $this->code, 'name' => 'Asha Rao', 'report_id' => $id]);
        $this->assertSame(200, $verified->get_status());
        $this->assertNotEmpty($verified->get_data()['checks']);
        $this->assertSame('asha@example.com', Plugin::store()->get($id)['email']);
    }

    public function test_unlock_cookie_opens_later_reports_and_records_the_lead(): void
    {
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha Rao', 'asha@example.com', time() + 60);
        $data = $this->analyze();
        $this->assertTrue($data['unlocked']);

        $full = $this->call('GET', '/report/' . $data['report_id']);
        $this->assertSame(200, $full->get_status());
        $this->assertNotEmpty($full->get_data()['checks']);
        $this->assertSame('asha@example.com', Plugin::store()->get($data['report_id'])['email']);
    }

    public function test_wrong_code_is_refused_and_report_stays_locked(): void
    {
        $id = $this->analyze()['report_id'];
        $this->call('POST', '/gate/send', ['email' => 'asha@example.com', 'report_id' => $id]);
        $this->assertSame(400, $this->call('POST', '/gate/verify', ['email' => 'asha@example.com', 'code' => '000000', 'name' => 'A', 'report_id' => $id])->get_status());
        $this->assertNull(Plugin::store()->get($id)['email']);
    }

    public function test_speed_fills_the_speed_group(): void
    {
        update_option('halo_audit_psi_key', 'KEY');
        $id = $this->analyze()['report_id'];
        $data = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertTrue($data['speed_done']);
        $this->assertSame(100, $data['summary']['groups']['speed']['score']);
        $this->assertArrayNotHasKey('checks', $data);
    }

    public function test_unknown_report_is_404(): void
    {
        $this->assertSame(404, $this->call('POST', '/speed', ['report_id' => str_repeat('a', 32)])->get_status());
    }

    public function test_scan_slot_says_not_available(): void
    {
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);
        $id = $this->analyze()['report_id'];
        $this->assertFalse($this->call('POST', '/scan', ['report_id' => $id])->get_data()['available']);
        $this->assertFalse($this->call('GET', '/scan/abc123')->get_data()['available']);
    }

    public function test_scan_is_refused_without_unlock(): void
    {
        $id = $this->analyze()['report_id'];
        $this->assertSame(403, $this->call('POST', '/scan', ['report_id' => $id])->get_status());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run the integration command with `--filter Halo_Rest_Test`.
Expected: errors, `Class "HaloAudit\Wp\RestController" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/ScanClient.php`:

```php
<?php
namespace HaloAudit;

/** The slot for the live AI visibility scan. The real client arrives with the scan server. */
interface ScanClient
{
    /** @return array{available:bool,scan_id?:string} */
    public function start(string $reportKey): array;

    /** @return array{available:bool,state?:string} */
    public function status(string $scanId): array;

    /** @return array{available:bool,result?:array} */
    public function result(string $scanId): array;
}
```

`includes/engine/NullScanClient.php`:

```php
<?php
namespace HaloAudit;

final class NullScanClient implements ScanClient
{
    public function start(string $reportKey): array { return ['available' => false]; }
    public function status(string $scanId): array { return ['available' => false]; }
    public function result(string $scanId): array { return ['available' => false]; }
}
```

`includes/engine/Wp/RestController.php`:

```php
<?php
namespace HaloAudit\Wp;

use HaloAudit\Analyzer;
use HaloAudit\PsiClient;
use HaloAudit\ReportView;
use HaloAudit\ScanClient;

final class RestController
{
    public const NS = 'halo-audit/v1';
    public const NONCE_ACTION = 'halo_audit';

    private ReportStore $store;
    private Gate $gate;
    private ScanClient $scan;

    public function __construct(ReportStore $store, Gate $gate, ScanClient $scan)
    {
        $this->store = $store;
        $this->gate = $gate;
        $this->scan = $scan;
    }

    public function register(): void
    {
        $nonce = [$this, 'nonceOk'];
        $unlocked = [$this, 'unlockedOk'];
        $id = '(?P<id>[a-f0-9]{32})';

        register_rest_route(self::NS, '/nonce', ['methods' => 'GET', 'callback' => [$this, 'nonce'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/analyze', ['methods' => 'POST', 'callback' => [$this, 'analyze'], 'permission_callback' => $nonce]);
        register_rest_route(self::NS, '/speed', ['methods' => 'POST', 'callback' => [$this, 'speed'], 'permission_callback' => $nonce]);
        register_rest_route(self::NS, '/gate/send', ['methods' => 'POST', 'callback' => [$this, 'gateSend'], 'permission_callback' => $nonce]);
        register_rest_route(self::NS, '/gate/verify', ['methods' => 'POST', 'callback' => [$this, 'gateVerify'], 'permission_callback' => $nonce]);
        register_rest_route(self::NS, '/report/' . $id, ['methods' => 'GET', 'callback' => [$this, 'report'], 'permission_callback' => $unlocked]);
        register_rest_route(self::NS, '/scan', ['methods' => 'POST', 'callback' => [$this, 'scanStart'], 'permission_callback' => $unlocked]);
        register_rest_route(self::NS, '/scan/(?P<id>[A-Za-z0-9_-]{1,64})', ['methods' => 'GET', 'callback' => [$this, 'scanStatus'], 'permission_callback' => $unlocked]);
    }

    public static function clientIp(): string
    {
        // REMOTE_ADDR only. Forwarded headers are set by the visitor and are not trusted.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return (string) apply_filters('halo_audit_client_ip', $ip);
    }

    /** @return true|\WP_Error A WP_Error with an explicit 403: a bare false gives 401 to logged-out visitors. */
    public function nonceOk(\WP_REST_Request $request)
    {
        if (wp_verify_nonce((string) $request->get_header('x_halo_nonce'), self::NONCE_ACTION) === false) {
            return new \WP_Error('halo_nonce', 'Your session has expired. Try again.', ['status' => 403]);
        }
        return true;
    }

    /** @return true|\WP_Error */
    public function unlockedOk(\WP_REST_Request $request)
    {
        $nonce = $this->nonceOk($request);
        if ($nonce !== true) {
            return $nonce;
        }
        if ($this->gate->current() === null) {
            return new \WP_Error('halo_locked', 'Confirm your email to see this.', ['status' => 403]);
        }
        return true;
    }

    public function nonce(): \WP_REST_Response
    {
        $response = new \WP_REST_Response(['nonce' => wp_create_nonce(self::NONCE_ACTION)]);
        $response->header('Cache-Control', 'no-store, max-age=0');
        return $response;
    }

    public function analyze(\WP_REST_Request $request)
    {
        if (!RateLimiter::allow('analyze', self::clientIp(), 10, HOUR_IN_SECONDS)) {
            return new \WP_Error('halo_rate', 'You have run 10 audits in the last hour. Try again later.', ['status' => 429]);
        }
        $url = trim((string) $request->get_param('url'));
        $keyword = sanitize_text_field((string) $request->get_param('keyword'));
        if (strlen($url) > 2000 || mb_strlen($keyword) > 255) {
            return new \WP_Error('halo_input', 'The address or keyword is too long.', ['status' => 400]);
        }

        $out = Plugin::analyzer()->analyze($url, $keyword);
        if (!$out['ok']) {
            return new \WP_Error('halo_' . $out['code'], $out['message'], ['status' => 422]);
        }

        $key = $this->store->create($out['report']);
        $lead = $this->gate->current();
        if ($lead !== null) {
            $this->store->attachLead($key, $lead['name'], $lead['email']);
        }

        return new \WP_REST_Response(ReportView::free($out['report']) + ['report_id' => $key, 'unlocked' => $lead !== null]);
    }

    public function speed(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request->get_param('report_id'));
        if ($row === null) {
            return self::notFound();
        }
        $report = $row['report'];
        if (empty($report['speed_done'])) {
            if (!RateLimiter::allow('speed', self::clientIp(), 20, HOUR_IN_SECONDS)) {
                return new \WP_Error('halo_rate', 'Too many speed tests. Try again later.', ['status' => 429]);
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            $psi = (new PsiClient(Plugin::http(), (string) get_option('halo_audit_psi_key', '')))->run($report['url']);
            $report = Analyzer::applySpeed($report, $psi);
            $this->store->update($row['key'], $report);
        }
        return new \WP_REST_Response(ReportView::free($report) + ['report_id' => $row['key'], 'unlocked' => $this->gate->current() !== null]);
    }

    public function gateSend(\WP_REST_Request $request)
    {
        if ($this->store->get((string) $request->get_param('report_id')) === null) {
            return self::notFound();
        }
        $sent = $this->gate->send((string) $request->get_param('email'), self::clientIp());
        return is_wp_error($sent) ? $sent : new \WP_REST_Response(['sent' => true]);
    }

    public function gateVerify(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request->get_param('report_id'));
        if ($row === null) {
            return self::notFound();
        }
        $name = sanitize_text_field((string) $request->get_param('name'));
        if ($name === '') {
            return new \WP_Error('halo_name', 'Enter your name.', ['status' => 400]);
        }
        $token = $this->gate->verify((string) $request->get_param('email'), (string) $request->get_param('code'), $name, $row['key']);
        if (is_wp_error($token)) {
            return $token;
        }
        if (!headers_sent()) {
            setcookie(Gate::COOKIE, $token, [
                'expires' => time() + Gate::UNLOCK_SECONDS,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return new \WP_REST_Response(ReportView::full($row['report']) + ['report_id' => $row['key'], 'unlocked' => true]);
    }

    public function report(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request['id']);
        if ($row === null) {
            return self::notFound();
        }
        if ($row['lead_verified_at'] === null) {
            $lead = $this->gate->current();
            $this->store->attachLead($row['key'], $lead['name'], $lead['email']);
        }
        return new \WP_REST_Response(ReportView::full($row['report']) + ['report_id' => $row['key'], 'unlocked' => true]);
    }

    public function scanStart(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request->get_param('report_id'));
        return $row === null ? self::notFound() : new \WP_REST_Response($this->scan->start($row['key']));
    }

    public function scanStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->scan->status((string) $request['id']));
    }

    private static function notFound(): \WP_Error
    {
        return new \WP_Error('halo_not_found', 'This report was not found. Run the audit again.', ['status' => 404]);
    }
}
```

Replace `includes/engine/Wp/Plugin.php` with:

```php
<?php
namespace HaloAudit\Wp;

use HaloAudit\Analyzer;
use HaloAudit\Fetcher;
use HaloAudit\HttpClient;
use HaloAudit\NullScanClient;
use HaloAudit\UrlGuard;

final class Plugin
{
    private static ?ReportStore $store = null;
    private static ?Gate $gate = null;

    public static function boot(): void
    {
        Installer::maybeUpgrade();

        add_action(Installer::CRON_HOOK, static function (): void {
            self::store()->purgeUnverified();
        });
        register_deactivation_hook(SEO_ANALYZER_PLUGIN_FILE, static function (): void {
            wp_clear_scheduled_hook(Installer::CRON_HOOK);
        });

        add_action('rest_api_init', static function (): void {
            $scan = apply_filters('halo_audit_scan_client', new NullScanClient());
            (new RestController(self::store(), self::gate(), $scan))->register();
        });
    }

    public static function store(): ReportStore
    {
        return self::$store ??= new ReportStore();
    }

    public static function gate(): Gate
    {
        return self::$gate ??= new Gate(self::store());
    }

    public static function http(): HttpClient
    {
        return new WpHttpClient();
    }

    public static function analyzer(): Analyzer
    {
        $guard = new UrlGuard(apply_filters('halo_audit_dns_resolver', null));
        $cert = apply_filters('halo_audit_cert_probe', new StreamCertProbe());
        return new Analyzer(new Fetcher(self::http(), $guard), $cert);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run the integration command with `--filter Halo_Rest_Test`.
Expected: `OK (14 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: add REST routes with server-side gate and scan slot"
```

---

### Task 18: PDF

**Files:**
- Create: `includes/engine/Wp/Pdf.php`
- Modify: `includes/engine/Wp/RestController.php` (add the PDF route and callback)
- Delete: `assets/css/seo-analyzer-pdf.css`
- Test: `tests/integration/PdfTest.php`

**Interfaces:**
- Consumes: report array, `Scorer::LABELS`, `RestController::unlockedOk`.
- Produces: `Pdf::html(array $report): string`; `Pdf::render(array $report): string` (PDF bytes); `Pdf::filename(array $report): string`; route `GET /report/{id}/pdf`.

- [ ] **Step 1: Write the failing test**

`tests/integration/PdfTest.php`:

```php
<?php
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Pdf;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

class Halo_Pdf_Test extends WP_UnitTestCase
{
    private function report(): array
    {
        return [
            'engine_version' => '2.0.0', 'url' => 'https://example.com/a?x=<script>', 'keyword' => '<b>mba</b>', 'created_at' => 1760000000,
            'is_education' => false, 'speed_done' => true,
            'checks' => [
                ['id' => 'title_length', 'group' => 'onpage', 'severity' => 3, 'title' => 'Title length', 'why' => 'Why.', 'status' => 'fail', 'evidence' => '<img src="http://evil.example/x.png">', 'message' => 'Fix it.'],
                ['id' => 'https', 'group' => 'technical', 'severity' => 3, 'title' => 'Secure connection', 'why' => 'Why.', 'status' => 'pass', 'evidence' => 'Served over HTTPS', 'message' => ''],
            ],
            'summary' => ['groups' => [
                'onpage' => ['score' => 0, 'pass' => 0, 'warn' => 0, 'fail' => 1, 'not_measured' => 0],
                'technical' => ['score' => 100, 'pass' => 1, 'warn' => 0, 'fail' => 0, 'not_measured' => 0],
                'ai' => ['score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 0],
                'speed' => ['score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 3],
            ], 'overall' => 40, 'grade' => 'D'],
        ];
    }

    public function test_html_escapes_everything_that_came_from_the_page(): void
    {
        $html = Pdf::html($this->report());
        $this->assertStringNotContainsString('<img src="http://evil.example', $html);
        $this->assertStringContainsString('&lt;img src=&quot;http://evil.example/x.png&quot;&gt;', $html);
        $this->assertStringNotContainsString('<b>mba</b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_html_shows_score_grade_groups_and_not_measured(): void
    {
        $html = Pdf::html($this->report());
        $this->assertStringContainsString('40', $html);
        $this->assertStringContainsString('Grade D', $html);
        $this->assertStringContainsString('On-page for your keyword', $html);
        $this->assertStringContainsString('Not measured', $html);
        $this->assertStringContainsString('Fix it.', $html);
        $this->assertStringNotContainsString('—', $html);
    }

    public function test_render_returns_pdf_bytes(): void
    {
        $this->assertStringStartsWith('%PDF-', Pdf::render($this->report()));
    }

    public function test_filename_is_safe(): void
    {
        $this->assertSame('halo-audit-example.com.pdf', Pdf::filename($this->report()));
    }

    public function test_pdf_route_is_refused_without_unlock(): void
    {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');

        $key = Plugin::store()->create($this->report());
        $request = new WP_REST_Request('GET', '/' . RestController::NS . '/report/' . $key . '/pdf');
        $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        unset($_COOKIE[Gate::COOKIE]);

        $this->assertSame(403, rest_get_server()->dispatch($request)->get_status());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run the integration command with `--filter Halo_Pdf_Test`.
Expected: errors, `Class "HaloAudit\Wp\Pdf" not found`.

- [ ] **Step 3: Write the implementation**

`includes/engine/Wp/Pdf.php`:

```php
<?php
namespace HaloAudit\Wp;

use HaloAudit\Scorer;
use HaloAudit\Url;

final class Pdf
{
    private const STATUS_LABELS = ['pass' => 'Pass', 'warn' => 'Improve', 'fail' => 'Fix', 'not_measured' => 'Not measured'];
    private const STATUS_COLOURS = ['pass' => '#2e7d32', 'warn' => '#b26a00', 'fail' => '#c62828', 'not_measured' => '#6b6b6b'];

    public static function filename(array $report): string
    {
        $host = preg_replace('/[^a-z0-9.-]/', '', Url::host($report['url']));
        return 'halo-audit-' . ($host !== '' ? $host : 'report') . '.pdf';
    }

    /** Every dynamic value is escaped. No value from the audited page reaches mPDF as HTML. */
    public static function html(array $report): string
    {
        $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $summary = $report['summary'];

        $out = '<h1>Halo Audit</h1>';
        $out .= '<p><strong>Page:</strong> ' . $e($report['url']) . '<br><strong>Keyword:</strong> ' . $e($report['keyword'])
            . '<br><strong>Date:</strong> ' . $e(gmdate('j F Y', (int) $report['created_at'])) . '</p>';

        $out .= $summary['overall'] === null
            ? '<p class="score">Not measured</p>'
            : '<p class="score">' . $e($summary['overall']) . ' / 100 &nbsp; Grade ' . $e($summary['grade']) . '</p>';

        $out .= '<table class="groups"><tr><th>Area</th><th>Score</th><th>Pass</th><th>Improve</th><th>Fix</th></tr>';
        foreach ($summary['groups'] as $group => $data) {
            $out .= '<tr><td>' . $e(Scorer::LABELS[$group] ?? $group) . '</td><td>' . ($data['score'] === null ? 'Not measured' : $e($data['score']))
                . '</td><td>' . $e($data['pass']) . '</td><td>' . $e($data['warn']) . '</td><td>' . $e($data['fail']) . '</td></tr>';
        }
        $out .= '</table>';

        $byGroup = [];
        foreach ($report['checks'] as $row) {
            $byGroup[$row['group']][] = $row;
        }
        foreach ($byGroup as $group => $rows) {
            $out .= '<h2>' . $e(Scorer::LABELS[$group] ?? $group) . '</h2><table class="checks">';
            foreach ($rows as $row) {
                $colour = self::STATUS_COLOURS[$row['status']] ?? '#6b6b6b';
                $out .= '<tr><td class="status" style="color:' . $colour . '">' . $e(self::STATUS_LABELS[$row['status']] ?? $row['status']) . '</td><td><strong>'
                    . $e($row['title']) . '</strong><br>' . $e($row['evidence']);
                if ($row['message'] !== '') {
                    $out .= '<br><em>What to do:</em> ' . $e($row['message']);
                }
                $out .= '</td></tr>';
            }
            $out .= '</table>';
        }

        $out .= '<p class="foot">Made with Halo Audit by Thrivemattic. thrivemattic.com</p>';
        return $out;
    }

    public static function render(array $report): string
    {
        $temp = trailingslashit(get_temp_dir()) . 'halo-audit-mpdf';
        wp_mkdir_p($temp);

        $mpdf = new \Mpdf\Mpdf(['tempDir' => $temp, 'format' => 'A4', 'margin_top' => 16, 'margin_bottom' => 16]);
        $mpdf->SetTitle('Halo Audit');
        $mpdf->WriteHTML(self::css(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(self::html($report), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private static function css(): string
    {
        return 'body{font-family:dejavusans;font-size:10pt;color:#222}'
            . 'h1{font-size:20pt;margin:0 0 6pt}h2{font-size:13pt;margin:14pt 0 4pt;border-bottom:1px solid #ccc}'
            . '.score{font-size:22pt;font-weight:bold;margin:8pt 0}'
            . 'table{width:100%;border-collapse:collapse}td,th{padding:4pt 5pt;border-bottom:1px solid #e5e5e5;vertical-align:top;text-align:left}'
            . '.status{width:70pt;font-weight:bold}.foot{margin-top:18pt;font-size:8pt;color:#666}';
    }
}
```

In `includes/engine/Wp/RestController.php`:

Add this line to `register()`, directly after the `/report/` route:

```php
        register_rest_route(self::NS, '/report/' . $id . '/pdf', ['methods' => 'GET', 'callback' => [$this, 'pdf'], 'permission_callback' => $unlocked]);
```

Add this method after `report()`:

```php
    public function pdf(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request['id']);
        if ($row === null) {
            return self::notFound();
        }
        if (!RateLimiter::allow('pdf', self::clientIp(), 20, HOUR_IN_SECONDS)) {
            return new \WP_Error('halo_rate', 'Too many downloads. Try again later.', ['status' => 429]);
        }
        $bytes = Pdf::render($row['report']);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . Pdf::filename($row['report']) . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF
        exit;
    }
```

Delete the unused stylesheet:

```bash
git rm -q assets/css/seo-analyzer-pdf.css
```

- [ ] **Step 4: Run test to verify it passes**

Run the integration command with `--filter Halo_Pdf_Test`.
Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: build the PDF on the server from the stored report"
```

---

### Task 19: Shortcode and front-end

**Files:**
- Create: `includes/engine/Wp/Shortcode.php`, `public/js/halo-audit.js`, `public/css/halo-audit.css`
- Modify: `includes/engine/Wp/Plugin.php` (`boot`)
- Test: `tests/integration/ShortcodeTest.php`

**Interfaces:**
- Consumes: REST routes from Tasks 17 and 18; `RestController::NS`.
- Produces: `Shortcode::register(): void`; shortcode `[seo_analyzer title="..."]` (the attributes `popup`, `comparison`, `nlp`, `competitor` are accepted and ignored); script handle `halo-audit` with global `HaloAudit = { root: string }`.

- [ ] **Step 1: Write the failing test**

`tests/integration/ShortcodeTest.php`:

```php
<?php
class Halo_Shortcode_Test extends WP_UnitTestCase
{
    public function test_shortcode_renders_the_form_and_enqueues_assets(): void
    {
        $html = do_shortcode('[seo_analyzer title="Halo <Audit>" comparison="true" popup="true" nlp="true" competitor="true"]');

        $this->assertStringContainsString('class="halo-audit"', $html);
        $this->assertStringContainsString('Halo &lt;Audit&gt;', $html);
        $this->assertStringContainsString('name="url"', $html);
        $this->assertStringContainsString('name="keyword"', $html);
        $this->assertStringNotContainsString('competitor_url', $html);
        $this->assertTrue(wp_script_is('halo-audit', 'enqueued'));
        $this->assertTrue(wp_style_is('halo-audit', 'enqueued'));
        $this->assertStringContainsString('halo-audit\/v1\/', wp_scripts()->get_data('halo-audit', 'data'));
    }

    public function test_script_has_no_inner_html_assignment(): void
    {
        $js = file_get_contents(SEO_ANALYZER_PLUGIN_DIR . 'public/js/halo-audit.js');
        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringNotContainsString('insertAdjacentHTML', $js);
        $this->assertStringNotContainsString('document.write', $js);
        $this->assertStringNotContainsString('console.log', $js);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run the integration command with `--filter Halo_Shortcode_Test`.
Expected: failures; the shortcode is not registered, so the output is the raw shortcode text.

- [ ] **Step 3: Write the shortcode**

`includes/engine/Wp/Shortcode.php`:

```php
<?php
namespace HaloAudit\Wp;

final class Shortcode
{
    public static function register(): void
    {
        add_shortcode('seo_analyzer', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);
    }

    public static function registerAssets(): void
    {
        wp_register_style('halo-audit', SEO_ANALYZER_PLUGIN_URL . 'public/css/halo-audit.css', [], SEO_ANALYZER_VERSION);
        wp_register_script('halo-audit', SEO_ANALYZER_PLUGIN_URL . 'public/js/halo-audit.js', [], SEO_ANALYZER_VERSION, true);
        wp_localize_script('halo-audit', 'HaloAudit', ['root' => esc_url_raw(rest_url(RestController::NS . '/'))]);
    }

    public static function render($atts): string
    {
        // popup, comparison, nlp and competitor are accepted so that existing pages do not break. They do nothing.
        $atts = shortcode_atts(['title' => 'Halo Audit', 'popup' => '', 'comparison' => '', 'nlp' => '', 'competitor' => ''], $atts, 'seo_analyzer');

        if (!wp_script_is('halo-audit', 'registered')) {
            self::registerAssets();
        }
        wp_enqueue_style('halo-audit');
        wp_enqueue_script('halo-audit');

        ob_start();
        ?>
        <div class="halo-audit">
            <h2 class="halo-audit__title"><?php echo esc_html($atts['title']); ?></h2>
            <form class="halo-audit__form" novalidate>
                <label class="halo-audit__field">
                    <span>Page address</span>
                    <input type="text" name="url" inputmode="url" autocomplete="url" placeholder="Enter your URL" required>
                </label>
                <label class="halo-audit__field">
                    <span>Primary keyword</span>
                    <input type="text" name="keyword" placeholder="Enter primary keyword" maxlength="255" required>
                </label>
                <button type="submit" class="halo-audit__button">Analyse</button>
            </form>
            <p class="halo-audit__status" role="status" aria-live="polite"></p>
            <div class="halo-audit__results" hidden></div>
            <noscript><p>This tool needs JavaScript.</p></noscript>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
```

In `includes/engine/Wp/Plugin.php`, add this line at the end of `boot()`:

```php
        Shortcode::register();
```

- [ ] **Step 4: Write the script**

`public/js/halo-audit.js`:

```js
(function () {
  'use strict';

  var cfg = window.HaloAudit || {};
  var nonce = null;
  var STATUS = { pass: 'Pass', warn: 'Improve', fail: 'Fix', not_measured: 'Not measured' };

  function route(path) {
    var parts = path.split('?');
    var joiner = cfg.root.indexOf('?') === -1 ? '?' : '&';
    return cfg.root + parts[0] + (parts[1] ? joiner + parts[1] : '');
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
  }

  function clear(node) {
    while (node.firstChild) { node.removeChild(node.firstChild); }
  }

  function fetchNonce() {
    return fetch(route('nonce?t=' + Date.now()), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) { nonce = data.nonce; });
  }

  function send(method, path, body, retried) {
    var ready = nonce ? Promise.resolve() : fetchNonce();
    return ready.then(function () {
      return fetch(route(path), {
        method: method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-Halo-Nonce': nonce },
        body: body ? JSON.stringify(body) : undefined
      });
    }).then(function (response) {
      if (response.status === 403 && !retried && path.indexOf('report/') !== 0 && path.indexOf('scan') !== 0) {
        nonce = null;
        return send(method, path, body, true);
      }
      return response;
    });
  }

  function api(method, path, body) {
    return send(method, path, body, false).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) {
          var error = new Error(data.message || 'Something went wrong. Try again.');
          error.status = response.status;
          throw error;
        }
        return data;
      });
    });
  }

  function Widget(root) {
    this.root = root;
    this.form = root.querySelector('.halo-audit__form');
    this.status = root.querySelector('.halo-audit__status');
    this.results = root.querySelector('.halo-audit__results');
    this.button = root.querySelector('.halo-audit__button');
    this.data = null;
    this.form.addEventListener('submit', this.onSubmit.bind(this));
  }

  Widget.prototype.say = function (text, isError) {
    this.status.textContent = text || '';
    this.status.classList.toggle('halo-audit__status--error', !!isError);
  };

  Widget.prototype.onSubmit = function (event) {
    event.preventDefault();
    var url = this.form.elements.url.value.trim();
    var keyword = this.form.elements.keyword.value.trim();
    if (!url || !keyword) {
      this.say('Enter a page address and a keyword.', true);
      return;
    }
    var self = this;
    this.button.disabled = true;
    this.results.hidden = true;
    this.say('Reading the page. This takes up to 15 seconds.');

    api('POST', 'analyze', { url: url, keyword: keyword }).then(function (data) {
      self.data = data;
      self.say('');
      self.render();
      if (data.unlocked) { self.loadFull(); }
      return api('POST', 'speed', { report_id: data.report_id }).then(function (speed) {
        self.data.summary = speed.summary;
        self.data.speed_done = true;
        self.renderSummary();
        if (self.data.checks) { self.loadFull(); }
      }).catch(function () {
        self.data.speed_done = true;
        self.renderSummary();
      });
    }).catch(function (error) {
      self.say(error.message, true);
    }).then(function () {
      self.button.disabled = false;
    });
  };

  Widget.prototype.render = function () {
    clear(this.results);
    this.results.hidden = false;
    this.summaryBox = el('div', 'halo-audit__summary');
    this.actions = el('div', 'halo-audit__actions');
    this.gateBox = el('div', 'halo-audit__gate');
    this.findings = el('div', 'halo-audit__findings');
    this.results.appendChild(this.summaryBox);
    this.results.appendChild(this.actions);
    this.results.appendChild(this.gateBox);
    this.results.appendChild(this.findings);
    this.renderSummary();
    this.renderActions();
  };

  Widget.prototype.renderSummary = function () {
    var data = this.data;
    var summary = data.summary;
    clear(this.summaryBox);

    this.summaryBox.appendChild(el('p', 'halo-audit__url', data.url));
    var score = el('div', 'halo-audit__score');
    score.appendChild(el('span', 'halo-audit__score-number', summary.overall === null ? 'Not measured' : summary.overall));
    if (summary.grade) { score.appendChild(el('span', 'halo-audit__grade halo-audit__grade--' + summary.grade, 'Grade ' + summary.grade)); }
    this.summaryBox.appendChild(score);

    var list = el('ul', 'halo-audit__groups');
    Object.keys(summary.groups).forEach(function (key) {
      var group = summary.groups[key];
      var item = el('li', 'halo-audit__group');
      item.appendChild(el('span', 'halo-audit__group-name', data.labels[key] || key));
      var value;
      if (key === 'speed' && !data.speed_done) {
        value = 'Testing speed...';
      } else if (group.score === null) {
        value = 'Not measured';
      } else {
        value = group.score + ' / 100';
      }
      item.appendChild(el('span', 'halo-audit__group-score', value));
      item.appendChild(el('span', 'halo-audit__group-counts', group.pass + ' pass, ' + group.warn + ' to improve, ' + group.fail + ' to fix'));
      list.appendChild(item);
    });
    this.summaryBox.appendChild(list);
  };

  Widget.prototype.renderActions = function () {
    var self = this;
    clear(this.actions);
    var see = el('button', 'halo-audit__button', 'See all findings');
    see.type = 'button';
    see.addEventListener('click', function () { self.need(function () { self.loadFull(); }); });
    var pdf = el('button', 'halo-audit__button halo-audit__button--ghost', 'Download PDF');
    pdf.type = 'button';
    pdf.addEventListener('click', function () { self.need(function () { self.downloadPdf(); }); });
    this.actions.appendChild(see);
    this.actions.appendChild(pdf);
  };

  Widget.prototype.need = function (then) {
    if (this.data.unlocked) { then(); return; }
    this.renderGate(then);
  };

  Widget.prototype.renderGate = function (then) {
    var self = this;
    var box = this.gateBox;
    clear(box);
    box.appendChild(el('p', 'halo-audit__gate-intro', 'Enter your name and email. We send a 6-digit code to confirm the address.'));

    var name = el('input'); name.type = 'text'; name.placeholder = 'Your name'; name.autocomplete = 'name';
    var email = el('input'); email.type = 'email'; email.placeholder = 'Work email'; email.autocomplete = 'email';
    var sendButton = el('button', 'halo-audit__button', 'Send code'); sendButton.type = 'button';
    var code = el('input'); code.type = 'text'; code.inputMode = 'numeric'; code.maxLength = 6; code.placeholder = '6-digit code'; code.hidden = true;
    var verifyButton = el('button', 'halo-audit__button', 'Confirm'); verifyButton.type = 'button'; verifyButton.hidden = true;
    var note = el('p', 'halo-audit__status');
    [name, email, sendButton, code, verifyButton, note].forEach(function (node) { box.appendChild(node); });

    function say(text, isError) {
      note.textContent = text;
      note.classList.toggle('halo-audit__status--error', !!isError);
    }

    sendButton.addEventListener('click', function () {
      if (!name.value.trim() || !email.value.trim()) { say('Enter your name and email.', true); return; }
      sendButton.disabled = true;
      api('POST', 'gate/send', { email: email.value.trim(), report_id: self.data.report_id }).then(function () {
        code.hidden = false;
        verifyButton.hidden = false;
        sendButton.textContent = 'Send a new code';
        say('We sent a code to ' + email.value.trim() + '. It is valid for 15 minutes.');
        code.focus();
      }).catch(function (error) { say(error.message, true); }).then(function () { sendButton.disabled = false; });
    });

    verifyButton.addEventListener('click', function () {
      verifyButton.disabled = true;
      api('POST', 'gate/verify', {
        email: email.value.trim(), name: name.value.trim(), code: code.value.trim(), report_id: self.data.report_id
      }).then(function (full) {
        self.data.unlocked = true;
        self.data.checks = full.checks;
        clear(box);
        self.renderFindings();
        then();
      }).catch(function (error) { say(error.message, true); }).then(function () { verifyButton.disabled = false; });
    });

    name.focus();
  };

  Widget.prototype.loadFull = function () {
    var self = this;
    api('GET', 'report/' + this.data.report_id).then(function (full) {
      self.data.checks = full.checks;
      self.data.summary = full.summary;
      self.renderSummary();
      self.renderFindings();
    }).catch(function (error) {
      if (error.status === 403) { self.data.unlocked = false; self.renderGate(function () { self.loadFull(); }); return; }
      self.say(error.message, true);
    });
  };

  Widget.prototype.renderFindings = function () {
    var data = this.data;
    var box = this.findings;
    clear(box);
    if (!data.checks) { return; }

    var groups = {};
    data.checks.forEach(function (row) { (groups[row.group] = groups[row.group] || []).push(row); });

    Object.keys(groups).forEach(function (key) {
      var section = el('section', 'halo-audit__section');
      section.appendChild(el('h3', 'halo-audit__section-title', data.labels[key] || key));
      groups[key].forEach(function (row) {
        var item = el('div', 'halo-audit__check halo-audit__check--' + row.status);
        item.appendChild(el('span', 'halo-audit__badge', STATUS[row.status] || row.status));
        var body = el('div', 'halo-audit__check-body');
        body.appendChild(el('strong', null, row.title));
        if (row.evidence) { body.appendChild(el('p', 'halo-audit__evidence', row.evidence)); }
        if (row.message) { body.appendChild(el('p', 'halo-audit__fix', row.message)); }
        body.appendChild(el('p', 'halo-audit__why', row.why));
        item.appendChild(body);
        section.appendChild(item);
      });
      box.appendChild(section);
    });

    var scan = el('section', 'halo-audit__section halo-audit__scan');
    scan.appendChild(el('h3', 'halo-audit__section-title', 'Live AI visibility'));
    scan.appendChild(el('p', null, 'Coming soon. This will show whether ChatGPT, Gemini and Google AI Overviews name your organisation.'));
    box.appendChild(scan);
  };

  Widget.prototype.downloadPdf = function () {
    var self = this;
    send('GET', 'report/' + this.data.report_id + '/pdf', null, false).then(function (response) {
      if (!response.ok) { throw new Error('The PDF could not be made. Try again.'); }
      return response.blob();
    }).then(function (blob) {
      var link = el('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'halo-audit.pdf';
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
    }).catch(function (error) { self.say(error.message, true); });
  };

  function boot() {
    var nodes = document.querySelectorAll('.halo-audit');
    for (var i = 0; i < nodes.length; i++) { new Widget(nodes[i]); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
```

- [ ] **Step 5: Write the stylesheet**

`public/css/halo-audit.css`:

```css
.halo-audit {
  --halo-ink: #1f2421;
  --halo-muted: #5f6661;
  --halo-line: #dfe3dc;
  --halo-accent: #6b7a3a;
  --halo-pass: #2e7d32;
  --halo-warn: #b26a00;
  --halo-fail: #c62828;
  color: var(--halo-ink);
  font: inherit;
  max-width: 880px;
  margin: 0 auto;
}

.halo-audit__form { display: grid; gap: 12px; grid-template-columns: 1fr; }
@media (min-width: 720px) { .halo-audit__form { grid-template-columns: 2fr 1fr auto; align-items: end; } }

.halo-audit__field { display: grid; gap: 4px; font-size: 0.875rem; }
.halo-audit input {
  width: 100%; box-sizing: border-box; padding: 12px 14px; font: inherit;
  border: 1px solid var(--halo-line); border-radius: 6px; background: #fff; color: var(--halo-ink);
}
.halo-audit input:focus-visible, .halo-audit__button:focus-visible { outline: 2px solid var(--halo-accent); outline-offset: 2px; }

.halo-audit__button {
  padding: 12px 20px; font: inherit; font-weight: 700; cursor: pointer;
  border: 1px solid var(--halo-accent); border-radius: 6px; background: var(--halo-accent); color: #fff;
}
.halo-audit__button[disabled] { opacity: 0.6; cursor: wait; }
.halo-audit__button--ghost { background: transparent; color: var(--halo-accent); }

.halo-audit__status { min-height: 1.5em; margin: 12px 0; color: var(--halo-muted); }
.halo-audit__status--error { color: var(--halo-fail); }

.halo-audit__url { word-break: break-all; color: var(--halo-muted); margin: 0 0 8px; }
.halo-audit__score { display: flex; align-items: baseline; gap: 16px; margin-bottom: 16px; }
.halo-audit__score-number { font-size: 3.5rem; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.halo-audit__grade { font-weight: 700; padding: 4px 10px; border-radius: 4px; background: #eef1e6; }

.halo-audit__groups { list-style: none; margin: 0 0 20px; padding: 0; border-top: 1px solid var(--halo-line); }
.halo-audit__group { display: grid; grid-template-columns: 1fr auto; gap: 2px 16px; padding: 12px 0; border-bottom: 1px solid var(--halo-line); }
.halo-audit__group-name { font-weight: 700; }
.halo-audit__group-score { font-variant-numeric: tabular-nums; }
.halo-audit__group-counts { grid-column: 1 / -1; font-size: 0.875rem; color: var(--halo-muted); }

.halo-audit__actions { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.halo-audit__gate { display: grid; gap: 10px; max-width: 420px; margin-bottom: 20px; }
.halo-audit__gate:empty { display: none; }

.halo-audit__section { margin-top: 28px; }
.halo-audit__section-title { margin: 0 0 8px; }
.halo-audit__check { display: grid; grid-template-columns: 110px 1fr; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--halo-line); }
@media (max-width: 520px) { .halo-audit__check { grid-template-columns: 1fr; } }
.halo-audit__badge { font-weight: 700; font-size: 0.875rem; }
.halo-audit__check--pass .halo-audit__badge { color: var(--halo-pass); }
.halo-audit__check--warn .halo-audit__badge { color: var(--halo-warn); }
.halo-audit__check--fail .halo-audit__badge { color: var(--halo-fail); }
.halo-audit__check--not_measured .halo-audit__badge { color: var(--halo-muted); }
.halo-audit__check-body p { margin: 4px 0 0; }
.halo-audit__evidence { color: var(--halo-muted); word-break: break-word; }
.halo-audit__fix { font-weight: 600; }
.halo-audit__why { font-size: 0.875rem; color: var(--halo-muted); }
.halo-audit__scan { padding: 16px; border: 1px dashed var(--halo-line); border-radius: 6px; }
```

- [ ] **Step 6: Run the tests and check the page by hand**

Run the integration command with `--filter Halo_Shortcode_Test`.
Expected: `OK (2 tests, ...)`.

Then check in a browser:

```bash
npx wp-env run cli wp post create --post_type=page --post_status=publish --post_title="Audit" --post_content='[seo_analyzer title="Halo Audit"]' --porcelain
```

Open `http://localhost:8888/?page_id=<the printed number>`. Run an audit on `https://example.com` with keyword `example`. Confirm: a score and grade show; Speed shows "Testing speed..." then a value or "Not measured"; "See all findings" opens the name, email and code form. Read the code from the mail log with `npx wp-env logs` only if a mail catcher is configured; otherwise this step ends at the code form, and the gate itself is already proven by `Halo_Rest_Test`.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: add shortcode and front-end for the new engine"
```

---

### Task 20: Admin screens

**Files:**
- Create: `includes/engine/Wp/Admin/Admin.php`, `Wp/Admin/Settings.php`, `Wp/Admin/ReportsTable.php`
- Modify: `seo-analyzer.php` (drop the old admin lines), `includes/engine/Wp/Plugin.php` (`boot`)
- Delete: `admin/` (whole folder), `tests/integration/HotfixTest.php`
- Test: `tests/integration/AdminTest.php`

**Interfaces:**
- Consumes: `Installer::resultsTable()`.
- Produces:
  - `Admin::register(): void`; menu slug `seo-analyzer` with submenus `seo-analyzer-settings` and `seo-analyzer-reports`; `admin_post_halo_audit_export` (capability `manage_options` + nonce `halo_audit_export`).
  - `Admin::csvCell(string $value): string` (neutralises spreadsheet formulas); `Admin::exportRows(): array`.
  - `Settings::GROUP = 'halo_audit_settings'`; options `halo_audit_psi_key`, `seo_analyzer_brevo_api_key`, `seo_analyzer_sender_email`, `halo_audit_scan_url`, `halo_audit_scan_secret`.
  - `ReportsTable extends \WP_List_Table`, 20 rows per page.

- [ ] **Step 1: Write the failing test**

`tests/integration/AdminTest.php`:

```php
<?php
use HaloAudit\Wp\Admin\Admin;
use HaloAudit\Wp\Admin\ReportsTable;
use HaloAudit\Wp\Admin\Settings;
use HaloAudit\Wp\Installer;

class Halo_Admin_Test extends WP_UnitTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/template.php';
    }

    public function tear_down(): void
    {
        unset($_REQUEST['_wpnonce']);
        parent::tear_down();
    }

    private function seed(int $n): void
    {
        global $wpdb;
        for ($i = 1; $i <= $n; $i++) {
            $wpdb->insert(Installer::resultsTable(), [
                'report_key' => md5('k' . $i), 'name' => 'Lead ' . $i, 'email' => "lead$i@example.com", 'url' => "https://example.com/$i",
                'keyword' => 'k', 'results' => '{}', 'overall_score' => 50, 'grade' => 'C', 'lead_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    public function test_export_is_refused_for_logged_out_visitors(): void
    {
        wp_set_current_user(0);
        $this->expectException(WPDieException::class);
        Admin::export();
    }

    public function test_export_is_refused_for_subscribers(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $_REQUEST['_wpnonce'] = wp_create_nonce('halo_audit_export');
        $this->expectException(WPDieException::class);
        Admin::export();
    }

    public function test_export_is_refused_for_admin_without_nonce(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->expectException(WPDieException::class);
        Admin::export();
    }

    public function test_export_rows_hold_only_verified_leads(): void
    {
        global $wpdb;
        $this->seed(2);
        $wpdb->insert(Installer::resultsTable(), ['report_key' => md5('anon'), 'url' => 'https://anon.example/', 'keyword' => 'k', 'results' => '{}']);
        $rows = Admin::exportRows();
        $this->assertCount(2, $rows);
        $this->assertSame(['name', 'email', 'url', 'keyword', 'overall_score', 'grade', 'is_education', 'created_at'], array_keys($rows[0]));
    }

    public function test_csv_cells_cannot_start_a_formula(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil\")", Admin::csvCell('=HYPERLINK("http://evil")'));
        $this->assertSame("'+1", Admin::csvCell('+1'));
        $this->assertSame("'-1", Admin::csvCell('-1'));
        $this->assertSame("'@x", Admin::csvCell('@x'));
        $this->assertSame('Asha', Admin::csvCell('Asha'));
    }

    public function test_reports_table_is_paginated(): void
    {
        $this->seed(25);
        set_current_screen('toplevel_page_seo-analyzer');
        $table = new ReportsTable();
        $table->prepare_items();
        $this->assertCount(20, $table->items);
        $this->assertSame(25, $table->get_pagination_arg('total_items'));
    }

    public function test_settings_are_registered_with_sanitisers(): void
    {
        Settings::register();
        $registered = get_registered_settings();
        foreach (['halo_audit_psi_key', 'seo_analyzer_brevo_api_key', 'seo_analyzer_sender_email', 'halo_audit_scan_url', 'halo_audit_scan_secret'] as $option) {
            $this->assertArrayHasKey($option, $registered, $option);
            $this->assertSame(Settings::GROUP, $registered[$option]['group']);
        }
        $this->assertSame('', sanitize_option('halo_audit_scan_url', 'javascript:alert(1)'));
        $this->assertSame('https://scan.example.com/', sanitize_option('halo_audit_scan_url', 'https://scan.example.com/'));
        $this->assertSame('', sanitize_option('seo_analyzer_sender_email', 'not an email'));
    }

    public function test_old_admin_class_is_gone(): void
    {
        $this->assertFalse(class_exists('Seo_Analyzer_Admin'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run the integration command with `--filter Halo_Admin_Test`.
Expected: errors, `Class "HaloAudit\Wp\Admin\Admin" not found`.

- [ ] **Step 3: Write the admin classes**

`includes/engine/Wp/Admin/Settings.php`:

```php
<?php
namespace HaloAudit\Wp\Admin;

final class Settings
{
    public const GROUP = 'halo_audit_settings';
    public const PAGE = 'seo-analyzer-settings';

    /** option => [label, input type, help] */
    private const FIELDS = [
        'halo_audit_psi_key' => ['Google PageSpeed API key', 'password', 'Used for the Speed group. Without a key Google allows only a few tests per day.'],
        'seo_analyzer_brevo_api_key' => ['Brevo API key', 'password', 'Sends the email code. Without a key WordPress mail is used.'],
        'seo_analyzer_sender_email' => ['Sender email', 'email', 'The From address of the email code.'],
        'halo_audit_scan_url' => ['Scan server URL', 'url', 'For the live AI visibility scan. Leave empty until the scan server is ready.'],
        'halo_audit_scan_secret' => ['Scan server secret', 'password', 'Shared secret for the scan server.'],
    ];

    public static function register(): void
    {
        $text = ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''];
        register_setting(self::GROUP, 'halo_audit_psi_key', $text);
        register_setting(self::GROUP, 'seo_analyzer_brevo_api_key', $text);
        register_setting(self::GROUP, 'halo_audit_scan_secret', $text);
        register_setting(self::GROUP, 'seo_analyzer_sender_email', ['type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => '']);
        register_setting(self::GROUP, 'halo_audit_scan_url', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeUrl'], 'default' => '']);

        add_settings_section('halo_audit_main', '', '__return_false', self::PAGE);
        foreach (self::FIELDS as $option => $field) {
            add_settings_field($option, $field[0], [self::class, 'field'], self::PAGE, 'halo_audit_main', ['option' => $option, 'label_for' => $option]);
        }
    }

    public static function sanitizeUrl($value): string
    {
        return esc_url_raw(trim((string) $value), ['https']);
    }

    public static function field(array $args): void
    {
        $option = $args['option'];
        [, $type, $help] = self::FIELDS[$option];
        printf(
            '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="off"><p class="description">%4$s</p>',
            esc_attr($type),
            esc_attr($option),
            esc_attr((string) get_option($option, '')),
            esc_html($help)
        );
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html(get_admin_page_title()) . '</h1><form action="options.php" method="post">';
        settings_fields(self::GROUP);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form></div>';
    }
}
```

`includes/engine/Wp/Admin/ReportsTable.php`:

```php
<?php
namespace HaloAudit\Wp\Admin;

use HaloAudit\Wp\Installer;

if (!class_exists('\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ReportsTable extends \WP_List_Table
{
    private const PER_PAGE = 20;

    public function get_columns(): array
    {
        return [
            'created_at' => 'Date', 'name' => 'Name', 'email' => 'Email', 'url' => 'Page',
            'keyword' => 'Keyword', 'overall_score' => 'Score', 'grade' => 'Grade', 'is_education' => 'Education',
        ];
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $table = Installer::resultsTable();
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $offset = ($this->get_pagenum() - 1) * self::PER_PAGE;

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, email, url, keyword, overall_score, grade, is_education, created_at FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",
            self::PER_PAGE,
            $offset
        ), ARRAY_A);
        $this->set_pagination_args(['total_items' => $total, 'per_page' => self::PER_PAGE]);
    }

    public function column_default($item, $column_name): string
    {
        $value = $item[$column_name] ?? '';
        if ($column_name === 'is_education') {
            return $value ? 'Yes' : 'No';
        }
        if ($column_name === 'overall_score') {
            return $value === null || $value === '' ? 'Not measured' : esc_html((string) (int) round((float) $value));
        }
        if (($column_name === 'name' || $column_name === 'email') && ($value === null || $value === '')) {
            return '<em>No email given</em>';
        }
        return esc_html((string) $value);
    }

    public function no_items(): void
    {
        echo 'No reports yet.';
    }
}
```

`includes/engine/Wp/Admin/Admin.php`:

```php
<?php
namespace HaloAudit\Wp\Admin;

use HaloAudit\Wp\Installer;

final class Admin
{
    private const CAPABILITY = 'manage_options';
    private const EXPORT_ACTION = 'halo_audit_export';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [Settings::class, 'register']);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'export']);
    }

    public static function menu(): void
    {
        add_menu_page('SEO Analyzer', 'SEO Analyzer', self::CAPABILITY, 'seo-analyzer', [self::class, 'homePage'], 'dashicons-chart-area', 6);
        add_submenu_page('seo-analyzer', 'SEO Analyzer Settings', 'Settings', self::CAPABILITY, Settings::PAGE, [Settings::class, 'page']);
        add_submenu_page('seo-analyzer', 'SEO Analysis Reports', 'Reports', self::CAPABILITY, 'seo-analyzer-reports', [self::class, 'reportsPage']);
    }

    public static function homePage(): void
    {
        echo '<div class="wrap"><h1>SEO Analyzer</h1><p>Add the tool to any page with this shortcode:</p>'
            . '<p><code>[seo_analyzer title="Halo Audit"]</code></p></div>';
    }

    public static function reportsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'seo-analyzer'), '', ['response' => 403]);
        }
        $table = new ReportsTable();
        $table->prepare_items();
        $export = wp_nonce_url(admin_url('admin-post.php?action=' . self::EXPORT_ACTION), self::EXPORT_ACTION);

        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html(get_admin_page_title()) . '</h1> ';
        echo '<a class="page-title-action" href="' . esc_url($export) . '">Export leads as CSV</a><hr class="wp-header-end">';
        $table->display();
        echo '</div>';
    }

    /** @return array<int,array<string,mixed>> verified leads only */
    public static function exportRows(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT name, email, url, keyword, overall_score, grade, is_education, created_at FROM ' . Installer::resultsTable()
            . " WHERE email IS NOT NULL AND email <> '' ORDER BY id DESC",
            ARRAY_A
        );
    }

    /** Stops a spreadsheet from running a cell as a formula. */
    public static function csvCell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    public static function export(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'seo-analyzer'), '', ['response' => 403]);
        }
        check_admin_referer(self::EXPORT_ACTION);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="halo-audit-leads-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Email', 'Page', 'Keyword', 'Score', 'Grade', 'Education', 'Date']);
        foreach (self::exportRows() as $row) {
            fputcsv($out, array_map(static fn($value): string => self::csvCell((string) $value), array_values($row)));
        }
        fclose($out);
        exit;
    }
}
```

- [ ] **Step 4: Wire the new admin and remove the old one**

In `seo-analyzer.php` delete these lines:

```php
// Old admin screens. Replaced in a later commit.
function seo_analyzer_is_addon_openai_active() {
    return false;
}
require_once SEO_ANALYZER_PLUGIN_DIR . 'admin/class-seo-analyzer-admin.php';
if (is_admin()) {
    new Seo_Analyzer_Admin('seo-analyzer', SEO_ANALYZER_VERSION);
}
```

In `includes/engine/Wp/Plugin.php`, add at the end of `boot()`:

```php
        if (is_admin()) {
            Admin\Admin::register();
        }
```

Delete the old admin folder and the hotfix test that exercised it:

```bash
git rm -rq admin tests/integration/HotfixTest.php
```

- [ ] **Step 5: Run both suites**

```bash
vendor/bin/phpunit
npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin vendor/bin/phpunit -c phpunit.integration.xml
```

Expected: both pass.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: replace admin screens with paginated list and guarded export"
```

---

### Task 21: Removals, uninstall, docs and version

**Files:**
- Delete: `public/class-seo-analyzer-public.php`, `public/js/seo-analyzer-public.js`, `public/css/seo-analyzer-public.css`, `public/logo.png`, `public/images/`, `assets/`, `includes/tcpdf/`, `includes/class-seo-analyzer.php`, `includes/class-seo-analyzer-loader.php`, `includes/class-seo-analyzer-activator.php`, `includes/class-seo-analyzer-deactivator.php`, `includes/class-seo-analyzer-api.php`, `includes/class-seo-analyzer-records.php`, `replit.nix`, `replit_zip_error_log.txt`, `PRD_ OnPage SEO Chrome Extension v 3.2.pdf`, `../seo-analyzer-add-on-openai/`, `../seo-analyzer-nlp-addon/`, `../push-log.txt`
- Modify: `uninstall.php` (replace), `README.txt` (replace), `CHANGELOG.md` (prepend), `../README.md` (replace)
- Test: `tests/unit/RepoHygieneTest.php`

**Interfaces:**
- Produces: a plugin folder that contains only code that is loaded.

- [ ] **Step 1: Write the failing test**

`tests/unit/RepoHygieneTest.php`:

```php
<?php
namespace HaloAudit\Tests;

use PHPUnit\Framework\TestCase;

final class RepoHygieneTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /** @dataProvider removed */
    public function test_dead_paths_are_gone(string $path): void
    {
        $this->assertFileDoesNotExist($this->root . '/' . $path);
    }

    public function removed(): array
    {
        return [
            ['public/class-seo-analyzer-public.php'], ['public/js/seo-analyzer-public.js'], ['public/css/seo-analyzer-public.css'],
            ['includes/tcpdf'], ['includes/class-seo-analyzer.php'], ['includes/class-seo-analyzer-loader.php'],
            ['includes/class-seo-analyzer-api.php'], ['includes/class-seo-analyzer-records.php'],
            ['includes/class-seo-analyzer-activator.php'], ['includes/class-seo-analyzer-deactivator.php'],
            ['admin'], ['../seo-analyzer-add-on-openai'], ['../seo-analyzer-nlp-addon'], ['../push-log.txt'],
        ];
    }

    public function test_no_sensitive_logging_or_unsafe_fetch_remains(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/includes', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            foreach (['error_log(', 'print_r($_POST', 'wp_remote_get(', "'sslverify' => false", 'set_time_limit(300'] as $needle) {
                if (strpos($code, $needle) !== false) {
                    $offenders[] = basename($file->getPathname()) . ': ' . $needle;
                }
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_engine_has_no_wordpress_calls_outside_the_wp_folder(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/includes/engine', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || strpos($path, '/engine/Wp/') !== false) {
                continue;
            }
            if (preg_match('/\b(wp_[a-z_]+|get_option|add_action|add_filter|apply_filters|esc_[a-z_]+|sanitize_[a-z_]+)\s*\(/', file_get_contents($path), $m)) {
                $offenders[] = basename($path) . ': ' . $m[1];
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_versions_agree(): void
    {
        $main = file_get_contents($this->root . '/seo-analyzer.php');
        $readme = file_get_contents($this->root . '/README.txt');
        $this->assertStringContainsString('Version: 2.0.0', $main);
        $this->assertStringContainsString("define('SEO_ANALYZER_VERSION', '2.0.0')", $main);
        $this->assertStringContainsString('Stable tag: 2.0.0', $readme);
        $this->assertStringContainsString('Requires PHP: 8.0', $readme);
        $this->assertStringContainsString('Requires at least: 6.2', $readme);
        $this->assertStringContainsString('## 2.0.0', file_get_contents($this->root . '/CHANGELOG.md'));
    }

    public function test_uninstall_removes_every_table_and_option(): void
    {
        $code = file_get_contents($this->root . '/uninstall.php');
        foreach (['seo_analyzer_results', 'seo_analyzer_otp', 'seo_analyzer_api_keys'] as $table) {
            $this->assertStringContainsString($table, $code, $table);
        }
        foreach (['halo_audit_db_version', 'halo_audit_psi_key', 'halo_audit_scan_url', 'halo_audit_scan_secret', 'seo_analyzer_brevo_api_key', 'seo_analyzer_sender_email', 'seo_analyzer_openai_api_key', 'seo_analyzer_redirect_url', 'seo_analyzer_pagespeed_api_key'] as $option) {
            $this->assertStringContainsString($option, $code, $option);
        }
        $this->assertStringContainsString('halo_audit_purge', $code);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RepoHygieneTest`
Expected: failures; the dead paths still exist.

- [ ] **Step 3: Delete the dead files**

```bash
cd "$REPO"
git rm -rq --ignore-unmatch \
  SEO-plugin/public/class-seo-analyzer-public.php SEO-plugin/public/js/seo-analyzer-public.js \
  SEO-plugin/public/css/seo-analyzer-public.css SEO-plugin/public/logo.png SEO-plugin/public/images SEO-plugin/assets \
  SEO-plugin/includes/tcpdf SEO-plugin/includes/class-seo-analyzer.php SEO-plugin/includes/class-seo-analyzer-loader.php \
  SEO-plugin/includes/class-seo-analyzer-activator.php SEO-plugin/includes/class-seo-analyzer-deactivator.php \
  SEO-plugin/includes/class-seo-analyzer-api.php SEO-plugin/includes/class-seo-analyzer-records.php \
  SEO-plugin/replit.nix SEO-plugin/replit_zip_error_log.txt "SEO-plugin/PRD_ OnPage SEO Chrome Extension v 3.2.pdf" \
  seo-analyzer-add-on-openai seo-analyzer-nlp-addon push-log.txt
ls SEO-plugin
```

Expected listing: `CHANGELOG.md composer.json composer.lock includes node_modules package.json package-lock.json phpunit.integration.xml phpunit.xml.dist public README.txt seo-analyzer.php tests uninstall.php vendor` (plus dot-files).

- [ ] **Step 4: Remove the last log lines from the mailer**

In `includes/class-seo-analyzer-mailer.php` delete the two remaining lines that start with `error_log('SEO Analyzer Brevo API Error:`. The `wp_mail` fallback on the next line of each block stays.

- [ ] **Step 5: Replace `uninstall.php`**

```php
<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

foreach (['seo_analyzer_results', 'seo_analyzer_otp', 'seo_analyzer_api_keys'] as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
}

foreach ([
    'halo_audit_db_version',
    'halo_audit_psi_key',
    'halo_audit_scan_url',
    'halo_audit_scan_secret',
    'seo_analyzer_brevo_api_key',
    'seo_analyzer_sender_email',
    'seo_analyzer_openai_api_key',
    'seo_analyzer_redirect_url',
    'seo_analyzer_pagespeed_api_key',
    'seo_analyzer_menu_created',
] as $option) {
    delete_option($option);
}

wp_clear_scheduled_hook('halo_audit_purge');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_halo\_rl\_%' OR option_name LIKE '\_transient\_timeout\_halo\_rl\_%'");
```

- [ ] **Step 6: Replace the docs**

`README.txt`:

```
=== SEO Analyzer ===
Contributors: thrivemattic
Tags: seo, audit, ai, performance
Requires at least: 6.2
Tested up to: 6.8
Stable tag: 2.0.0
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

A check that cannot run shows "Not measured". It never lowers the score.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Run `composer install --no-dev` in the plugin folder.
3. Activate the plugin.
4. Go to SEO Analyzer > Settings. Add a Google PageSpeed API key and a Brevo API key.
5. Add `[seo_analyzer title="Halo Audit"]` to a page.

== Upgrade Notice ==

= 2.0.0 =
Needs PHP 8.0 and WordPress 6.2. Run `composer install --no-dev` after you update. The OpenAI and NLP add-ons are no longer used: deactivate and delete them. The competitor comparison is removed.

== Developer notes ==

* Filter `halo_audit_client_ip`: return the true visitor IP when the site is behind a proxy.
* Filter `halo_audit_scan_client`: return a `HaloAudit\ScanClient` to enable the live AI visibility scan.
* Tests: `composer test` (unit). Integration tests need Docker: `npx wp-env start`, then
  `npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin vendor/bin/phpunit -c phpunit.integration.xml`.
```

Prepend to `CHANGELOG.md`, above the existing first heading:

```markdown
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

### Added
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

```

`../README.md` (repo root):

```markdown
# seo-analyzer

The WordPress plugin behind Halo Audit. The plugin is in `SEO-plugin/`. See `SEO-plugin/README.txt`.
```

- [ ] **Step 7: Run both suites**

```bash
cd "$REPO"/SEO-plugin
composer dump-autoload
vendor/bin/phpunit
npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin vendor/bin/phpunit -c phpunit.integration.xml
```

Expected: both pass.

- [ ] **Step 8: Commit**

```bash
cd "$REPO"
git add -A && git commit -m "chore: remove dead code and add-ons, update uninstall and docs for 2.0.0"
```

---

### Task 22: Acceptance run and pull request

**Files:**
- Create: `../docs/halo-audit/ACCEPTANCE.md` (the notes file for this task).

**Interfaces:**
- Consumes: everything.
- Produces: a pull request on `PraveenKumarDreamnotion/seo-analyzer`.

- [ ] **Step 1: Run the full test suites one last time**

```bash
cd "$REPO"/SEO-plugin
vendor/bin/phpunit
npx wp-env run tests-cli --env-cwd=wp-content/plugins/SEO-plugin vendor/bin/phpunit -c phpunit.integration.xml
```

Expected: both pass. Record the two test counts.

- [ ] **Step 2: Run five real audits in the local WordPress**

In the wp-env site (`http://localhost:8888`, page from Task 19), audit these five pages with the keyword shown:

| Page | Keyword |
|---|---|
| `https://thrivemattic.com/` | `AI marketing agency` |
| `https://thrivemattic.com/halo/` | `search visibility platform` |
| `https://www.ashoka.edu.in/` | `liberal arts university` |
| `https://www.ashoka.edu.in/admissions/` | `Ashoka admissions` |
| `https://example.com/` | `example` |

For each, write into the notes file: overall score, grade, each group score, time to first result in seconds, and any check that reads "Not measured".

Expected: every first result arrives in under 15 seconds; both `ashoka.edu.in` pages show the Education extras; no page shows a PHP error.

- [ ] **Step 3: Compare with Halo for the two Thrivemattic pages (Sandeep runs this step)**

This step needs the Halo CLI, which lives in the private `tm_SEO` repo. Ask Sandeep to run it and send the two JSON files.

```bash
cd tm_SEO
uv run tmseo page verify https://thrivemattic.com/ --json > halo-home.json
uv run tmseo page verify https://thrivemattic.com/halo/ --json > halo-halo.json
```

For these checks, the plugin status and the Halo status must agree in direction (both pass, or both not pass): `h1_single` ↔ `single_h1`, `canonical_matches` ↔ `canonical`, `no_noindex` ↔ `noindex`, `structured_data` ↔ `json_ld`, `og_complete` ↔ `open_graph`, `twitter_card` ↔ `twitter_card`, `image_alts` ↔ `image_alt`, `staging_leaks` ↔ `staging_leaks`.

`title_length` and `meta_description` may differ: the plugin uses the canon thresholds (50 to 60, 140 to 160) and Halo's launch check uses 30 to 60 and 70 to 160. Note each difference; it is expected.

If the Halo output is not available, write that in the notes file and skip this step.

Any unexpected disagreement is a bug. Fix it with a failing unit test first, then commit.

- [ ] **Step 4: Check the security fixes by hand**

```bash
BASE=http://localhost:8888
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/wp-admin/admin-ajax.php?page=seo-analyzer-reports&action=view&id=1"
curl -s -o /dev/null -w "%{http_code}\n" -X POST "$BASE/wp-admin/admin-ajax.php" -d "action=test_wp_mail"
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/wp-admin/admin-post.php?action=halo_audit_export"
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/wp-json/halo-audit/v1/report/$(printf 'a%.0s' {1..32})"
NONCE=$(curl -s "$BASE/wp-json/halo-audit/v1/nonce" | sed -E 's/.*"nonce":"([^"]+)".*/\1/')
curl -s -X POST "$BASE/wp-json/halo-audit/v1/analyze" -H "Content-Type: application/json" -H "X-Halo-Nonce: $NONCE" -d '{"url":"http://169.254.169.254/latest/meta-data/","keyword":"x"}'
```

Expected, in order: `400`; `400`; a redirect to the login page (`302`) or `403`; `403`; a JSON error with the message `This address cannot be checked.` No response body contains a name or an email.

- [ ] **Step 5: Open the pull request**

```bash
cd "$REPO"
git push -u origin halo-audit-engine
gh pr create --repo PraveenKumarDreamnotion/seo-analyzer --base main --head halo-audit-engine \
  --title "Halo Audit 2.0: new check engine, server-side gate, security fixes" \
  --body "$(cat <<'EOF'
## What this does

- Replaces the check engine with Halo's on-page, technical and AI-readiness rules: 33 scored checks in four groups, plus 4 education extras.
- Stores reports on the server and enforces the email gate on the server.
- Moves the endpoints to the REST API under `/wp-json/halo-audit/v1/`.
- Removes dead code, the bundled TCPDF copy and both add-ons.
- Adds unit and integration tests.

## Review order

The commits are ordered for review. The second commit (`fix: require login and nonce for report actions, remove open endpoints`) is small and stands alone. It was deployed ahead of this pull request as the security hotfix.

## Deploy steps

1. `composer install --no-dev` in `SEO-plugin/`.
2. Update the plugin. The database upgrade runs on the first page load.
3. Deactivate and delete the two add-on plugins.
4. Settings: check that the PageSpeed key was carried over. Add the Brevo key if it is missing.
5. On Kinsta, confirm that `$_SERVER['REMOTE_ADDR']` is the visitor IP and not a proxy IP. If it is a proxy IP, add a `halo_audit_client_ip` filter.

## Behaviour changes

- Needs PHP 8.0 and WordPress 6.2.
- The competitor comparison is removed.
- The page copy "no signup" on the tool page must change: the score is free, the full findings and the PDF need an email.

## Tests

`composer test` for unit tests. See `SEO-plugin/README.txt` for the integration tests.
EOF
)"
```

- [ ] **Step 6: Check on staging**

After the deploy to staging, run Step 2 again against `https://stg-thrivemattic-staging.kinsta.cloud/ai-tools/seo-analysis/` and add the results to the notes file. Then confirm Review Focus item 5: run one audit from the office network and one from a phone hotspot, and confirm that the two requests show different `REMOTE_ADDR` values in the server log.
