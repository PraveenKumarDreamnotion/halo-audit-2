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
