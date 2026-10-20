<?php

declare(strict_types=1);

/**
 * Inserts one order. A second process with the same idempotency key must fail closed.
 * Prints "ok" or "dup". Never prints the database password.
 */
$cfg = json_decode((string) file_get_contents($argv[1]), true);
$which = $argv[2] === 'b' ? 'order_b' : 'order_a';
$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO orders (id, buyer_id, product_id, quantity, unit_price_minor, total_minor, currency, status, payment_status, idempotency_key, created_at)
         VALUES (?, ?, ?, 1, 1000, 1000, \'USD\', \'processing\', \'pending\', ?, now())'
    );
    $stmt->execute([$cfg[$which], $cfg['buyer'], $cfg['product'], $cfg['key']]);
    echo "ok\n";
} catch (PDOException $exception) {
    echo (($exception->errorInfo[0] ?? '') === '23505') ? "dup\n" : "err\n";
}
