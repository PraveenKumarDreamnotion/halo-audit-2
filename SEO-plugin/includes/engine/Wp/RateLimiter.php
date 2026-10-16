<?php
namespace HaloAudit\Wp;

final class RateLimiter
{
    /**
     * Fixed window. The window starts at the first counted call and does not slide.
     * $cost is how many calls this one counts as (a comparison reads two pages, so it costs 2).
     */
    public static function allow(string $bucket, string $key, int $limit, int $windowSeconds, int $cost = 1): bool
    {
        $cost = max(1, $cost);
        $name = 'halo_rl_' . md5($bucket . '|' . $key);
        $state = get_transient($name);
        $now = time();

        if (!is_array($state) || $state['until'] <= $now) {
            $state = ['count' => 0, 'until' => $now + $windowSeconds];
        }
        if ($state['count'] + $cost > $limit) {
            return false;
        }
        $state['count'] += $cost;
        set_transient($name, $state, max(1, $state['until'] - $now));
        return true;
    }
}
