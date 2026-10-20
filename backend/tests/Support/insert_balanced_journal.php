<?php

declare(strict_types=1);

/**
 * Posts one balanced journal and commits. Prints "ok" or "err".
 */
$cfg = json_decode((string) file_get_contents($argv[1]), true);
$which = $argv[2] === 'b' ? 'journal_b' : 'journal_a';
$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO ledger_journals (id, kind, order_id, idempotency_key, created_at) VALUES (?, \'sale\', NULL, ?, now())')
        ->execute([$cfg[$which], 'test:race:'.$cfg[$which]]);
    $line = $pdo->prepare('INSERT INTO ledger_lines (id, journal_id, account, direction, amount_minor, currency, created_at) VALUES (?, ?, ?, ?, 100, \'USD\', now())');
    $line->execute([$cfg[$which.'_debit'], $cfg[$which], $cfg['account'], 'debit']);
    $line->execute([$cfg[$which.'_credit'], $cfg[$which], 'platform:sale', 'credit']);
    $pdo->commit();
    echo "ok\n";
} catch (PDOException) {
    echo "err\n";
}
