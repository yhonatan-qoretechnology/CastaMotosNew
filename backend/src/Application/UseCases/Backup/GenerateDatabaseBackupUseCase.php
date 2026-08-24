<?php

declare(strict_types=1);

namespace App\Application\UseCases\Backup;

use PDO;

/**
 * Backup completo de la base de datos en SQL puro (sin depender de que el
 * binario "mysqldump" esté disponible/permitido en el hosting — muchos
 * planes compartidos bloquean shell_exec()/exec() por seguridad). Arma el
 * mismo tipo de script que generaría mysqldump: DROP + CREATE TABLE por
 * cada tabla, seguido de sus INSERT — restaurable tal cual en phpMyAdmin o
 * por consola.
 */
final class GenerateDatabaseBackupUseCase
{
    public function __construct(private PDO $connection)
    {
    }

    /** @return array{sql: string, database_name: string, table_count: int} */
    public function handle(): array
    {
        $databaseName = (string) $this->connection->query('SELECT DATABASE()')->fetchColumn();
        $tables = $this->connection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $sql = "-- Backup de \"{$databaseName}\" — generado " . date('Y-m-d H:i:s') . "\n"
            . "-- CASTAMOTO — /admin → Configuración → Backup de la base de datos\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $sql .= $this->dumpTable((string) $table);
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        return [
            'sql' => $sql,
            'database_name' => $databaseName,
            'table_count' => count($tables),
        ];
    }

    private function dumpTable(string $table): string
    {
        $quotedTable = $this->quoteIdentifier($table);

        $createRow = $this->connection->query("SHOW CREATE TABLE {$quotedTable}")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? '';

        $sql = "--\n-- Tabla `{$table}`\n--\nDROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n\n";

        $rowsStmt = $this->connection->query("SELECT * FROM {$quotedTable}");
        $rowCount = 0;

        foreach ($rowsStmt as $row) {
            $columns = implode(', ', array_map([$this, 'quoteIdentifier'], array_keys($row)));
            $values = implode(', ', array_map(fn ($value) => $this->quoteValue($value), array_values($row)));
            $sql .= "INSERT INTO {$quotedTable} ({$columns}) VALUES ({$values});\n";
            $rowCount++;
        }

        if ($rowCount > 0) {
            $sql .= "\n";
        }

        return $sql;
    }

    /** Nombres de tabla/columna vienen del propio esquema (SHOW TABLES/fetch de fila), no de input externo — igual se escapan con backticks por prolijidad. */
    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->quote((string) $value);
    }
}
