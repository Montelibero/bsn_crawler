<?php

namespace MTLA;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class SnapshotHealthCheck
{
    private const PUBLIC_ALIASES = [
        'bsn.json',
        'bsn.json.gz',
        'bsn-extra.json,gz',
        'bsn.html.gz',
    ];

    /**
     * @throws JsonException
     */
    public function check(string $publicDirectory, int $maximumAgeSeconds, ?int $now = null): array
    {
        if ($maximumAgeSeconds <= 0) {
            throw new RuntimeException('Maximum snapshot age must be positive');
        }

        $publicDirectory = rtrim($publicDirectory, DIRECTORY_SEPARATOR);
        $currentLink = $publicDirectory . DIRECTORY_SEPARATOR . 'current';
        if (!is_link($currentLink)) {
            throw new RuntimeException('Current snapshot link is missing');
        }

        $generationDirectory = realpath($currentLink);
        $snapshotsDirectory = realpath($publicDirectory . DIRECTORY_SEPARATOR . '.snapshots');
        if (
            $generationDirectory === false
            || $snapshotsDirectory === false
            || !str_starts_with($generationDirectory, $snapshotsDirectory . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Current snapshot link has an invalid target');
        }

        foreach (self::PUBLIC_ALIASES as $name) {
            $alias = $publicDirectory . DIRECTORY_SEPARATOR . $name;
            if (!is_link($alias) || readlink($alias) !== 'current/' . $name) {
                throw new RuntimeException('Public snapshot alias is invalid: ' . $name);
            }
        }

        $metadata = $this->readJson($generationDirectory . DIRECTORY_SEPARATOR . 'publication.json');
        $generation = basename($generationDirectory);
        if (($metadata['generation'] ?? null) !== $generation) {
            throw new RuntimeException('Publication metadata generation mismatch');
        }

        $snapshotPath = $generationDirectory . DIRECTORY_SEPARATOR . 'bsn.json';
        $snapshotJson = $this->readFile($snapshotPath);
        $snapshot = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Published snapshot root must be an object');
        }

        $createDate = $snapshot['createDate'] ?? null;
        if (!is_string($createDate) || $createDate === '') {
            throw new RuntimeException('Published snapshot has no createDate');
        }

        $createdAt = new DateTimeImmutable($createDate);
        $now ??= time();
        $age = $now - $createdAt->getTimestamp();
        if ($age < -300) {
            throw new RuntimeException('Published snapshot createDate is in the future');
        }
        if ($age > $maximumAgeSeconds) {
            throw new RuntimeException('Published snapshot is stale: ' . $age . ' seconds old');
        }

        $gzip = $this->readFile($generationDirectory . DIRECTORY_SEPARATOR . 'bsn.json.gz');
        if (gzdecode($gzip) !== $snapshotJson) {
            throw new RuntimeException('Published bsn.json.gz does not match bsn.json');
        }

        $extraGzip = $this->readFile($generationDirectory . DIRECTORY_SEPARATOR . 'bsn-extra.json,gz');
        $extraJson = gzdecode($extraGzip);
        if ($extraJson === false) {
            throw new RuntimeException('Published extra snapshot is not valid gzip');
        }
        json_decode($extraJson, true, 512, JSON_THROW_ON_ERROR);

        $htmlGzip = $this->readFile($generationDirectory . DIRECTORY_SEPARATOR . 'bsn.html.gz');
        $html = gzdecode($htmlGzip);
        if ($html === false || $html === '') {
            throw new RuntimeException('Published HTML snapshot is empty or invalid');
        }

        foreach (self::PUBLIC_ALIASES as $name) {
            $artifact = $this->readFile($generationDirectory . DIRECTORY_SEPARATOR . $name);
            $expectedHash = $metadata['artifacts'][$name]['sha256'] ?? null;
            $expectedBytes = $metadata['artifacts'][$name]['bytes'] ?? null;
            if (!is_string($expectedHash) || !hash_equals($expectedHash, hash('sha256', $artifact))) {
                throw new RuntimeException('Published artifact checksum mismatch: ' . $name);
            }
            if (!is_int($expectedBytes) || $expectedBytes !== strlen($artifact)) {
                throw new RuntimeException('Published artifact size mismatch: ' . $name);
            }
        }

        return [
            'generation' => $generation,
            'createDate' => $createDate,
            'ageSeconds' => max(0, $age),
            'accounts' => is_array($snapshot['accounts'] ?? null) ? count($snapshot['accounts']) : null,
        ];
    }

    private function readJson(string $path): array
    {
        $data = json_decode($this->readFile($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('JSON root must be an object: ' . $path);
        }

        return $data;
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Published artifact is missing or empty: ' . $path);
        }

        return $contents;
    }
}
