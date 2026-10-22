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
        $evidence = $hops . ($hops === 1 ? ' redirect: ' : ' redirects: ') . implode(', ', $statuses);
        if ($hops > 2) {
            return Result::fail($evidence);
        }
        $temporary = array_intersect($statuses, [302, 303, 307]) !== [];
        return ($hops === 2 || $temporary) ? Result::warn($evidence) : Result::pass($evidence);
    }
}
