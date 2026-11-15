<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Files customers and staff attach to support messages. Checked by content (MIME and file signature), stored outside
 * public/ under random names, and only ever served through a controller that checks who may see the ticket.
 */
final class SupportAttachmentService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PER_MESSAGE = 3;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /** What the customer sees when a file cannot be saved on the server (the detail goes to the app log). */
    public const SAVE_FAILED = 'The file could not be saved on the server. Try again, or send a smaller file. If it keeps happening, the shop needs to check that the support folder can be written to.';

    /** Log why a save failed and return the text to show. @return string */
    public static function saveFailed(\Throwable $e): string
    {
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . '] support attachment: ' . $e->getMessage() . ' (check that storage/support can be written to by the web server user)' . "\n", FILE_APPEND | LOCK_EX);
        return self::SAVE_FAILED;
    }

    public static function dir(): string
    {
        return BASE_PATH . '/storage/support';
    }

    /**
     * Normalise $_FILES['attachments'] into a list of single uploads, skipping empty slots.
     * @return array<int, array{name:string, tmp_name:string, size:int, error:int}>
     * @throws \InvalidArgumentException with a customer-safe message
     */
    public static function collect(?array $files): array
    {
        if ($files === null || !isset($files['name']) || !is_array($files['name'])) {
            return [];
        }
        $out = [];
        foreach ($files['name'] as $i => $name) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => (string) $name, 'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''), 'size' => (int) ($files['size'][$i] ?? 0), 'error' => $error];
        }
        if (count($out) > self::MAX_PER_MESSAGE) {
            throw new \InvalidArgumentException('Attach up to ' . self::MAX_PER_MESSAGE . ' files per message.');
        }
        foreach ($out as $f) {
            self::check($f);
        }
        return $out;
    }

    /** @return array{ext:string, mime:string} @throws \InvalidArgumentException */
    public static function check(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('"' . self::safeName((string) ($file['name'] ?? '')) . '" is too large (max 5 MB).');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \InvalidArgumentException('A file did not finish uploading. Please attach it again.');
        }
        if ((int) $file['size'] <= 0) {
            throw new \InvalidArgumentException('"' . self::safeName((string) $file['name']) . '" is empty.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new \InvalidArgumentException('Attach a JPG, PNG, WebP or PDF file.');
        }
        if ($mime === 'application/pdf') {
            $fh = fopen((string) $file['tmp_name'], 'rb');
            $head = $fh ? (string) fread($fh, 5) : '';
            $fh && fclose($fh);
            if ($head !== '%PDF-') {
                throw new \InvalidArgumentException('That PDF looks damaged. Attach it again.');
            }
        } elseif (@getimagesize((string) $file['tmp_name']) === false) {
            throw new \InvalidArgumentException('That image could not be read. Try a screenshot instead.');
        }
        return ['ext' => self::TYPES[$mime], 'mime' => $mime];
    }

    /** Save one checked upload against a message. Returns the stored file name. @throws \RuntimeException when the file cannot be stored */
    public static function store(int $ticketId, int $messageId, array $file): string
    {
        $info = self::check($file);
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('The support files folder cannot be created on the server.');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $info['ext'];
        if (!@move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $stored)) {
            throw new \RuntimeException('The support files folder cannot be written to on the server.');
        }
        try {
            Database::query(
                'INSERT INTO support_attachments (ticket_id, message_id, stored_name, original_name, mime, size) VALUES (?, ?, ?, ?, ?, ?)',
                [$ticketId, $messageId, $stored, self::safeName((string) $file['name']), $info['mime'], (int) $file['size']]
            );
        } catch (\Throwable $e) {
            @unlink($dir . '/' . $stored); // never keep a file with no record
            throw $e;
        }
        return $stored;
    }

    /** @return array<int, array<int, array<string, mixed>>> message id => its attachments */
    public static function forMessages(array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
        if ($messageIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($messageIds), '?'));
        $out = [];
        foreach (Database::query("SELECT id, message_id, original_name, mime, size FROM support_attachments WHERE message_id IN ({$in}) ORDER BY id", $messageIds)->fetchAll() as $row) {
            $out[(int) $row['message_id']][] = $row;
        }
        return $out;
    }

    /** One attachment of a ticket (the caller must already have checked the ticket belongs to them). */
    public static function find(int $ticketId, int $id): ?array
    {
        return Database::query('SELECT * FROM support_attachments WHERE id = ? AND ticket_id = ?', [$id, $ticketId])->fetch() ?: null;
    }

    public static function path(array $row): string
    {
        return self::dir() . '/' . basename((string) $row['stored_name']);
    }

    /**
     * Send the file to the browser. Images show inline. A PDF downloads, unless $viewInline is set (the chat's viewer
     * shows it in a popup). Callers must have checked the ticket first.
     * @return never
     */
    public static function serve(array $row, bool $viewInline = false): never
    {
        $path = self::path($row);
        if (!is_file($path)) {
            \App\Core\Response::abort(404);
        }
        $inline = str_starts_with((string) $row['mime'], 'image/') || $viewInline;
        header('Content-Type: ' . $row['mime']);
        header('Content-Length: ' . (string) filesize($path));
        $name = self::safeName((string) $row['original_name']);
        $ascii = (string) preg_replace('/[^\x20-\x7E]|"/', '_', $name);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /** A file name safe to show: no control characters, no folders, short. */
    public static function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', basename($name)) ?? '';
        return mb_substr($name !== '' ? $name : 'attachment', 0, 190);
    }
}
