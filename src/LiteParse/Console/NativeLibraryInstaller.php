<?php

declare(strict_types=1);

namespace LiteParse\Console;

use Composer\InstalledVersions;
use LiteParse\Composer\Platform;
use OutOfRangeException;
use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resolves the native library assets for the current platform from the
 * GitHub releases API and downloads them into the package `lib/` directory,
 * verifying each published checksum.
 *
 * Unlike a single-binary FFI package, each platform needs *two* files here:
 * the compiled `liteparse_php` cdylib and its PDFium runtime dependency
 * (pdfium-sys's loader discovers the latter by looking next to the former —
 * see LiteParseFfi::resolveLibrary()). Both are downloaded together.
 *
 * A `natives.lock` file records, per installed package, the resolved release
 * and the download URL/checksum of every platform asset. It is written to the
 * consuming project root (alongside composer.lock) so it can be committed and
 * shared: a lock generated on one OS lets a build on a different OS install
 * deterministically and verify the download against the locked digest,
 * without re-resolving from the API.
 */
final class NativeLibraryInstaller
{
    public const PACKAGE_NAME = 'avvertix/liteparse-php';

    public const LOCK_FILE = 'natives.lock';

    private const GITHUB_LATEST_URL = 'https://api.github.com/repos/avvertix/liteparse-php/releases/latest';

    private const GITHUB_VERSION_URL = 'https://api.github.com/repos/avvertix/liteparse-php/releases/tags/{version}';

    /**
     * @param  string  $packageRoot  The package directory (holds composer.json and lib/).
     * @param  string|null  $projectRoot  The consuming project root where natives.lock
     *                                    lives. Resolved from the working directory when null.
     * @param  (\Closure(string, list<string>, int): array{status: int, body: string|false})|null  $httpClient
     *                                                                                                          Network seam: GET a URL, returning status + body. Defaults to a
     *                                                                                                          stream wrapper; injectable so tests can stub HTTP access.
     */
    public function __construct(
        private readonly string $packageRoot,
        private readonly ?string $projectRoot = null,
        private readonly ?\Closure $httpClient = null,
    ) {}

    /**
     * Download the native libraries (liteparse_php + pdfium) for the current
     * platform.
     *
     * @param  bool  $force  Re-resolve and re-download, ignoring the lock file.
     * @param  string|null  $version  Override the release version to download.
     */
    public function install(SymfonyStyle $io, bool $force = false, ?string $version = null): void
    {
        $lock = $this->readLock();
        $entry = $this->packageEntry($lock);

        $plan = $this->resolvePlan($io, $entry, $force, $version);

        $libDir = $this->packageRoot.'/lib';
        if (! is_dir($libDir) && ! @mkdir($libDir, 0o755, true) && ! is_dir($libDir)) {
            throw new RuntimeException("Failed to create lib directory: {$libDir}");
        }

        $changed = false;
        foreach ($plan['files'] as $filename => $file) {
            $destination = $libDir.'/'.$filename;

            if (! $force && is_file($destination) && $this->fileMatches($destination, $file['digest'])) {
                $io->writeln("Artifact <info>{$filename}</info> already present and verified, skipping download.");

                continue;
            }

            $io->writeln("Downloading <info>{$filename}</info> ({$plan['version']}) from <comment>{$file['url']}</comment>");
            $this->download($file['url'], $destination, $file['digest']);
            $changed = true;
        }

        if ($changed || ! $this->lockMatches($entry, $plan)) {
            $this->writeLock($plan);
            $io->writeln('Locked release in <info>'.self::LOCK_FILE.'</info>.');
        }

        $io->success('Native libraries installed to lib/: '.implode(', ', array_keys($plan['files'])));
    }

