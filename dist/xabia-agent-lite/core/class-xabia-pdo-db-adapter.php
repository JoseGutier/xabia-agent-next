<?php
/**
 * Adaptador PDO puro → Xabia_Database_Interface (Standalone / CLI).
 */

class Xabia_PDO_DB_Adapter implements Xabia_Database_Interface {

    /** @var PDO */
    private $pdo;

    /** @var string */
    private $table_prefix;

    public function __construct(PDO $pdo, string $table_prefix = '') {
        $this->pdo = $pdo;
        $this->table_prefix = $table_prefix;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array<string, scalar|null> $where
     */
    public function delete(string $table, array $where) {
        $table = $this->qualify_table($table);
        if ($where === []) {
            return false;
        }

        $parts = [];
        $params = [];
        $i = 0;
        foreach ($where as $column => $value) {
            $col = $this->quote_identifier((string) $column);
            $key = ':w' . $i;
            $parts[] = $col . ' = ' . $key;
            $params[$key] = $value;
            $i++;
        }

        $sql = 'DELETE FROM ' . $this->quote_identifier($table) . ' WHERE ' . implode(' AND ', $parts);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): bool {
        $table = $this->qualify_table($table);
        if ($data === []) {
            return false;
        }

        $columns = [];
        $placeholders = [];
        $params = [];
        $i = 0;
        foreach ($data as $column => $value) {
            $columns[] = $this->quote_identifier((string) $column);
            $key = ':i' . $i;
            $placeholders[] = $key;
            $params[$key] = $value;
            $i++;
        }

        $sql = 'INSERT INTO ' . $this->quote_identifier($table) . ' ('
            . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    public function query(string $sql) {
        return $this->pdo->exec($sql);
    }

    public function begin_transaction(): void {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollback(): void {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function qualify_table(string $table): string {
        $table = trim($table);
        if ($table === '') {
            return '';
        }
        if ($this->table_prefix !== '' && strpos($table, $this->table_prefix) !== 0) {
            if (strpos($table, 'xabia_') === 0) {
                return $this->table_prefix . $table;
            }
        }

        return $table;
    }

    private function quote_identifier(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
