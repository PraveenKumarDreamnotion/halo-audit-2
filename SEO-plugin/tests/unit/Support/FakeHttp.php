<?php
namespace HaloAudit\Tests\Support;

use HaloAudit\HttpClient;
use HaloAudit\Response;

final class FakeHttp implements HttpClient
{
    /** @var array<string,Response> */
    public array $routes = [];
    /** @var string[] */
    public array $calls = [];
    /** @var array<string,string[]> url => pinned IPs of the last call */
    public array $pins = [];

    public function request(string $method, string $url, int $timeout, int $maxBytes, array $headers = [], array $pinnedIps = []): Response
    {
        $this->calls[] = $url;
        $this->pins[$url] = $pinnedIps;
        return isset($this->routes[$url]) ? clone $this->routes[$url] : Response::error('no route for ' . $url);
    }
}
