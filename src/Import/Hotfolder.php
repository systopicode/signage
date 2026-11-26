<?php

declare(strict_types=1);

namespace Systopic\Signage\Import;

use RuntimeException;
use Systopic\Cms\Pages\Sites\SiteRegistry;
use Systopic\Cms\Queries\Params;
use Systopic\Cms\Tables\Pages\Operator as PageOperator;
use Systopic\Cms\Tables\Pages\Page;
use Systopic\Db\Scope;
use Systopic\System\Media\GroupPolicy;
use Systopic\System\Tables\Medias\Files as MediaFiles;
use Systopic\System\Tables\Medias\Operator as MediaOperator;
use Systopic\System\Tables\Nodes\Model as Node;

/**
 * Takes over a legacy signage hotfolder (var/media/) into a site of Fluxo
 * CMS, so that an existing installation keeps its content.
 *
 * Under the site node:
 *
 *     Start            signage_start     Welcome.txt: text01 headline, html01 lines, text02 countdown
 *     <tile>           signage_topic     a file or a picture folder: text02 position (4x2), text03 flags,
 *                                        media groups document (pdf) / video (mp4) / slides (pictures)
 *     <tile>R          signage_section   a folder with tiles inside
 *     screensaver_*    signage_playlist  a folder played by the screensaver
 *
 * config.txt goes into the site meta (`signage.player`). The files are copied,
 * the hotfolder stays as it is. The order is the one of the hotfolder (natural
 * sort, as the legacy server read it).
 *
 * Run from the project folder through bin/import-hotfolder.php:
 *
 *     php vendor/systopic/signage/bin/import-hotfolder.php <hotfolder> <site> [--dry]
 *
 * Refuses a site that already has pages: a second run would double them.
 */
final class Hotfolder
{
    /** media group per file extension */
    private const GROUPS = [
        'pdf' => 'document',
        'mp4' => 'video',
        'jpg' => 'slides', 'jpeg' => 'slides', 'png' => 'slides', 'gif' => 'slides', 'webp' => 'slides',
    ];

    /** @var list<string> */
    public array $log = [];

    private Params $params;
    private int $pages = 0;
    private int $medias = 0;

    public function __construct(
        private readonly Scope $scope,
        private readonly string $source,
        private readonly ?int $userId = null,
        private readonly bool $dry = false,
    ) {
        $this->params = new Params(lang: defined('DEFAULT_LANG') ? (string) DEFAULT_LANG : 'de', preview: true, inCms: true, nodeType: 'page');
    }

    /** Entry point for the loader's CLI call. */
    public static function cli(string $source = '', string $siteName = '', string $mode = ''): void
    {
        if ($source === '' || $siteName === '') {
            fwrite(STDERR, "usage: … Hotfolder::cli <hotfolder> <site> [dry]\n");
            exit(2);
        }
        // previews of large PDFs and videos take minutes; the config's
        // max_execution_time counts wall time on Windows
        set_time_limit(0);
        try {
            $import = new self(Scope::default(), $source, null, $mode === 'dry');
            $import->into($siteName);
            echo implode("\n", $import->log), "\n";
        } catch (\Throwable $e) {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            exit(1);
        }
    }

    public function into(string $siteName): void
    {
        $source = rtrim(str_replace('\\', '/', $this->source), '/') . '/';
        if (!is_dir($source)) {
            throw new RuntimeException("hotfolder not found: $source");
        }
        $site = SiteRegistry::byName($this->scope, $siteName) ?? throw new RuntimeException("site not found: $siteName");
        $siteNodeId = (int) $site->relationId('node');
        if ($siteNodeId < 1) {
            throw new RuntimeException("$siteName is the default site (no node) - import into a site with a node");
        }
        $existing = (int) $this->scope->connection->value(
            'SELECT COUNT(*) FROM cms_nodes WHERE node_parent_node_id = ? AND node_is_trashed = 0',
            [$siteNodeId],
        );
        if ($existing > 0) {
            throw new RuntimeException("$siteName already has $existing node(s) - import refused (it would double them)");
        }
        $siteNode = Node::withId($siteNodeId);

        $this->start($siteNode, $source);
        $this->entries($siteNode, $source);
        $this->player($site, $source);
        $this->log[] = sprintf('%s%d pages, %d media into %s', $this->dry ? '[dry] ' : '', $this->pages, $this->medias, $siteName);
    }

