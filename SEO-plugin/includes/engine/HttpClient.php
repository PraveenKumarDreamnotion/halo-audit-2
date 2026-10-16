<?php
namespace HaloAudit;

interface HttpClient
{
    /**
     * One hop only. Implementations must not follow redirects.
     *
     * @param string[] $pinnedIps addresses the host was checked against (UrlGuard::inspect). When given,
     *                            implementations should connect to one of them rather than resolve the host again.
     */
    public function request(string $method, string $url, int $timeout, int $maxBytes, array $headers = [], array $pinnedIps = []): Response;
}
