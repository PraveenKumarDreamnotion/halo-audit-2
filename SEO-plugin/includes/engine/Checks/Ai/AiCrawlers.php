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
        // RFC 9309 2.3.1.3: any 4xx (including 401 and 403) means "no rules", so crawlers may read
        // everything. A rate limit, a server error or no answer leaves the rules unknown.
        if ($status === null || $status === 429 || ($status !== 200 && $status < 400) || $status >= 500) {
            return Result::notMeasured($status === null ? 'The robots.txt file could not be fetched' : 'The robots.txt rules could not be read (HTTP ' . $status . ')');
        }
        $robots = $c->site->robots;
        if ($robots === null) {
            return Result::pass($status === 200 ? 'No robots.txt rules, so all crawlers are allowed' : 'No robots.txt (HTTP ' . $status . '), so all crawlers are allowed');
        }

        $path = Url::path($c->url);
        $blocked = [];
        $fallThrough = false;
        foreach (self::AGENTS as $agent) {
            if (!$robots->isAllowed($agent, $path)) {
                $blocked[] = $agent;
            }
            $fallThrough = $fallThrough || !$robots->hasNamedGroup($agent);
        }

        // Design 3.2: "Disallow: /" for "*" is a fail on its own. It only binds the AI crawlers that
        // have no group of their own, so it counts when at least one of the seven falls through to "*".
        if ($fallThrough && !$robots->isAllowed('*', '/')) {
            return Result::fail('robots.txt blocks every crawler (User-agent: * / Disallow: /)' . ($blocked !== [] ? '. Blocked: ' . implode(', ', $blocked) : ''));
        }
        if ($blocked === []) {
            return Result::pass('All 7 AI crawlers are allowed');
        }
        $evidence = 'Blocked: ' . implode(', ', $blocked);
        return count($blocked) >= 3 ? Result::fail($evidence) : Result::warn($evidence);
    }
}
