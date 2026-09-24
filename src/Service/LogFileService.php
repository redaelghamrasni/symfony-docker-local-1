<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Read-only access to the application's log files for the back-office viewer.
 *
 * Each log channel writes its own rotating file (see
 * config/packages/monolog.yaml), so a "type" here is simply the channel the
 * file belongs to — checkout, payment, shipping, audit, mail, or app for
 * everything technical.
 *
 * Everything is deliberately defensive: the file name always arrives from the
 * browser, so it is matched against the files actually present in the log
 * directory rather than concatenated into a path. Traversal is impossible by
 * construction rather than by filtering.
 */
class LogFileService
{
    /**
     * The only files ever exposed. Anything else in var/log — dev.log,
     * test.log — stays private.
     */
    public const TYPES = ['checkout', 'payment', 'shipping', 'audit', 'mail', 'app'];

    public function __construct(private readonly string $logDir)
    {
    }

    /**
     * @return array<int, array{name: string, type: string, size: int, modified: \DateTimeImmutable}>
     */
    public function listFiles(?string $type = null): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($this->logDir)->depth('== 0')->name('*.log');

        $files = [];

        foreach ($finder as $file) {
            $fileType = $this->typeOf($file->getFilename());

            if ($fileType === null || ($type !== null && $fileType !== $type)) {
                continue;
            }

            $files[] = [
                'name'     => $file->getFilename(),
                'type'     => $fileType,
                'size'     => $file->getSize(),
                'modified' => \DateTimeImmutable::createFromFormat('U', (string) $file->getMTime()),
            ];
        }

        // Newest first: the file someone wants is almost always today's.
        usort($files, static fn (array $a, array $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * One entry per type, in a stable order, each with its files and totals —
     * this is what the viewer's sections are built from. Types with no file yet
     * are included so the section still appears (and explains itself).
     *
     * @return array<string, array{files: array, count: int, size: int, latest: ?string}>
     */
    public function groupedByType(): array
    {
        $grouped = [];

        foreach (self::TYPES as $type) {
            $files = $this->listFiles($type);

            $grouped[$type] = [
                'files'  => $files,
                'count'  => count($files),
                'size'   => array_sum(array_column($files, 'size')),
                'latest' => $files[0]['name'] ?? null,
            ];
        }

        return $grouped;
    }

    /**
     * Last $limit entries of a file, newest first, JSON lines decoded.
     *
     * Reads from the end of the file rather than loading it whole — a log can
     * grow large and this runs inside a web request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tail(string $name, int $limit = 100, ?string $level = null): array
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

    /**
     * The type a file belongs to, or null when it is not one we expose.
     *
     * Rotating files are named "<type>-YYYY-MM-DD.log", so the prefix must
     * match a known type exactly — "app" must not swallow "application.log".
     */
    private function typeOf(string $filename): ?string
    {
        foreach (self::TYPES as $type) {
            if ($filename === $type . '.log' || str_starts_with($filename, $type . '-')) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Maps a browser-supplied name to a real path, or null.
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
