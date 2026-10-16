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