    /**
     * Decide which release to install and which two assets this platform needs.
     *
     * Without an explicit version or --force, a locked entry is reused: if it
     * already carries this platform's pair of files, entirely offline; if it
     * carries only other platforms' (e.g. the lock was generated on a
     * different OS), by re-fetching the *locked version* — never
     * `installedVersion()` — so a lock stays authoritative once written.
     * Otherwise the release is resolved fresh from the GitHub API.
     *
     * @param  array<string, mixed>|null  $entry  This package's locked entry.
     * @return array{version: string, assets: array<string, array{url: string, digest: ?string}>, files: array<string, array{url: string, digest: ?string}>}
     */
    private function resolvePlan(SymfonyStyle $io, ?array $entry, bool $force, ?string $version): array
    {
        if (! $force && $version === null && is_array($entry) && $this->lockHasVersion($entry)) {
            return $this->resolveFromLock($io, $entry);
        }

        $release = $this->resolveRelease($version);
        /** @var string $tag */
        $tag = $release['tag_name'] ?? '';
        $io->writeln("Resolved release <info>{$tag}</info>.");

        return $this->planFor($tag, $this->assetsFromRelease($release));
    }

    /**
     * Build an install plan from this package's locked entry, falling back to
     * the API — fetching the locked version, not `installedVersion()` — only
     * when it has no assets for the current platform.
     *
     * @param  array<string, mixed>  $entry
     * @return array{version: string, assets: array<string, array{url: string, digest: ?string}>, files: array<string, array{url: string, digest: ?string}>}
     */
    private function resolveFromLock(SymfonyStyle $io, array $entry): array
    {
        $version = (string) $entry['version'];
        $assets = $this->lockAssets($entry);

        if ($this->hasAllPlatformFiles($assets)) {
            $io->writeln("Reusing locked release <info>{$version}</info> from ".self::LOCK_FILE.'.');

            return $this->planFor($version, $assets);
        }

        $io->writeln(
            '<comment>'.self::LOCK_FILE." has no asset for this platform; resolving {$version} from GitHub.</comment>"
        );

        $release = $this->fetchReleaseByTag($version);
        $merged = $this->assetsFromRelease($release) + $assets;

        return $this->planFor((string) ($release['tag_name'] ?? $version), $merged);
    }

    /**
     * Resolve which of the (possibly multi-platform) `$assets` this platform
     * needs, while keeping the *full* asset map around — it is persisted to
     * the lock file as-is so a lock generated on one OS still lets another OS
     * resolve its own pair offline (see `lockHasAllPlatformFiles`).
     *
     * @param  array<string, array{url: string, digest: ?string}>  $assets  Every library asset in the release, keyed by filename.
     * @return array{version: string, assets: array<string, array{url: string, digest: ?string}>, files: array<string, array{url: string, digest: ?string}>}
     */
    private function planFor(string $version, array $assets): array
    {
        $files = [];
        foreach ($this->platformAssetNames() as $name) {
            if (! isset($assets[$name])) {
                $platform = Platform::current();
                $available = $assets === [] ? 'none' : implode(', ', array_keys($assets));

                throw new OutOfRangeException(
                    "Release {$version} has no asset named \"{$name}\" for the current platform ".
                    "({$platform['os']}-{$platform['arch']}). Available: {$available}."
                );
            }
            $files[$name] = $assets[$name];
        }

        return ['version' => $version, 'assets' => $assets, 'files' => $files];
    }

    /**
     * Resolve the GitHub release to install: an explicit version if provided,
     * otherwise the installed package version (falling back to the latest
     * published release for source/dev installs).
     *
     * @return array<string, mixed>
     */
    private function resolveRelease(?string $version): array
    {
        if ($version !== null && $version !== '') {
            return $this->fetchReleaseByTag($version);
        }

        $installed = $this->installedVersion();

        // Source installs (local development, branch requirements) report an
        // alias such as "dev-main", and an untagged checkout of this very
        // package (running as the Composer root package rather than as an
        // installed dependency) reports Composer's "1.0.0+no-version-set"
        // sentinel. Neither corresponds to a published release; fall back to
        // whatever GitHub marks as the latest release.
        if (str_starts_with($installed, 'dev-') || str_contains($installed, 'no-version-set')) {
            return $this->fetchLatestRelease();
        }

        return $this->fetchReleaseByTag($installed);
    }

    private function installedVersion(): string
    {
        if (! InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return $this->versionFromComposerJson()
                ?? throw new RuntimeException(
                    'Unable to determine the package version. Pass one explicitly as the command argument.'
                );
        }

        $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);

