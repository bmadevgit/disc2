<?php

declare(strict_types=1);

require __DIR__.'/release-package.php';

function run(array $command, string $cwd, bool $capture = false): string
{
    $output = $capture ? ['pipe', 'w'] : STDOUT;
    $process = proc_open($command, [0 => STDIN, 1 => $output, 2 => STDERR], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start '.$command[0]);
    }
    $result = '';
    if ($capture) {
        $result = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Command failed: '.implode(' ', $command));
    }
    return trim($result);
}

function removeStage(string $dir): void
{
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($tree as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

function build(string $tag, string $signer, string $output, ?string $composerPhar): void
{
    if (!preg_match('/^v[0-9][0-9A-Za-z.-]*$/D', $tag) || !preg_match('/^(?:[\da-f]{40}|[\da-f]{64})$/iD', $signer)) {
        throw new RuntimeException('Provide a version tag and the approved OpenPGP signing fingerprint.');
    }
    if (strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'zip' || file_exists($output) || file_exists($output.'.sha256')) {
        throw new RuntimeException('Choose a new ZIP and checksum destination.');
    }
    $root = dirname(__DIR__);
    $object = run(['git', 'rev-parse', '--verify', "refs/tags/$tag^{tag}"], $root, true);
    // --format prints GPG details for commits, not tags. Git sends verify-tag --raw status to stderr.
    $proc = proc_open(['git', 'verify-tag', '--raw', $object], [0 => STDIN, 1 => STDOUT, 2 => ['pipe','w']], $pipes, $root);
    $status = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0 || !preg_match('/^\[GNUPG:\] VALIDSIG ([A-F0-9]+) [^\r\n]* ([A-F0-9]+)\r?$/m', $status, $match)
        || !in_array(strtoupper($signer), [$match[1], $match[2]], true) || preg_match('/\[GNUPG:\] (?:EXPKEYSIG|EXPSIG|REVKEYSIG)/', $status)) {
        throw new RuntimeException('Release signature failed or used an unapproved signing key.');
    }
    $tagHeader = explode("\n\n", run(['git', 'cat-file', 'tag', $object], $root, true), 2)[0];
    if (!in_array("tag $tag", explode("\n", $tagHeader), true)) {
        throw new RuntimeException('Signed tag name differs from the requested ref.');
    }
    $commit = run(['git', 'rev-parse', '--verify', "$object^{commit}"], $root, true);
    $roots = explode("\n", run(['git', 'ls-tree', '--name-only', $commit], $root, true));
    if (preg_grep('/\.php$/i', $roots)) {
        throw new RuntimeException('The release tag still contains root procedural PHP files.');
    }
    $buildDir = dirname($output).'/.disc2-build-'.bin2hex(random_bytes(8));
    if (!mkdir($buildDir, 0700)) {
        throw new RuntimeException('Cannot create a clean build directory.');
    }
    try {
        $stage = $buildDir.'/stage';
        mkdir($stage, 0700);
        $archive = $buildDir.'/source.zip';
        run(['git', 'archive', '--format=zip', '--output='.$archive, $commit, '--', 'bin', 'config', 'migrations', 'public', 'src', 'templates',
            'tools/iis-smoke.ps1', 'tools/iis-preflight.php', 'tools/rollback-proof.php', 'composer.json', 'composer.lock'], $root);
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot read source archive.');
        }
        $files = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $zip->getExternalAttributesIndex($i, $os, $attrs);
            if (!Disc2Release::allowed($name, []) || (($attrs >> 16) & 0170000) === 0120000 || isset($files[$name]) || !$zip->extractTo($stage, $name)) {
                throw new RuntimeException("Unexpected tagged source file: $name");
            }
            $files[$name] = $stage.'/'.$name;
        }
        $zip->close();
        $stage = realpath($stage);
        foreach (Disc2Release::EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                throw new RuntimeException("Missing PHP extension $ext");
            }
        }
        $composer = $composerPhar ? [PHP_BINARY, realpath($composerPhar) ?: throw new RuntimeException('Composer PHAR not found.')] : ['composer'];
        putenv('APP_ENV=prod');
        putenv('APP_DEBUG=0');
        putenv('APP_SECRET='.bin2hex(random_bytes(32)));
        putenv('COMPOSER=composer.json');
        putenv('COMPOSER_VENDOR_DIR=vendor');
        putenv('COMPOSER_ROOT_VERSION='.substr($tag, 1));
        run([...$composer, 'validate', '--strict', '--no-interaction'], $stage);
        run([...$composer, 'install', '--no-dev', '--classmap-authoritative', '--prefer-dist', '--no-interaction', '--no-progress'], $stage);
        run([...$composer, 'check-platform-reqs', '--no-dev'], $stage);
        $packages = array_column(json_decode(file_get_contents($stage.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR)['packages'], 'name');
        $vendor = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage.'/vendor', FilesystemIterator::SKIP_DOTS));
        foreach ($vendor as $file) {
            $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($stage) + 1));
            if (Disc2Release::allowed($name, $packages)) {
                $files[$name] = $file->getPathname();
            }
        }
        $built = $buildDir.'/release.zip';
        Disc2Release::pack($files, $built, $tag, $commit);
        Disc2Release::verify($built);
        $check = $buildDir.'/unpacked';
        $zip->open($built, ZipArchive::RDONLY);
        if (!$zip->extractTo($check)) {
            throw new RuntimeException('Cannot unpack release for validation.');
        }
        $zip->close();
        run([PHP_BINARY, '-r', 'exit((require "vendor/autoload.php")->isClassMapAuthoritative() ? 0 : 1);'], $check);
        $console = [PHP_BINARY, 'bin/console', '--env=prod', '--no-debug'];
        run([...$console, 'lint:container', '--resolve-env-vars'], $check);
        run([...$console, 'cache:warmup'], $check);
        $routes = json_decode(run([...$console, 'debug:router', '--format=json'], $check, true), true, flags: JSON_THROW_ON_ERROR);
        foreach (['login'=>'/login.php', 'case_home'=>'/', 'case_detail'=>'/case.php', 'case_save'=>'/save_case.php', 'discipline_save'=>'/save_discipline.php'] as $name => $path) {
            if (($routes[$name]['path'] ?? null) !== $path) {
                throw new RuntimeException("Wrong production route $name");
            }
        }
        if (isset($routes['runtime_smoke']) || isset($routes['security_probe'])) {
            throw new RuntimeException('Test route exposed in production.');
        }
        $hash = hash_file('sha256', $built);
        if (file_put_contents($buildDir.'/checksum', $hash.'  '.basename($output)."\n") === false || !link($built, $output)) {
            throw new RuntimeException('Cannot publish ZIP in output directory.');
        }
        if (!link($buildDir.'/checksum', $output.'.sha256')) {
            unlink($output);
            throw new RuntimeException('Cannot publish checksum.');
        }
        echo "$tag $commit SHA256 $hash\n";
    } finally {
        removeStage($buildDir);
    }
}

try {
    if (PHP_SAPI !== 'cli' || !extension_loaded('zip')) {
        throw new RuntimeException('Use PHP CLI with ext-zip.');
    }
    if (($argv[1] ?? '') === 'build' && in_array($argc, [5,6], true)) {
        $outputDir = realpath(dirname($argv[4])) ?: throw new RuntimeException('Output directory does not exist.');
        build($argv[2], $argv[3], $outputDir.DIRECTORY_SEPARATOR.basename($argv[4]), $argv[5] ?? null);
    } elseif (($argv[1] ?? '') === 'verify' && $argc === 4) {
        if (!preg_match('/^[0-9a-f]{64}$/iD', $argv[3]) || !hash_equals(strtolower($argv[3]), hash_file('sha256', $argv[2]))) {
            throw new RuntimeException('Archive SHA-256 differs from the approved build digest.');
        }
        $manifest = Disc2Release::verify($argv[2]);
        echo $manifest['tag'].' '.$manifest['commit'].' '.count($manifest['files'])." verified files\n";
    } else {
        throw new RuntimeException('Use build TAG SIGNER OUTPUT.zip [COMPOSER.phar] or verify OUTPUT.zip EXPECTED_SHA256.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
