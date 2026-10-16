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

    /** null: the feed addresses could not all be checked (errors, bot walls, server errors). */
    public ?bool $feedFound = null;

    /** @var array{score:int,lcp_ms:float,cls:float}|null */
    public ?array $psi = null;

    /**
     * A side-file answer that says nothing about whether the file exists: no answer at all,
     * a redirect with no address, an access or rate-limit refusal, or a server error.
     * 404, 410 and other 4xx answers are a real "not there" and are not unavailable.
     */
    public static function unavailable(?int $status): bool
    {
        return $status === null
            || $status < 200
            || ($status >= 300 && $status < 400)
            || in_array($status, [401, 403, 407, 408, 425, 429], true)
            || $status >= 500;
    }
}
