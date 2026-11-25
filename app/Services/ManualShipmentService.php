<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * A courier booked by the seller outside the system: the seller types the tracking number, an optional tracking
 * page link and optionally uploads the AWB. Nothing is sent to the courier. Stored in the shipments table with
 * source = 'manual'. The AWB file lives in storage/awb (outside the web root, random names) and is only ever sent
 * through an authorised route.
 */
final class ManualShipmentService
{
    public const COURIERS = [
        'spx' => 'SPX Express',
        'ninjavan' => 'NinjaVan',
        'jnt' => 'J&T Express',
        'poslaju' => 'PosLaju',
    ];
    private const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const TRACKING = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{2,59}$/';

    public static function dir(): string
    {
        return BASE_PATH . '/storage/awb';
    }

    public static function courierName(string $code): string
    {
        return self::COURIERS[$code] ?? 'NinjaVan';
    }

    /**
     * Book a manual courier on a Processing order that has no active shipment.
     * @param array{courier?:string, tracking_number?:string, tracking_url?:string} $in
     * @param array|null $file the $_FILES entry for the optional AWB
     * @return array{ok:bool, error?:string, fields?:array<string,string>, tracking?:string}
     */
    public static function create(int $orderId, array $in, ?array $file, int $actorId): array
    {
        $order = OrderService::find($orderId);
        if ($order === null) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        if ($order['status'] !== 'processing') {
            return ['ok' => false, 'error' => 'A courier can only be added to a Processing order.'];
        }
        if (ShipmentService::active($orderId) !== null) {
            return ['ok' => false, 'error' => 'This order already has an active shipment.'];
        }
        $v = self::validateFields($in, $file);
        if ($v['fields'] !== []) {
            return ['ok' => false, 'error' => 'Please check the highlighted fields.', 'fields' => $v['fields']];
        }
        $stored = $v['file'] !== null ? self::storeFile($v['file']) : null;
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            Database::query(
                'INSERT INTO shipments (order_id, carrier, source, tracking_number, tracking_url, status, awb_file, awb_mime, awb_original_name, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$orderId, $v['carrier'], 'manual', $v['tracking'], $v['url'], 'Booked manually',
                    $stored['name'] ?? null, $stored['mime'] ?? null, $stored['original'] ?? null, $actorId]
            );
            Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)',
                [$orderId, 'shipment', mb_substr(self::courierName($v['carrier']) . ' tracking ' . $v['tracking'] . ' added manually', 0, 255), $actorId]);
            $pdo->commit();
        } catch (\PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::removeFile($stored['name'] ?? null);
            // 23000 = duplicate key. Two keys can fire: the tracking number (any shipment, cancelled ones included)
            // or one active shipment per order (two staff booking the same order at once).
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_shipments_active')) {
                return ['ok' => false, 'error' => 'This order already has an active shipment.'];
            }
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_shipments_tracking')) {
                return ['ok' => false, 'error' => 'That tracking number is already used on another shipment.', 'fields' => ['tracking_number' => 'Already used.']];
            }
            throw $e;
        }
        return ['ok' => true, 'tracking' => $v['tracking']];
    }

    /** Attach or replace the AWB of the order's active manual shipment. @return string|null error message, null on success */
    public static function attachAwb(int $orderId, ?array $file, int $actorId): ?string
    {
        $ship = ShipmentService::active($orderId);
        if ($ship === null || ($ship['source'] ?? 'api') !== 'manual') {
            return 'Add the courier first, then upload its AWB.';
        }
        $v = self::validateFile($file);
        if ($v['error'] !== null) {
            return $v['error'];
        }
        $stored = self::storeFile($v['file']);
        Database::query('UPDATE shipments SET awb_file = ?, awb_mime = ?, awb_original_name = ? WHERE id = ?',
            [$stored['name'], $stored['mime'], $stored['original'], (int) $ship['id']]);
        self::removeFile($ship['awb_file'] ?? null);
        Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)',
            [$orderId, 'shipment', 'AWB uploaded for ' . $ship['tracking_number'], $actorId]);
        return null;
    }

    /** Cancel the active manual shipment (no courier call). The AWB file is kept for the record. */
    public static function cancel(int $orderId, int $actorId): bool
    {
        $ship = ShipmentService::active($orderId);
        if ($ship === null || ($ship['source'] ?? 'api') !== 'manual') {
            return false;
        }
        Database::query("UPDATE shipments SET active = NULL, cancelled_at = NOW(), status = 'Cancelled' WHERE id = ? AND active = 1", [(int) $ship['id']]);
        Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)',
            [$orderId, 'shipment', 'Manual courier booking ' . $ship['tracking_number'] . ' cancelled', $actorId]);
        return true;
    }

    /** Stream the AWB file (call only after the viewer has been authorised for this order). */
    public static function sendAwb(array $ship): never
    {
        $file = self::dir() . '/' . basename((string) $ship['awb_file']);
        if (!is_file($file)) {
            http_response_code(404);
            echo 'AWB file not found.';
            exit;
        }
        $ext = self::TYPES[(string) $ship['awb_mime']] ?? 'bin';
        header('Content-Type: ' . $ship['awb_mime']);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="awb-' . preg_replace('/[^A-Za-z0-9._-]/', '', (string) $ship['tracking_number']) . '.' . $ext . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex');
        readfile($file);
        exit;
    }

    /**
     * @param array $in
     * @return array{carrier:string, tracking:string, url:?string, file:?array, fields:array<string,string>}
     */
    private static function validateFields(array $in, ?array $file): array
    {
        $fields = [];
        $carrier = trim((string) ($in['courier'] ?? ''));
        if (!isset(self::COURIERS[$carrier])) {
            $fields['courier'] = 'Choose a courier from the list.';
        }
        $tracking = trim((string) ($in['tracking_number'] ?? ''));
        if (preg_match(self::TRACKING, $tracking) !== 1) {
            $fields['tracking_number'] = 'Enter the tracking number (3 to 60 letters or digits; - . / _ allowed).';
        }
        $url = trim((string) ($in['tracking_url'] ?? ''));
        if ($url !== '' && (strlen($url) > 500 || !self::isWebUrl($url))) {
            $fields['tracking_url'] = 'The tracking link must start with https:// or http://.';
            $url = '';
        }
        $v = self::validateFile($file);
        if ($v['error'] !== null) {
            $fields['awb'] = $v['error'];
        }
        return [
            'carrier' => $carrier,
            'tracking' => $tracking,
            'url' => $url === '' ? null : $url,
            'file' => $v['file'],
            'fields' => $fields,
        ];
    }

    /** An optional upload. No file is fine. @return array{file:?array, error:?string} */
    private static function validateFile(?array $file): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['file' => null, 'error' => null];
        }
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['file' => null, 'error' => 'The upload did not finish. Attach the AWB again.'];
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['file' => null, 'error' => 'That file is too large (max 5 MB).'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return ['file' => null, 'error' => 'The upload did not finish. Attach the AWB again.'];
        }
        if ((int) $file['size'] < 1) {
            return ['file' => null, 'error' => 'That file is empty.'];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            return ['file' => null, 'error' => 'Attach a PDF, JPG or PNG file.'];
        }
        if ($mime === 'application/pdf') {
            $fh = fopen((string) $file['tmp_name'], 'rb');
            $head = $fh ? (string) fread($fh, 5) : '';
            $fh && fclose($fh);
            if ($head !== '%PDF-') {
                return ['file' => null, 'error' => 'That PDF looks damaged. Attach it again.'];
            }
        } elseif (@getimagesize((string) $file['tmp_name']) === false) {
            return ['file' => null, 'error' => 'That image could not be read. Try a PDF or another image.'];
        }
        return ['file' => ['tmp_name' => (string) $file['tmp_name'], 'name' => (string) ($file['name'] ?? 'awb'), 'mime' => $mime], 'error' => null];
    }

    /** @return array{name:string, mime:string, original:string} */
    private static function storeFile(array $file): array
    {
        if (!is_dir(self::dir()) && !mkdir(self::dir(), 0750, true) && !is_dir(self::dir())) {
            throw new \RuntimeException('Cannot create the AWB folder.');
        }
        $ext = self::TYPES[$file['mime']];
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $to = self::dir() . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $to)) {
            throw new \RuntimeException('Cannot store the AWB file.');
        }
        @chmod($to, 0640);
        $original = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', ' ', $file['name']) ?? 'awb') ?: 'awb', 0, 190);
        return ['name' => $name, 'mime' => $file['mime'], 'original' => $original];
    }

    private static function removeFile(?string $name): void
    {
        if ($name !== null && $name !== '') {
            @unlink(self::dir() . '/' . basename($name));
        }
    }

    private static function isWebUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return preg_match('#^https?://#i', $url) === 1;
    }
}
