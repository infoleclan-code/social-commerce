<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/** Writes a pg_dump into storage/app/backups and drops dumps older than 7 days. */
final class BackupDatabase extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Dump the PostgreSQL database to storage/app/backups';

    public function handle(): int
    {
        $directory = storage_path('app/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->error('Backup directory is not available.');

            return self::FAILURE;
        }

        $file = $directory.'/db-'.now()->format('Ymd-His').'.sql';
        $connection = config('database.connections.pgsql');
        $result = Process::env([
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
        ])->timeout(180)->run([
            'pg_dump',
            '-h', (string) ($connection['host'] ?? '127.0.0.1'),
            '-p', (string) ($connection['port'] ?? '5432'),
            '-U', (string) ($connection['username'] ?? ''),
            '-d', (string) ($connection['database'] ?? ''),
            '--no-owner',
            '-f', $file,
        ]);

        if (! $result->successful() || ! is_file($file) || filesize($file) === 0) {
            if (is_file($file)) {
                unlink($file);
            }
            $this->error('Database backup failed.');

            return self::FAILURE;
        }

        $this->prune($directory);
        $this->info('Database backup written.');

        return self::SUCCESS;
    }

    private function prune(string $directory): void
    {
        $files = glob($directory.'/db-*.sql') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - 7)) as $old) {
            unlink($old);
        }
    }
}