        if ($version === null || $version === '') {
            throw new RuntimeException(
                'Composer reported no version for '.self::PACKAGE_NAME.
                '. Pass one explicitly as the command argument.'
            );
        }

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLatestRelease(): array
    {
        return $this->fetchRelease(self::GITHUB_LATEST_URL)
            ?? throw new RuntimeException('No published release was found on GitHub.');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchReleaseByTag(string $version): array
    {
        foreach ($this->tagCandidates($version) as $tag) {
            $release = $this->fetchRelease(
                str_replace('{version}', rawurlencode($tag), self::GITHUB_VERSION_URL)
            );

            if ($release !== null) {
                return $release;
            }
        }

        throw new RuntimeException("No GitHub release found for version {$version}.");
    }

    /**
     * @return list<string>
     */
    private function tagCandidates(string $version): array
    {
        $candidates = [$version];
        $candidates[] = str_starts_with($version, 'v') ? substr($version, 1) : 'v'.$version;

        return array_values(array_unique($candidates));
    }

    /**
     * Extract library assets (url + digest, keyed by filename) from a GitHub
     * release payload. Non-library assets (checksums, source archives) are
     * ignored.
     *
     * @param  array<string, mixed>  $release
     * @return array<string, array{url: string, digest: ?string}>
     */
    private function assetsFromRelease(array $release): array
    {
        $assets = [];

        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? null;
            $url = $asset['browser_download_url'] ?? null;

            if (! is_string($name) || ! is_string($url) || ! $this->isLibraryAsset($name)) {
                continue;
            }

            $assets[$name] = [
                'url' => $url,
                'digest' => isset($asset['digest']) && is_string($asset['digest']) ? $asset['digest'] : null,
            ];
        }

        return $assets;
    }

    private function isLibraryAsset(string $name): bool
    {
        return preg_match('/\.(so|dylib|dll)$/i', $name) === 1;
    }

