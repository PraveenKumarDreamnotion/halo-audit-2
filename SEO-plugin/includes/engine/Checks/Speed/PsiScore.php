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
    public const TITLE = 'Google Performance score (mobile)';
    public const WHY = 'Most visitors arrive on a phone. A slow page loses them before they read anything.';
    public const FIX_WARN = 'Improve mobile speed. Start with large images, unused JavaScript and render-blocking files.';
    public const FIX_FAIL = 'The page is slow on a phone. Compress and resize images, remove unused JavaScript, and cache pages on the server.';

    public function run(Context $c): Result
    {
        if ($c->site->psi === null) {
            return Result::notMeasured('Google PageSpeed did not return a result');
        }
        $score = $c->site->psi['score'];
        $evidence = $score . ' out of 100 (Google Lighthouse, mobile lab test)';
        if ($score >= 90) {
            return Result::pass($evidence);
        }
        return $score >= 50 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
