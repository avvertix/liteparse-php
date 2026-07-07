<?php

declare(strict_types=1);

namespace LiteParse\Composer;

/**
 * Platform detection for selecting the correct native library filenames.
 *
 * Adapted from avvertix/html-shot's HtmlShot\Composer\Platform, itself
 * adapted from codewithkyrian/platform-package-installer (MIT).
 */
class Platform
{
    /**
     * @return array{os: string, arch: string, full: string}
     */
    public static function current(): array
    {
        return [
            'os' => strtolower(php_uname('s')),
            'arch' => self::normalizeArchitecture(php_uname('m')),
            'full' => php_uname(),
        ];
    }

    public static function normalizeArchitecture(string $arch): string
    {
        $arch = strtolower($arch);

        $archMap = [
            'x86_64' => 'x86_64',
            'amd64' => 'x86_64',
            'x64' => 'x86_64',
            '64' => 'x86_64',
            'i386' => 'x86',
            'i686' => 'x86',
            'x86' => 'x86',
            '32' => 'x86',
            'arm64' => 'arm64',
            'aarch64' => 'arm64',
            'armv8' => 'arm64',
            'arm64v8' => 'arm64',
            'armv7' => 'arm',
        ];

        return $archMap[$arch] ?? $arch;
    }
}
