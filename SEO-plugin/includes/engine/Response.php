<?php
namespace HaloAudit;

final class Response
{
    public int $status = 0;
    /** @var array<string,string> lower-case header names */
    public array $headers = [];
    public string $body = '';
    public string $finalUrl = '';
    /** @var array<int,array{status:int,url:string}> */
    public array $chain = [];
    public ?string $error = null;

    public static function make(int $status, string $body = '', array $headers = []): self
    {
        $r = new self();
        $r->status = $status;
        $r->body = $body;
        foreach ($headers as $name => $value) {
            $r->headers[strtolower((string) $name)] = (string) $value;
        }
        return $r;
    }

    public static function error(string $message): self
    {
        $r = new self();
        $r->error = $message;
        return $r;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }
}
