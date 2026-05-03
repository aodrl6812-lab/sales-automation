<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

class BoardService
{
    public function __construct()
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $pdo = db();
        $sql = 'CREATE TABLE IF NOT EXISTS board_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            work_content TEXT NOT NULL,
            work_status VARCHAR(20) NOT NULL DEFAULT "등록",
            row_status VARCHAR(20) NOT NULL DEFAULT "진행",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_board_tasks_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        $pdo->exec($sql);
    }

    public function getList(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM board_tasks ORDER BY id DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM board_tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $title = trim((string)($data['title'] ?? ''));
        $workContent = trim((string)($data['work_content'] ?? ''));
        $workStatus = trim((string)($data['work_status'] ?? '등록'));
        $rowStatus = trim((string)($data['row_status'] ?? '진행'));

        if ($title === '') {
            $title = '제목 없음';
        }

        if ($workContent === '') {
            $workContent = '-';
        }

        $allowedWorkStatus = ['등록', '진행', '완료'];
        if (!in_array($workStatus, $allowedWorkStatus, true)) {
            $workStatus = '등록';
        }

        $allowedRowStatus = ['진행', '삭제'];
        if (!in_array($rowStatus, $allowedRowStatus, true)) {
            $rowStatus = '진행';
        }

        $pdo = db();
        if ($id === null) {
            $stmt = $pdo->prepare('INSERT INTO board_tasks (title, work_content, work_status, row_status) VALUES (?, ?, ?, ?)');
            $stmt->execute([$title, $workContent, $workStatus, $rowStatus]);
            return (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('UPDATE board_tasks SET title=?, work_content=?, work_status=?, row_status=? WHERE id=?');
        $stmt->execute([$title, $workContent, $workStatus, $rowStatus, $id]);

        return $id;
    }
}