    /** Welcome.txt (windows-1252): line 1 headline, then lines, a date line is the countdown. */
    private function start(Node $parent, string $source): void
    {
        $file = $source . 'Welcome.txt';
        $lines = is_file($file) ? preg_split('/\r\n|\r|\n/', (string) iconv('windows-1252', 'UTF-8', (string) file_get_contents($file))) : ['Welcome'];
        $headline = trim((string) array_shift($lines));
        $countdown = '';
        $html = [];
        foreach ($lines as $line) {
            if (\DateTime::createFromFormat('Y-m-d H:i:s', $line) !== false) {
                $countdown = $line;
            } elseif (trim($line) !== '') {
                $html[] = htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $this->page($parent, 'Start', 'signage_start', [
            'text01' => $headline,
            'html01' => implode('<br>', $html),
            'text02' => $countdown,
        ]);
    }

    /** The tiles of one folder, in hotfolder order. */
    private function entries(Node $parent, string $dir): void
    {
        $names = array_map('basename', glob($dir . '*') ?: []);
        natsort($names);
        foreach ($names as $name) {
            $hot = HotfolderName::parse($name);
            $path = $dir . $name;
            if ($hot === null) {
                if (!in_array($name, ['Welcome.txt', 'config.txt'], true)) {
                    $this->log[] = "skipped: $path";
                }
                continue;
            }
            $fields = ['text02' => $hot->key, 'text03' => $hot->flags];
            if (is_dir($path)) {
                $template = match (true) {
                    $hot->kind === 'playlist' => 'signage_playlist',
                    $hot->isSection() => 'signage_section',
                    default => 'signage_topic',   // a picture folder = a gallery
                };
                $page = $this->page($parent, $hot->label, $template, $fields);
                if ($template === 'signage_topic') {
                    $files = array_values(array_filter(glob($path . '/*') ?: [], 'is_file'));
                    natsort($files);
                    foreach ($files as $file) {
                        $this->media($page, $file);
                    }
                } else {
                    $this->entries($page, $path . '/');
                }
                continue;
            }
            $page = $this->page($parent, $hot->label, 'signage_topic', $fields);
            $this->media($page, $path);
        }
    }

    /** config.txt → site meta `signage.player` (the other meta keys stay). */
    private function player(\Systopic\Cms\Tables\Sites\Model $site, string $source): void
    {
        $file = $source . 'config.txt';
        if (!is_file($file)) {
            return;
        }
        $player = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($file)) as $line) {
            if (str_starts_with(ltrim($line), '#') || substr_count($line, ':') !== 1) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line));
            $player[$key] = is_numeric($value) ? (int) $value : $value;
        }
        $meta = json_decode((string) ($site->meta ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $meta['signage']['player'] = $player;
        $this->log[] = 'site meta signage.player: ' . json_encode($player, JSON_UNESCAPED_UNICODE);
        if (!$this->dry) {
            SiteRegistry::saveMeta($this->scope, $site, (string) json_encode($meta));
        }
    }

    /** @param array<string, string> $fields */
    private function page(Node $parent, string $label, string $template, array $fields): Node
    {
        $this->pages++;
        $this->log[] = "page: $label ($template) " . json_encode(array_filter($fields), JSON_UNESCAPED_UNICODE);
        if ($this->dry) {
            return new Node();
        }
        $page = Page::create($this->scope, $this->params, $parent, PHP_INT_MAX, $label, $template, $this->userId);
        $row = $page->row;
        foreach ($fields as $field => $value) {
            PageOperator::assign($row, $field, $value);
        }
        $row->isPublished = true;
        $row->isNaviElement = true;
        PageOperator::touch($row, $this->userId);
        $this->scope->uow()->persist($row)->commit();
        $this->scope->save(...$page->rewritepaths->rebuildAuto());
        return $page->node;
    }

    /** A copy of the file as a medium of the page, in the group of its type. */
    private function media(Node $page, string $file): void
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $group = self::GROUPS[$ext] ?? null;
        if ($group === null) {
            $this->log[] = "skipped (type): $file";
            return;
        }
        $this->medias++;
        $this->log[] = "  media $group: " . basename($file);
        if ($this->dry) {
            return;
        }
        [$media, $rows] = MediaOperator::create($this->scope, (int) $page->id, $group, $this->userId);
        $media->label = pathinfo($file, PATHINFO_FILENAME);
        MediaOperator::touch($media, $this->userId);
        $uow = $this->scope->uow();
        foreach ([...$rows, $media] as $row) {
            $uow->persist($row);
        }
        $uow->commit();
        // Files::store() moves its file: hand it a copy, the hotfolder stays
        $tmp = tempnam(sys_get_temp_dir(), 'sig');
        if ($tmp === false || !copy($file, $tmp)) {
            throw new RuntimeException("cannot copy $file");
        }
        MediaFiles::store($media, $tmp, GroupPolicy::storageName(basename($file)));
        $this->scope->uow()->persist($media)->commit();
        try {
            \Systopic\System\Media\VariantServer::preview($this->scope, (int) $media->id);
        } catch (\Throwable $e) {
            $this->log[] = '  (no preview: ' . $e->getMessage() . ')';
        }
    }
}
