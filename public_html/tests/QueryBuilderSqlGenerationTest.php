<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Data/QueryBuilder.php';

use src\Data\QueryBuilder;

final class RecordingStatement extends PDOStatement
{
    public array $bindings = [];

    public function execute(?array $params = null): bool
    {
        $this->bindings = $params ?? [];
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}

final class RecordingPdo extends PDO
{
    public string $lastQuery = '';
    public ?RecordingStatement $lastStatement = null;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastQuery = $query;
        $this->lastStatement = new RecordingStatement();
        return $this->lastStatement;
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

$pdo = new RecordingPdo();
(new QueryBuilder($pdo, 'user'))->where('username', 'alice')->where('id', '>=', 5)->get();
assertSameValue('SELECT * FROM user WHERE username = ? AND id >= ?', $pdo->lastQuery, 'where() should generate safe SQL.');
assertSameValue(['alice', 5], $pdo->lastStatement->bindings, 'where() should bind values.');

$pdo = new RecordingPdo();
(new QueryBuilder($pdo, 'totp_email'))->where('id', 'IN', [1, 2])->where('deleted_at', 'IS', null)->get();
assertSameValue('SELECT * FROM totp_email WHERE id IN (?, ?) AND deleted_at IS NULL', $pdo->lastQuery, 'where() should support IN and IS NULL.');
assertSameValue([1, 2], $pdo->lastStatement->bindings, 'IN should bind each value.');

$pdo = new RecordingPdo();
(new QueryBuilder($pdo, 'totp_email'))->orderBy('created_at', 'DESC')->limit(10)->get();
assertSameValue('SELECT * FROM totp_email ORDER BY created_at DESC LIMIT 10', $pdo->lastQuery, 'orderBy() and limit() should generate safe SQL.');
assertSameValue([], $pdo->lastStatement->bindings, 'limit() should be constrained before interpolation, not string-bound through execute().');

$pdo = new RecordingPdo();
(new QueryBuilder($pdo, 'user'))->insert(['username' => 'alice', 'email' => 'alice@example.com']);
assertSameValue('INSERT INTO user (username, email) VALUES (?, ?)', $pdo->lastQuery, 'insert() should generate safe SQL.');
assertSameValue(['alice', 'alice@example.com'], $pdo->lastStatement->bindings, 'insert() should bind values.');

$pdo = new RecordingPdo();
(new QueryBuilder($pdo, 'user'))->where('id', 7)->update(['email' => 'new@example.com']);
assertSameValue('UPDATE user SET email = ? WHERE id = ?', $pdo->lastQuery, 'update() should generate safe SQL.');
assertSameValue(['new@example.com', 7], $pdo->lastStatement->bindings, 'update() should bind values.');

$pdo = new RecordingPdo();
(new QueryBuilder($pdo, 'user'))->where('id', 7)->delete();
assertSameValue('DELETE FROM user WHERE id = ?', $pdo->lastQuery, 'delete() should generate safe SQL.');
assertSameValue([7], $pdo->lastStatement->bindings, 'delete() should bind values.');

assertThrows(fn() => (new QueryBuilder(new RecordingPdo(), 'user'))->update(['email' => 'new@example.com']), 'Unconstrained update() should be rejected.');
assertThrows(fn() => (new QueryBuilder(new RecordingPdo(), 'user'))->delete(), 'Unconstrained delete() should be rejected.');

assertThrows(fn() => new QueryBuilder(new RecordingPdo(), 'user; DROP TABLE user'), 'Invalid table names should be rejected.');
assertThrows(fn() => new QueryBuilder(new RecordingPdo(), 'schema.user'), 'Schema-qualified table names should be rejected by the current simple identifier policy.');
assertThrows(fn() => (new QueryBuilder(new RecordingPdo(), 'user'))->where('email; DROP', 'x'), 'Invalid where columns should be rejected.');
assertThrows(fn() => (new QueryBuilder(new RecordingPdo(), 'user'))->where('user.email', 'x'), 'Qualified where columns should be rejected by the current simple identifier policy.');
assertThrows(fn() => (new QueryBuilder(new RecordingPdo(), 'user'))->where('email', 'REGEXP', 'x'), 'Invalid where operators should be rejected.');
assertThrows(fn() => (new QueryBuilder(new RecordingPdo(), 'user'))->orderBy('created_at DESC'), 'Invalid orderBy columns should be rejected.');
assertThrows(fn() => (new QueryBuilder(new RecordingPdo(), 'user'))->limit(-1), 'Negative limits should be rejected.');

fwrite(STDOUT, "QueryBuilder SQL generation tests passed.\n");
