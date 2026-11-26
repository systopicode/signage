<?php

declare(strict_types=1);

/**
 * Takes a legacy hotfolder over into a site (Systopic\Signage\Import\Hotfolder).
 *
 *   cd <project>
 *   php vendor/systopic/signage/bin/import-hotfolder.php <hotfolder> <site> [--dry]
 *
 *   php vendor/systopic/signage/bin/import-hotfolder.php ../legacy/var/media demo.signage.systopic.net --dry
 *
 * Boots the project like a request and runs the import as soon as the
 * database is there (hook afterConnect, after systopic/cms has loaded the
 * sites). To do so it starts `php public/index.php` again with itself as
 * auto_prepend_file: the front controller takes the first included file as
 * the project entry, so it has to be public/index.php. The legacy CLI path
 * of loader.php (`index.php <json> <callable> …`) cannot be used: AgentCli
 * joins every argument into the JSON payload.
 */

// ---- second process: prepended to public/index.php
if (getenv('SIGNAGE_IMPORT') !== false && realpath((string) (get_included_files()[0] ?? '')) !== realpath(__FILE__)) {
    $job = json_decode((string) getenv('SIGNAGE_IMPORT'), true);
    require dirname((string) get_included_files()[0], 2) . '/vendor/autoload.php';
    \Systopic\System\Sys\Hooks\Hooks::on('afterConnect', static function () use ($job): void {
        while (ob_get_level() > 0) {
            ob_end_flush();   // the front controller buffers from its first line
        }
        \Systopic\Signage\Import\Hotfolder::cli((string) $job['source'], (string) $job['site'], $job['dry'] ? 'dry' : '');
        exit(0);
    });
    return;
}

// ---- first process: the command
$project = getcwd();
if (!is_file("$project/public/index.php") || !is_file("$project/vendor/autoload.php")) {
    fwrite(STDERR, "run from the project folder (public/index.php and vendor/ expected in $project)\n");
    exit(2);
}
$args = array_slice($argv, 1);
$dry = in_array('--dry', $args, true);
$args = array_values(array_filter($args, static fn(string $a): bool => $a !== '--dry'));
if (count($args) !== 2) {
    fwrite(STDERR, "usage: php vendor/systopic/signage/bin/import-hotfolder.php <hotfolder> <site> [--dry]\n");
    exit(2);
}
$payload = tempnam(sys_get_temp_dir(), 'sig');
file_put_contents($payload, '{"output":"html"}');
$env = getenv() + ['SIGNAGE_IMPORT' => json_encode(['source' => $args[0], 'site' => $args[1], 'dry' => $dry])];
$process = proc_open(
    [PHP_BINARY, '-d', 'auto_prepend_file=' . __FILE__, "$project/public/index.php", "@$payload"],
    [STDIN, STDOUT, STDERR],
    $pipes,
    $project,
    $env,
);
$exit = is_resource($process) ? proc_close($process) : 1;
@unlink($payload);
exit($exit);
