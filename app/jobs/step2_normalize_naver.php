<?php
declare(strict_types=1);

/**
 * NAVER normalize with the same structure as step2_normalize_coupang.
 * - Source: orders_raw (platform='naver', is_normalized=0, created_at range)
 * - Target: coupang_order_excel upsert
 * - Complete mark: orders_raw.is_normalized=1, normalized_at=NOW()
 */
function step2_normalize_naver(int $jobId, string $from, string $to): void
{
    job_log($jobId, 'info', "Normalize started: {$from} ~ {$to}");

    $pdo = db();
    $prepareIds = [];

    $stmt = $pdo->prepare(
        'SELECT id, order_no, raw_json
         FROM orders_raw
         WHERE platform = "naver"
           AND is_normalized = 0
           AND created_at BETWEEN ? AND ?
         ORDER BY id ASC'
    );
    $stmt->execute([$from, $to]);

    $up = $pdo->prepare(
        'INSERT INTO coupang_order_excel (
            order_no, option_id, qty, ordered_at, carrier_name, tracking_no,
            buyer_name, buyer_phone, receiver_name, receiver_phone,
            zipcode, receiver_address, delivery_message, shipment_box_id, source_file
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
            shipment_box_id = VALUES(shipment_box_id)'
    );

    $read = 0;
    $written = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $read++;

        $raw = json_decode((string)$row['raw_json'], true);
        if (!is_array($raw)) {
            continue;
        }

        $orderedAt = $raw['orderedAt'] ?? null;
        if (!$orderedAt) {
            continue;
        }

        $orderedAt2 = str_replace('T', ' ', (string)$orderedAt);

        $receiver = $raw['receiver'] ?? [];
        $orderer = $raw['orderer'] ?? [];

        $carrier = 'KGB';
        $tracking = $raw['invoiceNumber'] ?? null;

        $buyerName = $orderer['name'] ?? null;
        $buyerPhone = $orderer['safeNumber'] ?? ($orderer['ordererNumber'] ?? null);

        $recvName = $receiver['name'] ?? null;
        $recvPhone = $receiver['safeNumber'] ?? ($receiver['receiverNumber'] ?? null);

        $zipcode = $receiver['postCode'] ?? null;
        $addr1 = $receiver['addr1'] ?? null;
        $addr2 = $receiver['addr2'] ?? null;

        $fullAddr = trim((string)$addr1 . ' ' . (string)$addr2);

        $deliveryMsg = $raw['parcelPrintMessage'] ?? null;
        $shipmentBoxId = $raw['shipmentBoxId'] ?? null;

        if (!$shipmentBoxId) {
            continue;
        }

        $orderItems = $raw['orderItems'] ?? [];

        foreach ($orderItems as $item) {
            $optionId = $item['vendorItemId'] ?? null;
            $qty = $item['shippingCount'] ?? 1;

            if (!$optionId) {
                continue;
            }

            $up->execute([
                (string)$shipmentBoxId,
                (string)$optionId,
                (int)$qty,
                $orderedAt2,
                $carrier,
                $tracking,
                $buyerName,
                $buyerPhone,
                $recvName,
                $recvPhone,
                $zipcode,
                $fullAddr,
                $deliveryMsg,
                $shipmentBoxId,
                'API',
            ]);

            $written++;
        }

        $prepareIds[$shipmentBoxId] = $shipmentBoxId;

        $pdo->prepare(
            'UPDATE orders_raw
             SET is_normalized = 1,
                 normalized_at = NOW()
             WHERE id = ?'
        )->execute([$row['id']]);
    }

    job_log($jobId, 'info', "Read: {$read}, Written: {$written}");
    job_log($jobId, 'info', 'Normalize finished');

    // Keep prepareIds structure but do not call step3_naver_prepare yet.
    if ($prepareIds) {
        job_log($jobId, 'info', 'Prepare candidates: ' . count($prepareIds));
    }
}