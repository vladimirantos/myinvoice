<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class NoteRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array{id:int, title:string, body:string, created_at:string, updated_at:string}> */
    public function listAll(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, title, body, created_at, updated_at FROM notes ORDER BY updated_at DESC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['id'] = (int) $row['id'];
        return $rows;
    }

    /** @return array{id:int, title:string, body:string, created_at:string, updated_at:string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, title, body, created_at, updated_at FROM notes WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $row['id'] = (int) $row['id'];
        return $row;
    }

    public function create(string $title, string $body): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('INSERT INTO notes (title, body) VALUES (?, ?)');
        $stmt->execute([$title, $body]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, string $title, string $body): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE notes SET title = ?, body = ? WHERE id = ?');
        $stmt->execute([$title, $body, $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM notes WHERE id = ?');
        return $stmt->execute([$id]);
    }
}
