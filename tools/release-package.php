<?php

declare(strict_types=1);

/** Shared by the clean-tag builder, the independent ZIP verifier and the release tests. */
final class Disc2Release
{
    public const REQUIRED = [
        'bin/console', 'config/bundles.php', 'config/routes.yaml', 'config/services.yaml',
        'config/packages/framework.yaml', 'config/packages/security.yaml', 'config/packages/twig.yaml', 'config/packages/monolog.yaml',
        'migrations/001_uat.sql', 'migrations/002_comment2.sql', 'migrations/003_subject_national_id.sql', 'migrations/004_workflow.sql',
        'public/index.php', 'public/web.config', 'public/assets/login.css', 'public/assets/login.js', 'public/assets/uat.css', 'public/assets/workflow.js',
        'public/assets/flatpickr-4.6.13.min.css', 'public/assets/flatpickr-4.6.13.min.js', 'public/assets/flatpickr-4.6.13-th.js',
        'tools/licenses/flatpickr-LICENSE.txt', 'src/Kernel.php', 'templates/base.html.twig', 'vendor/autoload.php', 'vendor/autoload_runtime.php',
        'vendor/composer/autoload_classmap.php', 'vendor/composer/installed.php', 'vendor/composer/installed.json',
        'tools/iis-smoke.ps1', 'tools/iis-policy.ps1', 'tools/iis-http.php', 'tools/iis-preflight.php', 'tools/rollback-proof.php', 'composer.json', 'composer.lock',
    ];
    public const EXTENSIONS = ['curl', 'iconv', 'intl', 'mbstring', 'pdo_sqlite', 'session', 'xml', 'Zend OPcache'];
    public const MANIFEST = 'release-manifest.json';

    public static function portableName(string $name): bool
    {
        foreach (explode('/', $name) as $part) {
            if ($part === '' || str_starts_with($part, '.') || str_ends_with($part, '.') || str_ends_with($part, ' ')
                || preg_match('~[\x00-\x1f\\\\:<>"|?*]|^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)~i', $part)) {
                return false;
            }
        }
        return true;
    }

    public static function allowed(string $name, array $packages): bool
    {
        if (!self::portableName($name) || preg_match('~(?:^|/)(?:tests|fixtures)(?:/|$)|\.(?:db|sqlite3?)(?:[-.].*)?$|\.(?:key|pem|pfx|p12|log|bak|zip)$~i', $name)) {
            return false;
        }
        // Twig compiles template predicates through these runtime expression nodes.
        if (str_starts_with($name, 'vendor/') && preg_match('~/test/~i', $name)
            && !str_starts_with($name, 'vendor/twig/twig/src/Node/Expression/Test/')) {
            return false;
        }
        if (in_array($name, self::REQUIRED, true)) {
            return true;
        }
        if (preg_match('~^(?:src/[\w/]+\.php|templates/[\w/.-]+\.twig|migrations/\d+_[a-z0-9_]+\.sql)$~D', $name)) {
            return true;
        }
        if (!str_starts_with($name, 'vendor/')) {
            return false;
        }
        if (preg_match('~^vendor/composer/(?:autoload_\w+\.php|platform_check\.php|ClassLoader\.php|InstalledVersions\.php|LICENSE)$~D', $name)) {
            return true;
        }
        $parts = explode('/', $name, 4);
        return count($parts) === 4 && in_array($parts[1].'/'.$parts[2], $packages, true);
    }

    private static function packages(string $lockJson, string $installedJson): array
    {
        $lock = json_decode($lockJson, true, flags: JSON_THROW_ON_ERROR);
        $installed = json_decode($installedJson, true, flags: JSON_THROW_ON_ERROR);
        $versions = static function (array $packages): array {
            $result = [];
            foreach ($packages as $pkg) {
                $result[$pkg['name']] = [$pkg['version'], $pkg['source']['reference'] ?? null, $pkg['dist']['reference'] ?? null];
            }
            ksort($result, SORT_STRING);
            return $result;
        };
        if (($installed['dev'] ?? true) !== false || $versions($installed['packages']) !== $versions($lock['packages'])) {
            throw new RuntimeException('Installed production packages differ from composer.lock.');
        }
        return array_column($lock['packages'], 'name');
    }

