<?php
namespace HaloAudit;

final class UrlGuard
{
    /**
     * IPv4 ranges that are not ordinary public unicast (IANA special-purpose registry).
     * PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE miss several of these, and their
     * coverage changes between PHP versions, so the list is explicit.
     */
    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '192.175.48.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** IPv6 ranges that are never public unicast. */
    private const V6_BLOCKED = [
        '::/128', '::1/128', '::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        '3fff::/20', '5f00::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** IPv6 ranges that carry an IPv4 address: the embedded address decides. [prefix, offset of the IPv4 bytes] */
    private const V6_EMBEDDING = [
        ['::ffff:0:0/96', 12],   // IPv4-mapped
        ['64:ff9b::/96', 12],    // NAT64 well-known prefix
        ['2002::/16', 2],        // 6to4
    ];

    /** @var callable(string):string[] */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            return $ips;
        };
    }

    public function reject(string $url): ?string
    {
        return $this->inspect($url)['reason'];
    }

    /**
     * Checks the address and returns the IPs it was checked against. The caller should connect
     * to one of these IPs (see HttpClient), so that a second DNS answer cannot swap in another host.
     *
     * @return array{reason:?string,ips:string[]}
     */
    public function inspect(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || empty($p['scheme'])) {
            return self::no('not a valid address');
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return self::no('only http and https are allowed');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return self::no('credentials in the address are not allowed');
        }
        if (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)) {
            return self::no('only ports 80 and 443 are allowed');
        }

        $host = strtolower(trim($p['host'], '[]'));
        if ($host === 'localhost' || preg_match('/\.(local|localhost|test|internal|lan|home|corp|arpa)$/', $host)) {
            return self::no('local host names are not allowed');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_values(array_filter(
            ($this->resolver)($host),
            static fn($ip): bool => is_string($ip) && $ip !== ''
        ));
        if ($ips === []) {
            return self::no('the host name does not resolve');
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return self::no('private or reserved addresses are not allowed');
            }
        }
        return ['reason' => null, 'ips' => $ips];
    }

    public static function isPublic(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            return !self::inAny($bin, self::V4_BLOCKED);
        }
        foreach (self::V6_EMBEDDING as [$cidr, $offset]) {
            if (self::inCidr($bin, $cidr)) {
                return self::isPublic((string) inet_ntop(substr($bin, $offset, 4)));
            }
        }
        return !self::inAny($bin, self::V6_BLOCKED);
    }

    private static function inAny(string $bin, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        if (strncmp($bin, $netBin, $whole) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($bin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask);
    }

    private static function no(string $reason): array
    {
        return ['reason' => $reason, 'ips' => []];
    }
}
