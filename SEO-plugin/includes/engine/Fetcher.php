<?php
namespace HaloAudit;

final class Fetcher
{
    public const MAX_REDIRECTS = 3;

    private HttpClient $http;
    private UrlGuard $guard;
    /** @var array<string,Response> */
    private array $cache = [];
    private ?float $deadline = null;

    public function __construct(HttpClient $http, UrlGuard $guard)
    {
        $this->http = $http;
        $this->guard = $guard;
    }

    public function setDeadline(float $unixTime): void
    {
        $this->deadline = $unixTime;
    }

    public function get(string $url, int $timeout = 5, int $maxBytes = 2000000): Response
    {
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }
        return $this->cache[$url] = $this->fetch($url, $timeout, $maxBytes);
    }

    private function fetch(string $url, int $timeout, int $maxBytes): Response
    {
        $chain = [];
        $current = $url;

        for ($hop = 0; ; $hop++) {
            if ($this->deadline !== null && microtime(true) > $this->deadline) {
                return $this->fail('time budget used up', $current, $chain);
            }
            $check = $this->guard->inspect($current);
            if ($check['reason'] !== null) {
                return $this->fail('blocked: ' . $check['reason'], $current, $chain);
            }

            $response = $this->http->request('GET', $current, $timeout, $maxBytes, [], $check['ips']);
            if ($response->error !== null) {
                $response->finalUrl = $current;
                $response->chain = $chain;
                return $response;
            }

            $location = $response->header('location');
            if (in_array($response->status, [301, 302, 303, 307, 308], true) && $location !== null && $location !== '') {
                $chain[] = ['status' => $response->status, 'url' => $current];
                if ($hop >= self::MAX_REDIRECTS) {
                    return $this->fail('too many redirects', $current, $chain);
                }
                $current = Url::resolve($current, $location);
                continue;
            }

            $response->finalUrl = $current;
            $response->chain = $chain;
            return $response;
        }
    }

    private function fail(string $message, string $url, array $chain): Response
    {
        $r = Response::error($message);
        $r->finalUrl = $url;
        $r->chain = $chain;
        return $r;
    }
}
