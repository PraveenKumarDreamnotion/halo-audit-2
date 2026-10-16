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

        // "No sitemap" needs a definite answer from every address tried. If any of them failed,
        // was refused or hit a server error, that one may be the real sitemap: not measured.
        $unavailable = false;
        foreach (array_slice(array_unique($candidates), 0, 4) as $candidate) {
            $r = $fetcher->get($candidate);
            if ($r->error === null && $r->status === 200 && preg_match('/<(urlset|sitemapindex)[\s>]/i', $r->body)) {
                $site->sitemapStatus = 200;
                $site->sitemapValid = true;
                $site->sitemapNewest = self::newestLastmod($r->body);
                return;
            }
            if (SiteFacts::unavailable($r->error === null ? $r->status : null)) {
                $unavailable = true;
            }
        }
        $site->sitemapStatus = $unavailable ? null : 404;
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
        // Same rule as the sitemap: "no feed" only when every known path gave a definite answer.
        $unavailable = false;
        foreach (self::FEED_PATHS as $path) {
            $r = $fetcher->get($origin . $path, 5, 4096);
            if ($r->ok() && preg_match('/<(rss|feed)[\s>]/i', $r->body)) {
                $site->feedFound = true;
                return;
            }
            if (SiteFacts::unavailable($r->error === null ? $r->status : null)) {
                $unavailable = true;
            }
        }
        $site->feedFound = $unavailable ? null : false;
    }
}
