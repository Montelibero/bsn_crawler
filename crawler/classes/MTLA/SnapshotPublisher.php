<?php

namespace MTLA;

use JsonException;
use RuntimeException;

final class SnapshotPublisher
{
    private const PUBLIC_ALIASES = [
        'bsn.json',
        'bsn.json.gz',
        'bsn-extra.json,gz',
        'bsn.html.gz',
    ];

    private const SNAPSHOTS_DIRECTORY = '.snapshots';
    private const CURRENT_LINK = 'current';
    private const LOCK_FILE = '.crawler.lock';
    private const GENERATIONS_TO_KEEP = 3;

    /** @var resource|null */
    private mixed $lockHandle = null;

    public function __construct(private readonly string $publicDirectory)
    {
    }

    public function acquireLock(): void
    {
        if ($this->lockHandle !== null) {
            return;
        }

        $this->ensureDirectory($this->publicDirectory);
        $lockPath = $this->path(self::LOCK_FILE);
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open crawler lock: ' . $lockPath);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another crawler process is already running');
        }

        $this->lockHandle = $handle;
    }

    public function releaseLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    /**
     * @throws JsonException
     */
    public function publish(array $snapshot, array $extraSnapshot, string $html): string
    {
        if ($this->lockHandle === null) {
            throw new RuntimeException('Crawler lock must be acquired before publishing');
        }

        $snapshotJson = $this->encodeJson($snapshot, 'bsn.json');
        $extraJson = $this->encodeJson($extraSnapshot, 'bsn-extra.json,gz');
        $this->assertUtf8($html, 'bsn.html.gz');

        $artifacts = [
            'bsn.json' => $snapshotJson,
            'bsn.json.gz' => $this->encodeGzip($snapshotJson, 'bsn.json.gz'),
            'bsn-extra.json,gz' => $this->encodeGzip($extraJson, 'bsn-extra.json,gz'),
            'bsn.html.gz' => $this->encodeGzip($html, 'bsn.html.gz'),
        ];

        $snapshotsDirectory = $this->path(self::SNAPSHOTS_DIRECTORY);
        $this->ensureDirectory($snapshotsDirectory);

        $generation = gmdate('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(6));
        $generationDirectory = $snapshotsDirectory . DIRECTORY_SEPARATOR . $generation;
        if (!mkdir($generationDirectory, 0755)) {
            throw new RuntimeException('Cannot create snapshot generation: ' . $generationDirectory);
        }

        try {
            foreach ($artifacts as $name => $contents) {
                $this->writeFile($generationDirectory . DIRECTORY_SEPARATOR . $name, $contents);
            }

            $metadata = $this->buildMetadata($generation, $snapshot, $artifacts);
            $this->writeFile(
                $generationDirectory . DIRECTORY_SEPARATOR . 'publication.json',
                $this->encodeJson($metadata, 'publication.json')
            );

            $this->verifyGeneration($generationDirectory, $artifacts);
            $this->preparePublicAliases();
            $this->switchCurrentGeneration($generation);
            $this->preparePublicAliases();
            $this->cleanupOldGenerations($generation);
        } catch (\Throwable $exception) {
            if (!$this->isCurrentGeneration($generation)) {
                $this->removeDirectory($generationDirectory);
            }
            throw $exception;
        }

        return $generation;
    }

    public function __destruct()
    {
        $this->releaseLock();
    }

    /**
     * @throws JsonException
     */
    private function encodeJson(array $data, string $name): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if ($json === '') {
            throw new RuntimeException('Encoded JSON is empty: ' . $name);
        }

        return $json;
    }

    private function encodeGzip(string $contents, string $name): string
    {
        $gzip = gzencode($contents, 9);
        if ($gzip === false) {
            throw new RuntimeException('Cannot encode gzip artifact: ' . $name);
        }

        $decoded = gzdecode($gzip);
        if ($decoded === false || !hash_equals(hash('sha256', $contents), hash('sha256', $decoded))) {
            throw new RuntimeException('Gzip verification failed: ' . $name);
        }

        return $gzip;
    }

    private function assertUtf8(string $contents, string $name): void
    {
        if ($contents === '') {
            throw new RuntimeException('Artifact is empty: ' . $name);
        }
        if (preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('Artifact contains invalid UTF-8: ' . $name);
        }
    }

    private function buildMetadata(string $generation, array $snapshot, array $artifacts): array
    {
        $artifactMetadata = [];
        foreach ($artifacts as $name => $contents) {
            $artifactMetadata[$name] = [
                'bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ];
        }

        return [
            'generation' => $generation,
            'publishedAt' => gmdate(DATE_ATOM),
            'snapshotCreateDate' => $snapshot['createDate'] ?? null,
            'artifacts' => $artifactMetadata,
        ];
    }

    private function writeFile(string $path, string $contents): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create artifact: ' . $path);
        }

        try {
            $length = strlen($contents);
            $written = 0;
            while ($written < $length) {
                $chunkLength = fwrite($handle, substr($contents, $written));
                if ($chunkLength === false || $chunkLength === 0) {
                    throw new RuntimeException('Incomplete artifact write: ' . $path);
                }
                $written += $chunkLength;
            }

            if (!fflush($handle)) {
                throw new RuntimeException('Cannot flush artifact: ' . $path);
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Cannot sync artifact: ' . $path);
            }
        } finally {
            fclose($handle);
        }

        if (!chmod($path, 0644)) {
            throw new RuntimeException('Cannot set artifact permissions: ' . $path);
        }

        clearstatcache(true, $path);
        if (filesize($path) !== strlen($contents)) {
            throw new RuntimeException('Artifact size verification failed: ' . $path);
        }
    }

    private function verifyGeneration(string $directory, array $expectedArtifacts): void
    {
        foreach ($expectedArtifacts as $name => $expectedContents) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $actualContents = file_get_contents($path);
            if ($actualContents === false || !hash_equals(hash('sha256', $expectedContents), hash('sha256', $actualContents))) {
                throw new RuntimeException('Artifact checksum verification failed: ' . $name);
            }
        }

        $json = file_get_contents($directory . DIRECTORY_SEPARATOR . 'bsn.json');
        if ($json === false) {
            throw new RuntimeException('Cannot verify bsn.json');
        }
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $gzip = file_get_contents($directory . DIRECTORY_SEPARATOR . 'bsn.json.gz');
        if ($gzip === false || gzdecode($gzip) !== $json) {
            throw new RuntimeException('bsn.json.gz does not match bsn.json');
        }
    }

    private function preparePublicAliases(): void
    {
        $currentPath = $this->path(self::CURRENT_LINK);
        if (!is_link($currentPath)) {
            return;
        }

        foreach (self::PUBLIC_ALIASES as $name) {
            $path = $this->path($name);
            $target = self::CURRENT_LINK . '/' . $name;
            if (is_link($path) && readlink($path) === $target) {
                continue;
            }

            $temporaryLink = $this->path('.alias-' . bin2hex(random_bytes(6)));
            if (!symlink($target, $temporaryLink)) {
                throw new RuntimeException('Cannot create public alias: ' . $name);
            }

            if (!rename($temporaryLink, $path)) {
                @unlink($temporaryLink);
                throw new RuntimeException('Cannot activate public alias: ' . $name);
            }
        }
    }

    private function switchCurrentGeneration(string $generation): void
    {
        $temporaryLink = $this->path('.current-' . bin2hex(random_bytes(6)));
        $target = self::SNAPSHOTS_DIRECTORY . '/' . $generation;
        if (!symlink($target, $temporaryLink)) {
            throw new RuntimeException('Cannot create current generation link');
        }

        if (!rename($temporaryLink, $this->path(self::CURRENT_LINK))) {
            @unlink($temporaryLink);
            throw new RuntimeException('Cannot activate snapshot generation: ' . $generation);
        }
    }

    private function isCurrentGeneration(string $generation): bool
    {
        $path = $this->path(self::CURRENT_LINK);
        return is_link($path) && readlink($path) === self::SNAPSHOTS_DIRECTORY . '/' . $generation;
    }

    private function cleanupOldGenerations(string $currentGeneration): void
    {
        $directory = $this->path(self::SNAPSHOTS_DIRECTORY);
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        $generations = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($directory . DIRECTORY_SEPARATOR . $entry)) {
                continue;
            }
            $generations[] = $entry;
        }
        rsort($generations, SORT_STRING);

        $keep = array_slice($generations, 0, self::GENERATIONS_TO_KEEP);
        $keep[] = $currentGeneration;
        foreach (array_diff($generations, array_unique($keep)) as $generation) {
            $this->removeDirectory($directory . DIRECTORY_SEPARATOR . $generation);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($directory . DIRECTORY_SEPARATOR . $entry);
                }
            }
        }
        @rmdir($directory);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create directory: ' . $directory);
        }
    }

    private function path(string $name): string
    {
        return rtrim($this->publicDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }
}