    private static function validateNames(array $names, array $packages): void
    {
        $packageManifests = array_map(static fn (string $package): string => "vendor/$package/composer.json", $packages);
        $missing = array_diff([...self::REQUIRED, ...$packageManifests], $names);
        if ($missing) {
            throw new RuntimeException('Missing release files: '.implode(', ', $missing));
        }
        $seen = [];
        foreach ($names as $name) {
            if (!self::allowed($name, $packages) || isset($seen[strtolower($name)])) {
                throw new RuntimeException("Forbidden or colliding release entry: $name");
            }
            $seen[strtolower($name)] = true;
        }
    }

    public static function pack(array $files, string $output, string $tag, string $commit): void
    {
        $packages = self::packages(file_get_contents($files['composer.lock']), file_get_contents($files['vendor/composer/installed.json']));
        self::validateNames(array_keys($files), $packages);
        ksort($files, SORT_STRING);
        $zip = new ZipArchive();
        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Choose a new ZIP output file.');
        }
        $manifest = ['format' => 1, 'tag' => $tag, 'commit' => $commit, 'php_min' => '8.2', 'extensions' => self::EXTENSIONS, 'files' => []];
        foreach ($files as $name => $path) {
            if (is_link($path) || !$zip->addFile($path, $name)) {
                throw new RuntimeException("Cannot archive regular file $name");
            }
            self::normalize($zip, $name);
            $manifest['files'][$name] = hash_file('sha256', $path);
        }
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (!$zip->addFromString(self::MANIFEST, $json)) {
            throw new RuntimeException('Cannot add release manifest.');
        }
        self::normalize($zip, self::MANIFEST);
        if (!$zip->close()) {
            throw new RuntimeException('Could not finalize ZIP.');
        }
    }

    private static function normalize(ZipArchive $zip, string $name): void
    {
        if (!$zip->setMtimeName($name, 946684800)
            || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ($name === 'bin/console' ? 0100755 : 0100644) << 16)
            || !$zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9)) {
            throw new RuntimeException("Could not normalize ZIP entry $name");
        }
    }

    public static function verify(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Unreadable ZIP.');
        }
        try {
            $read = static function (string $name) use ($zip): string {
                $data = $zip->getFromName($name);
                if ($data === false) {
                    throw new RuntimeException("Missing ZIP entry $name");
                }
                return $data;
            };
            $manifest = json_decode($read(self::MANIFEST), true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? 0) !== 1 || ($manifest['extensions'] ?? []) !== self::EXTENSIONS || ($manifest['php_min'] ?? '') !== '8.2'
                || !preg_match('/^v[0-9][0-9A-Za-z.-]*$/D', $manifest['tag'] ?? '') || !preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $manifest['commit'] ?? '')) {
                throw new RuntimeException('Invalid release manifest.');
            }
            $packages = self::packages($read('composer.lock'), $read('vendor/composer/installed.json'));
            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (!$zip->getExternalAttributesIndex($i, $os, $attrs) || $os !== ZipArchive::OPSYS_UNIX || (($attrs >> 16) & 0170000) !== 0100000) {
                    throw new RuntimeException("Expected regular file: $name");
                }
                if ($name !== self::MANIFEST) {
                    $names[] = $name;
                }
            }
            self::validateNames($names, $packages);
            sort($names, SORT_STRING);
            if ($zip->numFiles !== count($names) + 1 || $names !== array_keys($manifest['files'])) {
                throw new RuntimeException('ZIP inventory differs from manifest.');
            }
            foreach ($names as $name) {
                if (!hash_equals($manifest['files'][$name], hash('sha256', $read($name)))) {
                    throw new RuntimeException("ZIP digest mismatch: $name");
                }
            }
            return $manifest;
        } finally {
            $zip->close();
        }
    }
}
