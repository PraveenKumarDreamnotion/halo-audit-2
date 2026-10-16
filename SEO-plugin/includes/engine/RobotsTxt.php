<?php
namespace HaloAudit;

/** RFC 9309 group matching: named group beats "*", longest pattern wins, Allow wins ties. */
final class RobotsTxt
{
    /** @var array<int,array{agents:string[],rules:array<int,array{allow:bool,pattern:string}>}> */
    private array $groups = [];
    /** @var string[] */
    private array $sitemaps = [];

    public static function parse(string $body): self
    {
        $self = new self();
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);

            if ($key === 'sitemap') {
                $self->sitemaps[] = $value;
                continue;
            }
            if ($key === 'user-agent') {
                if (!$lastWasAgent || $current === null) {
                    $self->groups[] = ['agents' => [], 'rules' => []];
                    $current = count($self->groups) - 1;
                }
                $self->groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            if (($key === 'allow' || $key === 'disallow') && $current !== null) {
                $self->groups[$current]['rules'][] = ['allow' => $key === 'allow', 'pattern' => $value];
            }
            $lastWasAgent = false;
        }

        return $self;
    }

    public function isAllowed(string $agent, string $path): bool
    {
        $rules = $this->rulesFor(strtolower($agent));
        if ($rules === null) {
            return true;
        }

        $best = null;
        foreach ($rules as $rule) {
            if ($rule['pattern'] === '' || !self::matches($rule['pattern'], $path)) {
                continue;
            }
            $length = strlen($rule['pattern']);
            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null ? true : $best['allow'];
    }

    /** True when the agent has a group of its own, so the "*" group does not apply to it. */
    public function hasNamedGroup(string $agent): bool
    {
        $agent = strtolower($agent);
        foreach ($this->groups as $group) {
            if ($agent !== '*' && in_array($agent, $group['agents'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    private function rulesFor(string $agent): ?array
    {
        $named = null;
        $star = null;
        foreach ($this->groups as $group) {
            if (in_array($agent, $group['agents'], true)) {
                $named = array_merge($named ?? [], $group['rules']);
            }
            if (in_array('*', $group['agents'], true)) {
                $star = array_merge($star ?? [], $group['rules']);
            }
        }
        return $named ?? $star;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = substr($pattern, -1) === '$';
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';
        return (bool) preg_match($regex, $path);
    }
}
