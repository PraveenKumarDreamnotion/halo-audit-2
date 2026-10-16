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
