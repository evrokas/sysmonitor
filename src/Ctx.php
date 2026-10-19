<?php
namespace Sysmon;

/**
 * Everything collectors touch outside their own logic: file roots, command
 * execution, disk space. Tests swap these for fixtures.
 */
class Ctx
{
    public string $os;
    public string $proc = '/proc';
    public string $sys = '/sys';
    /** @var callable|null fn(string $cmd): ?string */
    public $runner = null;
    /** @var callable|null fn(string $path): array{0:float,1:float} [total, free] */
    public $diskSpace = null;
    public Config $cfg;
    private ?array $winSnap = null;
    private bool $winSnapTried = false;

    public function __construct(Config $cfg, ?string $os = null)
    {
        $this->cfg = $cfg;
        $this->os = $os ?? PHP_OS_FAMILY;
    }

    public function read(string $path): ?string
    {
        $s = @file_get_contents($path);
        return $s === false ? null : $s;
    }

    public function glob(string $pattern): array
    {
        $r = glob($pattern);
        return $r ?: [];
    }

    public function run(string $cmd): ?string
    {
        if ($this->runner) {
            return ($this->runner)($cmd);
        }
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : null;
    }

    public function space(string $path): ?array
    {
        if ($this->diskSpace) {
            return ($this->diskSpace)($path);
        }
        $t = @disk_total_space($path);
        $f = @disk_free_space($path);
        return ($t && $f !== false) ? [(float)$t, (float)$f] : null;
    }

    /** One PowerShell call per tick, shared by every Windows collector. */
    public function winSnapshot(): ?array
    {
        if (!$this->winSnapTried) {
            $this->winSnapTried = true;
            $enc = base64_encode(mb_convert_encoding(WinSnapshot::SCRIPT, 'UTF-16LE', 'UTF-8'));
            $out = $this->run('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $enc);
            $j = $out ? json_decode(trim($out), true) : null;
            $this->winSnap = is_array($j) ? $j : null;
        }
        return $this->winSnap;
    }
}
