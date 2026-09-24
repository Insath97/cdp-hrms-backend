<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DatabaseService
{
    /**
     * Export the database to a SQL file.
     *
     * @return string Path to the generated SQL file
     * @throws \Exception
     */
    public function export(): string
    {
        $config = $this->getConnectionConfig();

        $filename = 'backup-' . date('Y-m-d-H-i-s') . '.sql';
        $directory = storage_path('app/backups');

        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . DIRECTORY_SEPARATOR . $filename;

        $mysqldump = $this->resolveBinary('mysqldump');

        $command = array_values(array_filter([
            $mysqldump,
            '--user=' . $config['username'],
            $config['password'] ? '--password=' . $config['password'] : null,
            '--host=' . $config['host'],
            '--port=' . $config['port'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--result-file=' . $filePath,
            $config['database'],
        ]));

        $process = new Process($command, null, $this->buildProcessEnv());
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('Database export failed', [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
                'command' => $command,
            ]);
            throw new \Exception('Database export failed: ' . ($process->getErrorOutput() ?: 'Unknown error (exit code ' . $process->getExitCode() . '). Ensure mysqldump is installed and configured.'));
        }

        Log::info('Database exported successfully', ['file' => $filePath]);

        return $filePath;
    }

    /**
     * Import a SQL file into the database.
     *
     * @param string $filePath
     * @return void
     * @throws \Exception
     */
    public function import(string $filePath): void
    {
        $config = $this->getConnectionConfig();

        if (!file_exists($filePath)) {
            throw new \Exception("SQL file not found at: {$filePath}");
        }

        $mysql = $this->resolveBinary('mysql');

        $command = sprintf(
            '%s --user=%s %s --host=%s --port=%s %s < %s',
            $mysql,
            escapeshellarg($config['username']),
            $config['password'] ? '--password=' . escapeshellarg($config['password']) : '',
            escapeshellarg($config['host']),
            escapeshellarg($config['port']),
            escapeshellarg($config['database']),
            escapeshellarg($filePath)
        );

        $process = Process::fromShellCommandline($command, null, $this->buildProcessEnv());
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorMessage = trim($process->getErrorOutput() . "\n" . $process->getOutput());
            Log::error('Database import failed', [
                'exit_code' => $process->getExitCode(),
                'output' => $errorMessage,
                'command' => $command,
            ]);
            throw new \Exception('Database import failed: ' . ($errorMessage ?: 'Unknown error (exit code ' . $process->getExitCode() . ').'));
        }

        Log::info('Database imported successfully', ['file' => $filePath]);
    }

    /**
     * Get the active database connection config.
     *
     * @return array{driver: string, host: string, port: string, database: string, username: string, password: string}
     * @throws \Exception
     */
    protected function getConnectionConfig(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'mysql') {
            throw new \Exception('Database export/import is only supported for MySQL/MariaDB.');
        }

        return [
            'driver' => $config['driver'],
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
        ];
    }

    /**
     * Build a complete environment for spawned MySQL binaries.
     *
     * Symfony Process builds its env from getenv()/$_ENV, but under some
     * Windows setups (e.g. the PHP built-in server) that snapshot is incomplete,
     * which breaks Winsock initialization in the child process and causes
     * mysqldump to fail with "Can't create TCP/IP socket (10106)".
     * Merging the essential system variables fixes it.
     *
     * @return array<string, string>
     */
    protected function buildProcessEnv(): array
    {
        $env = getenv() ?: [];

        $defaults = [
            'PATH' => $env['PATH'] ?? '',
            'SystemRoot' => $env['SystemRoot'] ?? 'C:\\Windows',
            'WINDIR' => $env['WINDIR'] ?? 'C:\\Windows',
            'SystemDrive' => $env['SystemDrive'] ?? 'C:',
            'COMSPEC' => $env['COMSPEC'] ?? 'C:\\Windows\\System32\\cmd.exe',
            'PATHEXT' => $env['PATHEXT'] ?? '.COM;.EXE;.BAT;.CMD;.VBS;.VBE;.JS;.JSE;.WSF;.WSH;.MSC',
            'TEMP' => $env['TEMP'] ?? sys_get_temp_dir(),
            'TMP' => $env['TMP'] ?? sys_get_temp_dir(),
        ];

        foreach ($defaults as $key => $value) {
            if (empty($env[$key])) {
                $env[$key] = $value;
            }
        }

        return array_filter($env, static fn ($value) => $value !== false);
    }

    /**
     * Resolve the full path to a MySQL binary (mysqldump / mysql).
     *
     * Priority: explicit config -> PATH -> Laragon (Windows).
     *
     * @param string $binary e.g. "mysqldump" or "mysql"
     * @return string
     * @throws \Exception
     */
    protected function resolveBinary(string $binary): string
    {
        $configured = config("database.binaries.{$binary}");
        if ($configured && is_file($configured)) {
            return $configured;
        }

        $fromPath = $this->findInPath($binary);
        if ($fromPath) {
            return $fromPath;
        }

        $laragonPath = $this->findLaragonBinary($binary);
        if ($laragonPath) {
            return $laragonPath;
        }

        throw new \Exception(ucfirst($binary) . " was not found. Set the path in config ('database.binaries.{$binary}') or add it to your PATH.");
    }

    /**
     * Locate a binary via the operating system PATH.
     */
    protected function findInPath(string $binary): ?string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $command = $isWindows ? "where {$binary} 2>NUL" : "which {$binary} 2>/dev/null";

        $output = [];
        $returnVar = 0;
        @exec($command, $output, $returnVar);

        if ($returnVar !== 0 || empty($output)) {
            return null;
        }

        $candidate = trim($output[0]);
        return $candidate !== '' && is_file($candidate) ? $candidate : null;
    }

    /**
     * Locate a MySQL binary inside a standard Laragon (Windows) installation.
     */
    protected function findLaragonBinary(string $binary): ?string
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return null;
        }

        $base = 'C:\\laragon\\bin\\mysql';
        if (!is_dir($base)) {
            return null;
        }

        foreach (glob($base . '/*') as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $candidate = $dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binary . '.exe';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}