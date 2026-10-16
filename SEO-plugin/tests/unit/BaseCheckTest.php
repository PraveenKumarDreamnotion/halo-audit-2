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