    /**
     * The two filenames this platform needs: the liteparse_php cdylib and
     * its PDFium runtime dependency. Order matches
     * LiteParseFfi::resolveLibrary() and scripts/copy-pdfium.sh.
     *
     * @return list<string>
     */
    private function platformAssetNames(): array
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => ['liteparse_php.dll', 'pdfium.dll'],
            'Darwin' => ['libliteparse_php.dylib', 'libpdfium.dylib'],
            default => ['libliteparse_php.so', 'libpdfium.so'],
        };
    }

    private function versionFromComposerJson(): ?string
    {
        $path = $this->packageRoot.'/composer.json';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $version = $decoded['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * @param  array<string, mixed>|null  $lock
     * @return array<string, mixed>|null
     */
    private function packageEntry(?array $lock): ?array
    {
        if (! is_array($lock) || ! isset($lock['packages']) || ! is_array($lock['packages'])) {
            return null;
        }

        foreach ($lock['packages'] as $package) {
            if (is_array($package) && ($package['name'] ?? null) === self::PACKAGE_NAME) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function lockHasVersion(array $entry): bool
    {
        return isset($entry['version']) && is_string($entry['version']) && $entry['version'] !== '';
    }

    /**
     * @param  array<string, array{url: string, digest: ?string}>  $assets
     */
    private function hasAllPlatformFiles(array $assets): bool
    {
        foreach ($this->platformAssetNames() as $name) {
            if (! isset($assets[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, array{url: string, digest: ?string}>
     */
    private function lockAssets(array $entry): array
    {
        $assets = [];
        $entries = $entry['assets'] ?? null;

        if (! is_array($entries)) {
            return $assets;
        }

        foreach ($entries as $name => $asset) {
            if (! is_string($name) || ! is_array($asset) || ! isset($asset['url']) || ! is_string($asset['url'])) {
                continue;
            }

            $assets[$name] = [
                'url' => $asset['url'],
                'digest' => isset($asset['digest']) && is_string($asset['digest']) ? $asset['digest'] : null,
            ];
        }

        return $assets;
    }

    /**
     * @param  array<string, mixed>|null  $entry
     * @param  array{version: string, assets: array<string, array{url: string, digest: ?string}>, files: array<string, array{url: string, digest: ?string}>}  $plan
     */
    private function lockMatches(?array $entry, array $plan): bool
    {
        return is_array($entry)
            && ($entry['version'] ?? null) === $plan['version']
            && $this->lockAssets($entry) == $plan['assets'];
    }

    private function lockPath(): string
    {
        return $this->resolveProjectRoot().'/'.self::LOCK_FILE;
    }

    private function resolveProjectRoot(): string
    {
        if ($this->projectRoot !== null && $this->projectRoot !== '') {
            return rtrim($this->projectRoot, '/\\');
        }

        $cwd = getcwd();

        return rtrim($cwd !== false ? $cwd : $this->packageRoot, '/\\');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readLock(): ?array
    {
        $path = $this->lockPath();
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Upsert this package's entry in the lock file, preserving any other
     * packages' entries (composer.lock style, in case a project depends on
     * more than one native-library package). Persists the *full* release
     * asset map (every platform), not just this platform's files, so the
     * lock stays useful when read back on a different OS.
     *
     * @param  array{version: string, assets: array<string, array{url: string, digest: ?string}>, files: array<string, array{url: string, digest: ?string}>}  $plan
     */
    private function writeLock(array $plan): void
    {
        $assets = $plan['assets'];
        ksort($assets);

        $entry = [
            'name' => self::PACKAGE_NAME,
            'version' => $plan['version'],
            'assets' => $assets,
            'installed-at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $existing = $this->readLock();
        $packages = is_array($existing['packages'] ?? null) ? $existing['packages'] : [];

        $replaced = false;
        foreach ($packages as $i => $package) {
            if (is_array($package) && ($package['name'] ?? null) === self::PACKAGE_NAME) {
                $packages[$i] = $entry;
                $replaced = true;
                break;
            }
        }
        if (! $replaced) {
            $packages[] = $entry;
        }

        usort($packages, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        $json = json_encode(
            ['packages' => array_values($packages)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($this->lockPath(), $json.PHP_EOL) === false) {
            throw new RuntimeException('Failed to write '.self::LOCK_FILE.'.');
        }
    }

    private function fileMatches(string $path, ?string $digest): bool
    {
        if ($digest === null || $digest === '') {
            return true;
        }

        [$algo, $expected] = array_pad(explode(':', $digest, 2), 2, '');

        if ($expected === '' || ! in_array($algo, hash_algos(), true)) {
            return true;
        }

        $actual = @hash_file($algo, $path);

        return is_string($actual) && hash_equals(strtolower($expected), strtolower($actual));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRelease(string $url): ?array
    {
        $response = $this->httpGet($url, [
            'User-Agent: liteparse-php native installer',
            'Accept: application/vnd.github+json',
        ], 30);

        if ($response['status'] === 404) {
            return null;
        }

        if ($response['body'] === false || $response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("GitHub API request failed (HTTP {$response['status']}) for: {$url}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function download(string $url, string $destination, ?string $digest): void
    {
        $response = $this->httpGet($url, ['User-Agent: liteparse-php native installer'], 120);
        $content = $response['body'];

        if ($content === false || $response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("Download failed for: {$url}");
        }

        if ($digest !== null && $digest !== '') {
            $this->verifyDigest($content, $digest);
        }

        if (file_put_contents($destination, $content) === false) {
            throw new RuntimeException("Failed to write artifact to: {$destination}");
        }
    }

    /**
     * @param  list<string>  $headers
     * @return array{status: int, body: string|false}
     */
    private function httpGet(string $url, array $headers, int $timeout): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($url, $headers, $timeout);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'follow_location' => 1,
                'max_redirects' => 10,
                'header' => $headers,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $http_response_header = [];
        $body = @file_get_contents($url, false, $context);

        return ['status' => $this->statusCode($http_response_header), 'body' => $body];
    }

    /**
     * @param  list<string>  $headers
     */
    private function statusCode(array $headers): int
    {
        $status = 0;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }

    private function verifyDigest(string $content, string $digest): void
    {
        [$algo, $expected] = array_pad(explode(':', $digest, 2), 2, '');

        if ($expected === '' || ! in_array($algo, hash_algos(), true)) {
            return;
        }

        $actual = hash($algo, $content);

        if (! hash_equals(strtolower($expected), strtolower($actual))) {
            throw new RuntimeException(
                "Checksum mismatch for downloaded artifact: expected {$algo}:{$expected}, got {$algo}:{$actual}."
            );
        }
    }
}
