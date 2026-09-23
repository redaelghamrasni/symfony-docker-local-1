<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Read-only access to the application's log files for the back-office viewer.
 *
 * Everything here is deliberately defensive: the file name always arrives from
 * the browser, so it is matched against the files actually present in the log
 * directory rather than concatenated into a path. That makes traversal
 * ("../../.env") impossible by construction instead of by filtering.
 */
class LogFileService
{
    /** Only these are ever exposed; anything else in var/log stays private. */
    private const ALLOWED_PREFIXES = ['business', 'app'];

    public function __construct(private readonly string $logDir)
    {
    }

    /**
     * @return array<int, array{name: string, size: int, modified: \DateTimeImmutable}>
     */
    public function listFiles(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($this->logDir)->depth('== 0')->name('*.log');

        $files = [];

        foreach ($finder as $file) {
            if (!$this->isAllowed($file->getFilename())) {
                continue;
            }

            $files[] = [
                'name'     => $file->getFilename(),
                'size'     => $file->getSize(),
                'modified' => \DateTimeImmutable::createFromFormat('U', (string) $file->getMTime()),
            ];
        }

        // Newest first: the file someone wants is almost always today's.
        usort($files, static fn (array $a, array $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Last $limit entries of a file, newest first, JSON lines decoded.
     *
     * Reads from the end of the file rather than loading it whole — a log can
     * grow large and this runs inside a web request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tail(string $name, int $limit = 200, ?string $level = null, ?string $channel = null): array
    {
        $path = $this->resolve($name);

        if ($path === null) {
            return [];
        }

        $lines = $this->readLastLines($path, $limit * 4);
        $entries = [];

        foreach (array_reverse($lines) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            // A non-JSON line is still worth showing rather than hiding.
            if (!is_array($decoded)) {
                $decoded = ['message' => $line, 'level_name' => 'RAW', 'channel' => 'raw'];
            }

            if ($level !== null && $level !== '' && ($decoded['level_name'] ?? null) !== $level) {
                continue;
            }

            if ($channel !== null && $channel !== '' && ($decoded['channel'] ?? null) !== $channel) {
                continue;
            }

            $entries[] = $decoded;

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    public function download(string $name): ?BinaryFileResponse
    {
        $path = $this->resolve($name);

        if ($path === null) {
            return null;
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);

        return $response;
    }

    /** Channels present in a file, for the filter dropdown. */
    public function channelsIn(string $name): array
    {
        $channels = [];

        foreach ($this->tail($name, 500) as $entry) {
            $channel = $entry['channel'] ?? null;

            if (is_string($channel) && !in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        sort($channels);

        return $channels;
    }

    /**
     * Maps a browser-supplied name to a real path, or null.
     *
     * The name must match a file we actually listed — no path building.
     */
    private function resolve(string $name): ?string
    {
        foreach ($this->listFiles() as $file) {
            if ($file['name'] === $name) {
                $path = $this->logDir . DIRECTORY_SEPARATOR . $file['name'];

                return is_file($path) ? $path : null;
            }
        }

        return null;
    }

    private function isAllowed(string $filename): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($filename, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function readLastLines(string $path, int $maxLines): array
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();

        $start = max(0, $lastLine - $maxLines);
        $lines = [];

        $file->seek($start);

        while (!$file->eof()) {
            $lines[] = (string) $file->current();
            $file->next();
        }

        return $lines;
    }
}
