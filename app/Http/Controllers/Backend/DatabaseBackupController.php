<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

class DatabaseBackupController extends Controller
{
    private const FILE_PATTERN = '/\AKamareyecare-backup-(\d{8})-(\d{6})-[A-Za-z0-9]{8}\.sql\z/';
    private const REQUIRED_TABLES = ['migrations', 'users', 'customers', 'patients', 'orders', 'products'];

    public function index()
    {
        $this->authorizeAdmin();

        $backups = collect(Storage::disk('local')->files('database-backups'))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => preg_match(self::FILE_PATTERN, $name))
            ->map(function (string $name) {
                preg_match(self::FILE_PATTERN, $name, $matches);

                return [
                    'filename' => $name,
                    'created_at' => Carbon::createFromFormat(
                        '!Ymd-His',
                        "{$matches[1]}-{$matches[2]}",
                        config('app.timezone')
                    ),
                ];
            })
            ->sortByDesc(fn (array $backup) => $backup['created_at']->timestamp)
            ->values();

        return view('backend.settings.database', compact('backups'));
    }

    public function store()
    {
        $this->authorizeAdmin();
        $filename = $this->createBackup();

        return to_route('backend.admin.settings.database')
            ->with('success', "Backup created: {$filename}");
    }

    public function download(string $filename)
    {
        $this->authorizeAdmin();
        abort_unless(preg_match(self::FILE_PATTERN, $filename), 404);
        $path = "database-backups/{$filename}";
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    public function destroy(string $filename)
    {
        $this->authorizeAdmin();
        abort_unless(preg_match(self::FILE_PATTERN, $filename), 404);
        $path = "database-backups/{$filename}";
        abort_unless(Storage::disk('local')->exists($path), 404);

        if (!Storage::disk('local')->delete($path)) {
            throw new RuntimeException('The database backup could not be deleted.');
        }

        return to_route('backend.admin.settings.database')
            ->with('success', "Backup deleted: {$filename}");
    }

    public function restore(Request $request)
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'backup' => ['required', 'file'],
        ]);

        if (strtolower($data['backup']->getClientOriginalExtension()) !== 'sql') {
            throw ValidationException::withMessages([
                'backup' => 'Choose a database backup file with a .sql extension.',
            ]);
        }

        $uploadedPath = $data['backup']->getRealPath();
        $this->validateSqlDump($uploadedPath);

        $safetyBackup = Storage::disk('local')->path('database-backups/' . $this->createBackup());

        try {
            $this->importSql($uploadedPath);
        } catch (Throwable $exception) {
            try {
                $this->importSql($safetyBackup);
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    'Restore failed and the safety backup could not be put back. The safety backup is available in storage/app/database-backups.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        return to_route('backend.admin.settings.database')
            ->with('success', 'Database restored successfully. A safety backup of the previous database was also created.');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->can('database_backup'), 403);
    }

    /* ------------------------------------------------------------------ */
    /* Driver                                                              */
    /* ------------------------------------------------------------------ */

    private function driver(): string
    {
        $driver = DB::connection()->getDriverName();

        // MariaDB uses the same connection driver name as MySQL in Laravel.
        abort_unless(in_array($driver, ['sqlite', 'mysql', 'mariadb'], true), 409, 'Database backup and restore require SQLite or MySQL.');

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    private function quoteIdentifier(string $driver, string $name): string
    {
        return $driver === 'mysql'
            ? '`' . str_replace('`', '``', $name) . '`'
            : '"' . str_replace('"', '""', $name) . '"';
    }

    /* ------------------------------------------------------------------ */
    /* Backup (export .sql)                                                */
    /* ------------------------------------------------------------------ */

    private function createBackup(): string
    {
        $driver = $this->driver();
        Storage::disk('local')->makeDirectory('database-backups');
        $filename = 'Kamareyecare-backup-' . now()->format('Ymd-His') . '-' . Str::random(8) . '.sql';
        $backupPath = Storage::disk('local')->path("database-backups/{$filename}");

        try {
            $this->writeDump($backupPath, $driver);
        } catch (Throwable $exception) {
            if (is_file($backupPath)) {
                @unlink($backupPath);
            }
            throw $exception;
        }

        $size = is_file($backupPath) ? filesize($backupPath) : false;
        if ($size === false || $size === 0) {
            throw new RuntimeException('The database backup file was not created.');
        }

        return $filename;
    }

    private function writeDump(string $path, string $driver): void
    {
        set_time_limit(0);
        $pdo = DB::connection()->getPdo();
        $q = fn (string $name) => $this->quoteIdentifier($driver, $name);

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The backup file could not be opened for writing.');
        }

        try {
            fwrite($handle, "-- Kamareyecare database backup\n");
            fwrite($handle, "-- Kamareyecare-driver: {$driver}\n");
            fwrite($handle, '-- created: ' . now()->toDateTimeString() . "\n\n");

            if ($driver === 'mysql') {
                fwrite($handle, "SET NAMES utf8mb4;\n");
                fwrite($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
                fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
            } else {
                fwrite($handle, "PRAGMA foreign_keys=OFF;\n\n");
            }

            foreach ($this->listTables($pdo, $driver) as $table) {
                fwrite($handle, "-- Table: {$table}\n");
                fwrite($handle, 'DROP TABLE IF EXISTS ' . $q($table) . ";\n");
                fwrite($handle, $this->createTableSql($pdo, $driver, $table) . ";\n");
                $this->dumpRows($handle, $pdo, $driver, $table);
                fwrite($handle, "\n");
            }

            if ($driver === 'sqlite') {
                // Indexes, triggers and views come after the data.
                $extras = $pdo->query(
                    "SELECT sql FROM sqlite_master
                     WHERE type IN ('index', 'trigger', 'view')
                       AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
                     ORDER BY CASE type WHEN 'view' THEN 0 WHEN 'index' THEN 1 ELSE 2 END, name"
                )->fetchAll(PDO::FETCH_COLUMN);

                foreach ($extras as $sql) {
                    fwrite($handle, $sql . ";\n");
                }
                fwrite($handle, "\nPRAGMA foreign_keys=ON;\n");
            } else {
                fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            }

            fwrite($handle, "\n-- Kamareyecare-backup-end\n");
        } finally {
            fclose($handle);
        }
    }

    private function listTables(PDO $pdo, string $driver): array
    {
        if ($driver === 'mysql') {
            return $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
                ->fetchAll(PDO::FETCH_COLUMN, 0);
        }

        return $pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function createTableSql(PDO $pdo, string $driver, string $table): string
    {
        if ($driver === 'mysql') {
            $row = $pdo->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($driver, $table))->fetch(PDO::FETCH_NUM);

            return $row[1];
        }

        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetchColumn();
    }

    private function dumpRows($handle, PDO $pdo, string $driver, string $table): void
    {
        $q = fn (string $name) => $this->quoteIdentifier($driver, $name);
        $rows = $pdo->query('SELECT * FROM ' . $q($table));
        $columns = null;
        $batch = [];

        $flush = function () use (&$batch, &$columns, $handle, $q, $table) {
            if ($batch === []) {
                return;
            }
            fwrite($handle, 'INSERT INTO ' . $q($table) . " ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = [];
        };

        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns ??= implode(', ', array_map($q, array_keys($row)));
            $batch[] = '(' . implode(', ', array_map(fn ($value) => $this->sqlValue($pdo, $value), $row)) . ')';

            if (count($batch) >= 100) {
                $flush();
            }
        }

        $flush();
    }

    private function sqlValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        // Binary data (not valid UTF-8) is written as a hex literal.
        if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
            return "X'" . bin2hex($value) . "'";
        }

        return $pdo->quote($value);
    }

    /* ------------------------------------------------------------------ */
    /* Restore (import .sql)                                               */
    /* ------------------------------------------------------------------ */

    private function importSql(string $path): void
    {
        set_time_limit(0);
        $driver = $this->driver();
        $pdo = DB::connection()->getPdo();
        $backslashEscapes = $driver === 'mysql';

        try {
            foreach ($this->readStatements($path, $backslashEscapes) as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            // Make sure the session never stays with constraints disabled.
            try {
                $pdo->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');
            } catch (Throwable) {
            }
        }
    }

    /**
     * Reads a .sql file statement by statement without loading it all in memory.
     * Semicolons inside quoted strings are not treated as statement ends.
     */
    private function readStatements(string $path, bool $backslashEscapes): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The SQL file could not be opened.');
        }

        $buffer = '';
        $quote = null;

        try {
            while (($line = fgets($handle)) !== false) {
                if ($quote === null && $buffer === '') {
                    $trimmed = ltrim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                        continue;
                    }
                }

                $length = strlen($line);
                for ($i = 0; $i < $length; $i++) {
                    $char = $line[$i];

                    if ($quote !== null) {
                        if ($backslashEscapes && $char === '\\') {
                            $i++;
                        } elseif ($char === $quote) {
                            $quote = null;
                        }
                    } elseif ($char === "'" || $char === '"' || $char === '`') {
                        $quote = $char;
                    }
                }

                $buffer .= $line;

                if ($quote === null && str_ends_with(rtrim($line), ';')) {
                    yield trim($buffer);
                    $buffer = '';
                }
            }

            if (trim($buffer) !== '') {
                yield trim($buffer);
            }
        } finally {
            fclose($handle);
        }
    }

    private function validateSqlDump(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['backup' => 'The uploaded file could not be read.']);
        }

        $driver = null;
        $tables = [];
        $complete = false;

        while (($line = fgets($handle)) !== false) {
            if (!str_starts_with($line, '--')) {
                continue;
            }
            if (preg_match('/^-- Kamareyecare-driver: (\w+)/', $line, $m)) {
                $driver ??= $m[1];
            } elseif (preg_match('/^-- Table: (.+)$/', rtrim($line), $m)) {
                $tables[] = $m[1];
            } elseif (str_starts_with($line, '-- Kamareyecare-backup-end')) {
                $complete = true;
            }
        }
        fclose($handle);

        $problems = [];
        if ($driver === null) {
            $problems[] = 'missing the "-- Kamareyecare-driver" header (not created by this system)';
        }
        $missingTables = array_diff(self::REQUIRED_TABLES, $tables);
        if ($missingTables !== []) {
            $problems[] = 'missing tables: ' . implode(', ', $missingTables);
        }
        if (!$complete) {
            $problems[] = 'missing the "-- Kamareyecare-backup-end" marker (file is truncated or was edited)';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages([
                'backup' => 'Invalid Kamareyecare backup: ' . implode('; ', $problems) . '.',
            ]);
        }

        $current = $this->driver();
        if ($driver !== $current) {
            throw ValidationException::withMessages([
                'backup' => "This backup was created from a {$driver} database, but the current database is {$current}.",
            ]);
        }
    }
}