<?php
declare(strict_types=1);

/**
 * Normalize NAVER canonical raw rows into coupang_order_excel.
 *
 * Rules:
 * - Read only orders_raw where platform='naver' and is_normalized=0
 * - Insert/Upsert into coupang_order_excel
 * - Mark orders_raw.is_normalized=1 after successful write
 */
function run_normalize_naver_orders(int $jobId, string $from, string $to): void
{
    $pdo = db();

    job_log($jobId, 'info', "Naver normalize started: {$from} ~ {$to}");

    $selectStmt = $pdo->prepare(
        /*'SELECT id, order_no, raw_json
         FROM orders_raw
         WHERE platform = "naver"
           AND is_normalized = 0
           AND created_at BETWEEN ? AND ?
         ORDER BY id ASC'*/

		'SELECT id, order_no, raw_json
         FROM orders_raw
         WHERE platform = "naver"
           AND is_normalized = 0
           AND ordered_at BETWEEN ? AND ?
         ORDER BY id ASC'
    );
    $selectStmt->execute([$from, $to]);

    $upsertStmt = $pdo->prepare(
        'INSERT INTO coupang_order_excel (
            order_no,
            option_id,
            qty,
            ordered_at,
            carrier_name,
            tracking_no,
            buyer_name,
            buyer_phone,
            receiver_name,
            receiver_phone,
            zipcode,
            receiver_address,
            delivery_message,
            shipment_box_id,
            source_file
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            option_id = VALUES(option_id),
            qty = VALUES(qty),
            ordered_at = VALUES(ordered_at),
            carrier_name = VALUES(carrier_name),
            tracking_no = VALUES(tracking_no),
            buyer_name = VALUES(buyer_name),
            buyer_phone = VALUES(buyer_phone),
            receiver_name = VALUES(receiver_name),
            receiver_phone = VALUES(receiver_phone),
            zipcode = VALUES(zipcode),
            receiver_address = VALUES(receiver_address),
            delivery_message = VALUES(delivery_message),
            shipment_box_id = VALUES(shipment_box_id),
            source_file = VALUES(source_file)'
    );

    $markNormalizedStmt = $pdo->prepare(
        'UPDATE orders_raw
         SET is_normalized = 1,
             normalized_at = NOW()
         WHERE id = ?'
    );

    $readCount = 0;
    $normalizedCount = 0;
    $writtenCount = 0;
    $skippedCount = 0;

    while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
        $readCount++;

        $raw = json_decode((string)$row['raw_json'], true);
        if (!is_array($raw)) {
            $skippedCount++;
            job_log($jobId, 'warn', 'Skip row: invalid json id=' . (string)$row['id']);
            continue;
        }

        $shipmentBoxId = trim((string)($raw['shipmentBoxId'] ?? ''));
        if ($shipmentBoxId === '') {
            $skippedCount++;
            job_log($jobId, 'warn', 'Skip row: missing shipmentBoxId id=' . (string)$row['id']);
            continue;
        }

        $orderedAt = naver_normalize_to_mysql_datetime((string)($raw['orderedAt'] ?? ''));
        if ($orderedAt === null) {
            $orderedAt = date('Y-m-d H:i:s');
        }

        $orderer = is_array($raw['orderer'] ?? null) ? $raw['orderer'] : [];
        $receiver = is_array($raw['receiver'] ?? null) ? $raw['receiver'] : [];

        $buyerName = naver_normalize_str($orderer['name'] ?? null);
        $buyerPhone = naver_normalize_str($orderer['safeNumber'] ?? ($orderer['ordererNumber'] ?? null));

        $receiverName = naver_normalize_str($receiver['name'] ?? null);
        $receiverPhone = naver_normalize_str($receiver['safeNumber'] ?? ($receiver['receiverNumber'] ?? null));
        $zipcode = naver_normalize_str($receiver['postCode'] ?? null);

        $addr1 = naver_normalize_str($receiver['addr1'] ?? null);
        $addr2 = naver_normalize_str($receiver['addr2'] ?? null);
        $receiverAddress = trim($addr1 . ' ' . $addr2);

        $deliveryMessage = naver_normalize_str($raw['parcelPrintMessage'] ?? null);
        $trackingNo = naver_normalize_str($raw['invoiceNumber'] ?? null);

        $orderItems = is_array($raw['orderItems'] ?? null) ? $raw['orderItems'] : [];
        if (!$orderItems) {
            $skippedCount++;
            job_log($jobId, 'warn', 'Skip row: no orderItems id=' . (string)$row['id']);
            continue;
        }

        $rowWritten = 0;
        foreach ($orderItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $vendorItemId = trim((string)($item['vendorItemId'] ?? ''));
            if ($vendorItemId === '') {
                continue;
            }

            $shippingCount = (int)($item['shippingCount'] ?? 1);
            if ($shippingCount <= 0) {
                $shippingCount = 1;
            }

            $upsertStmt->execute([
                $shipmentBoxId,
                $vendorItemId,
                $shippingCount,
                $orderedAt,
                'NAVER',
                $trackingNo,
                $buyerName,
                $buyerPhone,
                $receiverName,
                $receiverPhone,
                $zipcode,
                $receiverAddress,
                $deliveryMessage,
                $shipmentBoxId,
                'NAVER_API',
            ]);

            $writtenCount++;
            $rowWritten++;
        }

        if ($rowWritten > 0) {
            $markNormalizedStmt->execute([(int)$row['id']]);
            $normalizedCount++;
        } else {
            $skippedCount++;
            job_log($jobId, 'warn', 'Skip row: no valid vendorItemId id=' . (string)$row['id']);
        }
    }

    job_log(
        $jobId,
        'info',
        'Naver normalize finished. read=' . $readCount
        . ', normalized=' . $normalizedCount
        . ', written=' . $writtenCount
        . ', skipped=' . $skippedCount
    );
}

function naver_normalize_to_mysql_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function naver_normalize_str($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    return '';
}