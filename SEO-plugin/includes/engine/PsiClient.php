<?php
namespace HaloAudit;

final class PsiClient
{
    private HttpClient $http;
    private string $apiKey;

    public function __construct(HttpClient $http, string $apiKey)
    {
        $this->http = $http;
        $this->apiKey = $apiKey;
    }

    /** @return array{score:int,lcp_ms:float,cls:float}|null */
    public function run(string $url): ?array
    {
        $response = $this->http->request('GET', self::endpoint($url, $this->apiKey), 90, 5000000);
        return $response->ok() ? self::parse($response->body) : null;
    }

    public static function endpoint(string $url, string $apiKey): string
    {
        $query = 'url=' . rawurlencode($url) . '&strategy=mobile&category=performance';
        if ($apiKey !== '') {
            $query .= '&key=' . rawurlencode($apiKey);
        }
        return 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . $query;
    }

    /** @return array{score:int,lcp_ms:float,cls:float}|null */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
        $lcp = $data['lighthouseResult']['audits']['largest-contentful-paint']['numericValue'] ?? null;
        $cls = $data['lighthouseResult']['audits']['cumulative-layout-shift']['numericValue'] ?? null;
        if (!is_numeric($score) || !is_numeric($lcp) || !is_numeric($cls)) {
            return null;
        }
        // Lighthouse gives the score from 0 to 1 and never a negative time or shift. Anything else is not a
        // Lighthouse result, and an infinite value could not even be stored.
        [$score, $lcp, $cls] = [(float) $score, (float) $lcp, (float) $cls];
        if ($score < 0 || $score > 1 || !is_finite($lcp) || $lcp < 0 || !is_finite($cls) || $cls < 0) {
            return null;
        }
        return ['score' => (int) round($score * 100), 'lcp_ms' => $lcp, 'cls' => $cls];
    }
}
