<?php
/**
 * Abstracción de persistencia para motores agnósticos (WordPress, Standalone, Lite).
 *
 * No depende de $wpdb ni de PDO directamente: los adaptadores concretos los encapsulan.
 */

interface Xabia_Database_Interface {

    /**
     * Elimina filas que coinciden con $where (AND entre columnas).
     *
     * @param string $table Nombre lógico o físico de tabla (el adaptador resuelve prefijos).
     * @param array<string, scalar|null> $where
     * @return int|false Filas afectadas o false en error
     */
    public function delete(string $table, array $where);

    /**
     * Inserta una fila.
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @return bool
     */
    public function insert(string $table, array $data): bool;

    /**
     * Ejecuta SQL arbitrario (transacciones, DDL, etc.).
     *
     * @param string $sql
     * @return int|bool Filas afectadas, true en éxito sin conteo, o false en error
     */
    public function query(string $sql);
}
