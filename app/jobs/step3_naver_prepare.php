<?php
declare(strict_types=1);

/**
 * NAVER prepare status handler (mock-only).
 *
 * - No external API call
 * - No schema change
 * - Status result is recorded only in job_log
 */
function step3_naver_prepare(int $jobId, array $shipmentBoxIds): void
{
    if (!$shipmentBoxIds) {
        job_log($jobId, 'warn', 'NAVER prepare skipped: empty shipmentBoxIds');
        return;
    }

    $pdo = db();
    $select = $pdo->prepare(
        'SELECT order_no, tracking_no, shipped_at
         FROM coupang_order_excel
         WHERE shipment_box_id = ?
         ORDER BY id ASC
         LIMIT 1'
    );

    $total = 0;
    $prepared = 0;
    $skipped = 0;
    $missing = 0;

    foreach ($shipmentBoxIds as $rawId) {
        $shipmentBoxId = trim((string)$rawId);
        if ($shipmentBoxId === '') {
            $skipped++;
            job_log($jobId, 'warn', 'NAVER prepare skip: empty shipmentBoxId');
            continue;
        }

        $total++;
        $select->execute([$shipmentBoxId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $missing++;
            job_log($jobId, 'warn', 'NAVER prepare missing order: ' . $shipmentBoxId);
            continue;
        }

        $trackingNo = (string)($row['tracking_no'] ?? '');
        $shippedAt = $row['shipped_at'] ?? null;

        $isPrepare = ($trackingNo === '') && ($shippedAt === null || $shippedAt === '');

        if ($isPrepare) {
            $prepared++;
            job_log($jobId, 'info', 'NAVER prepare: ' . $shipmentBoxId);
        } else {
            $skipped++;
            job_log(
                $jobId,
                'info',
                'NAVER prepare skip(not target): ' . $shipmentBoxId
                . ', tracking=' . ($trackingNo !== '' ? 'Y' : 'N')
                . ', shipped=' . ($shippedAt ? 'Y' : 'N')
            );
        }
    }

    job_log(
        $jobId,
        'info',
        'NAVER prepare finished: total=' . $total
        . ', prepared=' . $prepared
        . ', skipped=' . $skipped
        . ', missing=' . $missing
    );
}