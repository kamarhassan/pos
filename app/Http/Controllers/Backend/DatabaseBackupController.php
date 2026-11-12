<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Portable database backup / restore (.sql) for SQLite and MySQL/MariaDB.
 *
 * The .sql file contains DATA ONLY, written as portable INSERT statements
 * (double-quoted identifiers, '' string escaping, no backslash escapes).
 * Restoring rebuilds the schema from the application's migrations
 * (migrate:fresh) and then loads the data, so a backup taken on SQLite can be
 * restored on MySQL and vice versa.
 */
class DatabaseBackupController extends Controller
{
    /** Tables that are rebuilt by migrations or are transient: not exported. */
    private const SKIP_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks'];

    /** Tables that must exist in a backup for it to be accepted. */
    private const REQUIRED_TABLES = ['users', 'customers', 'patients', 'orders', 'products'];

    public function index()
    {
        $this->authorizeAdmin();

        $pattern = $this->filePattern();

        $backups = collect(Storage::disk('local')->files('database-backups'))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => preg_match($pattern, $name))
            ->map(function (string $name) use ($pattern) {
                preg_match($pattern, $name, $matches);

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
        abort_unless(preg_match($this->filePattern(), $filename), 404);
        $path = "database-backups/{$filename}";
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    public function destroy(string $filename)
    {
        $this->authorizeAdmin();
        abort_unless(preg_match($this->filePattern(), $filename), 404);
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
        $sourceDriver = $this->validateSqlDump($uploadedPath);
        $currentDriver = $this->driver();

        $safetyBackup = Storage::disk('local')->path('database-backups/' . $this->createBackup());

        try {
            $this->importSql($uploadedPath);
        } catch (Throwable $exception) {
            try {
                $this->importSql($safetyBackup);
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    'Restore failed and the safety backup could not be put back. '
                    . 'The safety backup is available in storage/app/database-backups. '
                    . 'Restore error: ' . $exception->getMessage() . ' | '
                    . 'Rollback error: ' . $rollbackException->getMessage(),
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        $converted = $sourceDriver && $sourceDriver !== $currentDriver
            ? " (converted from {$sourceDriver} to {$currentDriver})"
            : '';

        return to_route('backend.admin.settings.database')
            ->with('success', "Database restored successfully{$converted}. A safety backup of the previous database was also created.");
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->can('database_backup'), 403);
    }

    /* ------------------------------------------------------------------ */
    /* System name / file naming (from config/system.php)                  */
    /* ------------------------------------------------------------------ */

    private function systemName(): string
    {
        return (string) (config('system.name') ?: config('app.name', 'Database'));
    }

    private function filePrefix(): string
    {
        return Str::slug($this->systemName()) ?: 'database';
    }

    private function filePattern(): string
    {
        return '/\A' . preg_quote($this->filePrefix(), '/') . '-backup-(\d{8})-(\d{6})-[A-Za-z0-9]{8}\.sql\z/';
    }

    /* ------------------------------------------------------------------ */
    /* Driver                                                              */
    /* ------------------------------------------------------------------ */

    private function driver(): string
    {
        $driver = DB::connection()->getDriverName();

        // MariaDB uses the MySQL grammar here.
        abort_unless(in_array($driver, ['sqlite', 'mysql', 'mariadb'], true), 409, 'Database backup and restore require SQLite or MySQL.');

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    /* ------------------------------------------------------------------ */
    /* Backup (export .sql)                                                */
    /* ------------------------------------------------------------------ */

    private function createBackup(): string
    {
        $driver = $this->driver();
        Storage::disk('local')->makeDirectory('database-backups');
        $filename = $this->filePrefix() . '-backup-' . now()->format('Ymd-His') . '-' . Str::random(8) . '.sql';
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

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The backup file could not be opened for writing.');
        }

        try {
            fwrite($handle, '-- ' . $this->systemName() . " database backup (data only, portable)\n");
            fwrite($handle, "-- backup-driver: {$driver}\n");
            fwrite($handle, '-- created: ' . now()->toDateTimeString() . "\n\n");

            foreach ($this->listTables($pdo, $driver) as $table) {
                fwrite($handle, "-- Table: {$table}\n");
                $this->dumpRows($handle, $pdo, $driver, $table);
                fwrite($handle, "\n");
            }

            fwrite($handle, "-- backup-end\n");
        } finally {
            fclose($handle);
        }
    }

    private function listTables(PDO $pdo, string $driver): array
    {
        if ($driver === 'mysql') {
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
                ->fetchAll(PDO::FETCH_COLUMN, 0);
        } else {
            $tables = $pdo->query(
                "SELECT name FROM sqlite_master
                 WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                 ORDER BY name"
            )->fetchAll(PDO::FETCH_COLUMN);
        }

        return array_values(array_diff($tables, self::SKIP_TABLES));
    }

    private function quoteIdentifier(string $name): string
    {
        // Double quotes work in SQLite and in MySQL with ANSI_QUOTES (set during restore).
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function dumpRows($handle, PDO $pdo, string $driver, string $table): void
    {
        // Reading uses the driver's native quoting; the OUTPUT uses portable double quotes.
        $nativeTable = $driver === 'mysql'
            ? '`' . str_replace('`', '``', $table) . '`'
            : $this->quoteIdentifier($table);
        $rows = $pdo->query('SELECT * FROM ' . $nativeTable);
        $tableSql = $this->quoteIdentifier($table);
        $columns = null;
        $batch = [];

        $flush = function () use (&$batch, &$columns, $handle, $tableSql) {
            if ($batch === []) {
                return;
            }
            fwrite($handle, "INSERT INTO {$tableSql} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = [];
        };

        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns ??= implode(', ', array_map(fn ($c) => $this->quoteIdentifier($c), array_keys($row)));
            $batch[] = '(' . implode(', ', array_map(fn ($v) => $this->sqlValue($v), $row)) . ')';

            if (count($batch) >= 100) {
                $flush();
            }
        }

        $flush();
    }

    /** Portable literal: only '' escaping, never backslashes. */
    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        // Binary data (invalid UTF-8 or NUL bytes) is written as a hex literal.
        if ($value !== '' && (str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8'))) {
            return "X'" . bin2hex($value) . "'";
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /* ------------------------------------------------------------------ */
    /* Restore (import .sql)                                               */
    /* ------------------------------------------------------------------ */

    private function importSql(string $path): void
    {
        set_time_limit(0);
        $driver = $this->driver();

        // 1) Rebuild the schema for the CURRENT database from the migrations.
        Artisan::call('migrate:fresh', ['--force' => true]);

        $pdo = DB::connection()->getPdo();
        $previousMode = null;

        // 2) Prepare the session so the portable INSERTs are understood.
        if ($driver === 'mysql') {
            $previousMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $pdo->exec("SET SESSION sql_mode = 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES,NO_AUTO_VALUE_ON_ZERO'");
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->exec('SET NAMES utf8mb4');
        } else {
            $pdo->exec('PRAGMA foreign_keys=OFF');
        }

        try {
            // 3) Empty every table: migrations may insert default rows (admin user,
            //    settings, permissions...) that would clash with the backup's rows.
            foreach ($this->listTables($pdo, $driver) as $table) {
                $native = $driver === 'mysql'
                    ? '`' . str_replace('`', '``', $table) . '`'
                    : $this->quoteIdentifier($table);
                $pdo->exec("DELETE FROM {$native}");
            }

            // 4) Load the data.
            foreach ($this->readStatements($path) as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            try {
                if ($driver === 'mysql') {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                    $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($previousMode));
                } else {
                    $pdo->exec('PRAGMA foreign_keys=ON');
                }
            } catch (Throwable) {
            }
        }
    }

    /**
     * Reads a .sql file statement by statement without loading it all in memory.
     * Semicolons inside quoted text are not treated as statement ends.
     */
    private function readStatements(string $path): Generator
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
                        if ($char === $quote) {
                            $quote = null; // a doubled quote ('' or "") re-opens on the next char
                        }
                    } elseif ($char === "'" || $char === '"') {
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

    /** Returns the driver the backup was taken from (if recorded). */
    private function validateSqlDump(string $path): ?string
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
            if (preg_match('/^-- backup-driver: (\w+)/', $line, $m)) {
                $driver ??= $m[1];
            } elseif (preg_match('/^-- Table: (.+)$/', rtrim($line), $m)) {
                $tables[] = $m[1];
            } elseif (str_starts_with($line, '-- backup-end')) {
                $complete = true;
            }
        }
        fclose($handle);

        $problems = [];
        $missingTables = array_diff(self::REQUIRED_TABLES, $tables);
        if ($missingTables !== []) {
            $problems[] = 'missing tables: ' . implode(', ', $missingTables);
        }
        if (!$complete) {
            $problems[] = 'missing the "-- backup-end" marker (file is truncated, edited, or not created by this system)';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages([
                'backup' => 'Invalid ' . $this->systemName() . ' backup: ' . implode('; ', $problems) . '.',
            ]);
        }

        return $driver;
    }
}