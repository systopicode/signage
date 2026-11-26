<?php

declare(strict_types=1);

namespace Systopic\Signage\Import;

/**
 * A file or folder name of a legacy signage hotfolder (var/media/).
 *
 *     4x2A_Annual_report.pdf     tile column 4, row 2, autoplay
 *     2x3R_Our_topics/           a section (folder with tiles inside)
 *     screensaver_films/         a playlist for the screensaver
 *
 * Flags after the position: R = section, A = autoplay, H = highlight.
 * Everything else in the hotfolder (Welcome.txt, config.txt, .DS_Store) is
 * not a tile.
 */
final readonly class HotfolderName
{
    private function __construct(
        public string $name,
        public string $kind,      // 'tile' | 'playlist'
        public string $key,       // '4x2' | 'screensaver_films'
        public string $flags,     // 'A', 'RH', ''
        public string $label,     // 'Annual report'
        public string $extension, // 'pdf', '' for a folder
    ) {}

    public static function parse(string $name): ?self
    {
        if (preg_match('~^screensaver[^/]*$~i', $name)) {
            return new self($name, 'playlist', $name, '', self::label($name), '');
        }
        if (!preg_match('~^(\d+)x(\d+)([A-Z]*)_(.+?)(?:\.([a-z0-9]+))?$~i', $name, $m)) {
            return null;
        }
        return new self($name, 'tile', $m[1] . 'x' . $m[2], strtoupper($m[3]), self::label($m[4]), strtolower($m[5] ?? ''));
    }

    public function isSection(): bool { return str_contains($this->flags, 'R'); }
    public function autoplay(): bool { return str_contains($this->flags, 'A') || $this->kind === 'playlist'; }
    public function highlight(): bool { return str_contains($this->flags, 'H'); }

    private static function label(string $name): string
    {
        return trim(str_replace(['_', '-'], ' ', $name));
    }
}
