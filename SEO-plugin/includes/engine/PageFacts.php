<?php
namespace HaloAudit;

final class PageFacts
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private \DOMDocument $dom;
    private \DOMXPath $xp;
    private string $html;
    private string $url;
    private ?string $text = null;
    private ?array $jsonLd = null;

    private function __construct()
    {
    }

    public static function fromHtml(string $html, string $url): self
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }

        $self = new self();
        $self->html = $html;
        $self->url = $url;
        $self->dom = new \DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $self->dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $self->xp = new \DOMXPath($self->dom);
        return $self;
    }

    public function url(): string { return $this->url; }
    public function html(): string { return $this->html; }

    public function title(): ?string
    {
        $node = $this->xp->query('//head/title')->item(0);
        if ($node === null) {
            return null;
        }
        $title = self::squash($node->textContent);
        return $title === '' ? null : $title;
    }

    public function meta(string $name): ?string
    {
        return $this->attr(sprintf('//meta[translate(@name,"%s","%s")="%s"]/@content', self::UPPER, self::LOWER, strtolower($name)));
    }

    public function property(string $property): ?string
    {
        return $this->attr(sprintf('//meta[translate(@property,"%s","%s")="%s"]/@content', self::UPPER, self::LOWER, strtolower($property)));
    }

    /** @return array<int,array{level:int,text:string}> */
    public function headings(): array
    {
        $out = [];
        foreach ($this->xp->query('//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
            $out[] = ['level' => (int) substr($node->nodeName, 1), 'text' => self::squash($node->textContent)];
        }
        return $out;
    }

    public function canonical(): ?string
    {
        $href = $this->attr(sprintf('//link[translate(@rel,"%s","%s")="canonical"]/@href', self::UPPER, self::LOWER));
        return $href === null ? null : Url::resolve($this->url, $href);
    }

    /** @return array<int,array{href:string,text:string,internal:bool}> */
    public function links(): array
    {
        $out = [];
        foreach ($this->xp->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || preg_match('/^(mailto|tel|javascript|data|sms|whatsapp):/i', $href)) {
                continue;
            }
            $abs = Url::resolve($this->url, $href);
            if (!preg_match('#^https?://#i', $abs)) {
                continue;
            }
            $out[] = ['href' => $abs, 'text' => self::squash($a->textContent), 'internal' => Url::sameSite($abs, $this->url)];
        }
        return $out;
    }

    /** @return array<int,array{src:string,has_alt:bool}> */
    public function images(): array
    {
        $out = [];
        foreach ($this->xp->query('//img') as $img) {
            $out[] = ['src' => $img->getAttribute('src'), 'has_alt' => $img->hasAttribute('alt')];
        }
        return $out;
    }

    /** @return array{blocks:int,invalid:int,types:string[]} */
    public function jsonLd(): array
    {
        if ($this->jsonLd !== null) {
            return $this->jsonLd;
        }
        $blocks = 0;
        $invalid = 0;
        $types = [];
        $topTypes = [];
        $query = sprintf('//script[translate(@type,"%s","%s")="application/ld+json"]', self::UPPER, self::LOWER);
        foreach ($this->xp->query($query) as $script) {
            $blocks++;
            $data = json_decode(trim($script->textContent), true);
            if (!is_array($data)) {
                $invalid++;
                continue;
            }
            self::collectTypes($data, $types);
            self::collectTopTypes($data, $topTypes);
        }
        return $this->jsonLd = [
            'blocks' => $blocks,
            'invalid' => $invalid,
            'types' => array_values(array_unique($types)),
            // Types of the things the page describes: top-level objects and @graph members only.
            // Nested values (publisher, potentialAction, itemListElement, image...) are left out.
            'top_types' => array_values(array_unique($topTypes)),
        ];
    }

    public function visibleText(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }
        $parts = [];
        $query = '//body//text()[not(ancestor::script or ancestor::style or ancestor::noscript or ancestor::template or ancestor::svg)]';
        foreach ($this->xp->query($query) as $node) {
            $parts[] = $node->nodeValue;
        }
        return $this->text = self::squash(implode(' ', $parts));
    }

    public function wordCount(): int
    {
        return Text::words($this->visibleText());
    }

    public function has(string $tag): bool
    {
        return $this->count($tag) > 0;
    }

    public function count(string $tag): int
    {
        return $this->dom->getElementsByTagName($tag)->length;
    }

    /** @return string[] */
    public function feedLinks(): array
    {
        $out = [];
        foreach ($this->xp->query('//link[@href]') as $link) {
            $type = strtolower($link->getAttribute('type'));
            if (strtolower($link->getAttribute('rel')) === 'alternate' && ($type === 'application/rss+xml' || $type === 'application/atom+xml')) {
                $out[] = Url::resolve($this->url, $link->getAttribute('href'));
            }
        }
        return $out;
    }

    private function attr(string $query): ?string
    {
        $node = $this->xp->query($query)->item(0);
        if ($node === null) {
            return null;
        }
        $value = trim($node->nodeValue);
        return $value === '' ? null : $value;
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function collectTopTypes(array $data, array &$types): void
    {
        if ($data !== [] && array_keys($data) === range(0, count($data) - 1)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    self::collectTopTypes($item, $types);
                }
            }
            return;
        }
        foreach ((array) ($data['@type'] ?? []) as $type) {
            if (is_string($type)) {
                $types[] = $type;
            }
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            self::collectTopTypes($data['@graph'], $types);
        }
    }

    private static function collectTypes(array $data, array &$types): void
    {
        if (isset($data['@type'])) {
            foreach ((array) $data['@type'] as $type) {
                if (is_string($type)) {
                    $types[] = $type;
                }
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                self::collectTypes($value, $types);
            }
        }
    }
}
