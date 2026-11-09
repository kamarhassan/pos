<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class DatabaseBackupController extends Controller
{
    public function index()
    {
        $this->authorizeAdmin();

        $backups = collect(Storage::disk('local')->files('database-backups'))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => preg_match('/\Aqpos-backup-(\d{8})-(\d{6})-[A-Za-z0-9]{8}\.sqlite\z/', $name))
            ->map(function (string $name) {
                preg_match('/\Aqpos-backup-(\d{8})-(\d{6})-[A-Za-z0-9]{8}\.sqlite\z/', $name, $matches);

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
        abort_unless(preg_match('/\Aqpos-backup-\d{8}-\d{6}-[A-Za-z0-9]{8}\.sqlite\z/', $filename), 404);
        $path = "database-backups/{$filename}";
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    public function destroy(string $filename)
    {
        $this->authorizeAdmin();
        abort_unless(preg_match('/\Aqpos-backup-\d{8}-\d{6}-[A-Za-z0-9]{8}\.sqlite\z/', $filename), 404);
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

        if (!in_array(strtolower($data['backup']->getClientOriginalExtension()), ['sqlite', 'sqlite3', 'db'], true)) {
            throw ValidationException::withMessages([
                'backup' => 'Choose a SQLite database file with a .sqlite, .sqlite3, or .db extension.',
            ]);
        }

        $uploadedPath = $data['backup']->getRealPath();
        $this->validateSqliteDatabase($uploadedPath);

        $databasePath = $this->databasePath();
        $safetyBackup = Storage::disk('local')->path('database-backups/' . $this->createBackup());
        $this->checkpointDatabase();
        DB::purge();

        try {
            foreach (['-wal', '-shm'] as $suffix) {
                $sidecar = $databasePath . $suffix;
                if (is_file($sidecar) && !unlink($sidecar)) {
                    throw new RuntimeException('SQLite could not close its journal files before restore.');
                }
            }

            if (!copy($uploadedPath, $databasePath)) {
                throw new RuntimeException('The uploaded backup could not be copied to the database file.');
            }

            DB::reconnect();
            DB::select('SELECT 1');
        } catch (Throwable $exception) {
            DB::purge();
            if (!copy($safetyBackup, $databasePath)) {
                throw new RuntimeException(
                    'Restore failed and the safety backup could not be put back. The safety backup is available in storage/app/database-backups.',
                    0,
                    $exception
                );
            }

            DB::reconnect();
            throw $exception;
        }

        return to_route('backend.admin.settings.database')
            ->with('success', 'Database restored successfully. A safety backup of the previous database was also created.');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->can('database_backup'), 403);
    }

    private function createBackup(): string
    {
        $databasePath = $this->databasePath();
        Storage::disk('local')->makeDirectory('database-backups');
        $filename = 'qpos-backup-' . now()->format('Ymd-His') . '-' . Str::random(8) . '.sqlite';
        $backupPath = Storage::disk('local')->path("database-backups/{$filename}");
        $connection = DB::connection();
        $pdo = $connection->getPdo();

        $this->checkpointDatabase();
        $pdo->exec('VACUUM INTO ' . $pdo->quote($backupPath));

        $size = is_file($backupPath) ? filesize($backupPath) : false;
        if ($size === false || $size === 0) {
            throw new RuntimeException('SQLite did not create a valid backup file.');
        }

        return $filename;
    }

    private function databasePath(): string
    {
        abort_unless(DB::connection()->getDriverName() === 'sqlite', 409, 'Database backup and restore require SQLite.');

        $path = config('database.connections.sqlite.database');
        if (!$path || $path === ':memory:' || !is_file($path)) {
            throw new RuntimeException('The configured SQLite database file is unavailable.');
        }

        return realpath($path) ?: throw new RuntimeException('The configured SQLite database path is invalid.');
    }

    private function checkpointDatabase(): void
    {
        $checkpoint = DB::connection()->getPdo()
            ->query('PRAGMA wal_checkpoint(TRUNCATE)')
            ->fetch(PDO::FETCH_NUM);

        if ($checkpoint && (int) $checkpoint[0] !== 0) {
            throw new RuntimeException('SQLite could not checkpoint the database before backup or restore.');
        }
    }

    private function validateSqliteDatabase(string $path): void
    {
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            $migrations = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $exception) {
            throw ValidationException::withMessages([
                'backup' => 'The uploaded file is not a readable SQLite database.',
            ]);
        }

        $requiredTables = ['migrations', 'users', 'customers', 'patients', 'orders', 'products'];
        if (
            $integrity !== 'ok'
            || array_diff($requiredTables, $tables) !== []
            || !in_array('2026_11_09_080000_create_patients_table', $migrations, true)
        ) {
            throw ValidationException::withMessages([
                'backup' => 'The uploaded SQLite database is corrupt or does not contain a QPOS schema.',
            ]);
        }
    }
}
