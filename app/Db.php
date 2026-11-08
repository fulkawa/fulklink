<?php
/**
 * PDO 封装。全站只在这里碰 SQL 驱动，业务层只调这里的方法。
 */
declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = Config::get('db', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'] ?? '127.0.0.1',
            (int) ($c['port'] ?? 3306),
            $c['name'] ?? 'fulklink',
            $c['charset'] ?? 'utf8mb4'
        );
        self::$pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        self::$pdo->exec("SET time_zone = '+08:00'");
        return self::$pdo;
    }

    /** 预编译执行，返回 statement */
    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 取第一行第一列 */
    public static function val(string $sql, array $params = [], $default = null)
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    /** 按关联数组插入，返回自增 ID */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    /** 按关联数组更新，必须给 where 子句，避免误伤全表 */
    public static function update(string $table, array $set, string $where, array $whereParams = []): int
    {
        if (trim($where) === '') {
            throw new \InvalidArgumentException('update 必须带 where 条件');
        }
        $parts = [];
        foreach (array_keys($set) as $col) {
            $parts[] = '`' . $col . '` = ?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $parts) . ' WHERE ' . $where;
        return self::exec($sql, array_merge(array_values($set), $whereParams));
    }

    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 写事件日志。日志失败不能影响主流程。 */
    public static function log(string $kind, string $message, array $meta = [], string $level = 'info'): void
    {
        try {
            self::insert('events', [
                'level'      => $level,
                'kind'       => substr($kind, 0, 48),
                'message'    => mb_substr($message, 0, 1000),
                'meta'       => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 日志表写不进去就算了，不能连累业务
        }
    }
}
