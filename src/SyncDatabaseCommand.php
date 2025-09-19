<?php

namespace Toyi\SyncDatabase;

use ByteUnits\Metric;
use Exception;
use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Symfony\Component\Process\Process;
use Toyi\SyncDatabase\DatabaseDrivers\DatabaseDriverAbstract;
use Toyi\SyncDatabase\DatabaseDrivers\MysqlDriver;
use Toyi\SyncDatabase\DatabaseDrivers\PgsqlDriver;

class SyncDatabaseCommand extends Command
{
    use ConfirmableTrait;

    protected bool $delete_local_dump = true;
    protected DatabaseDriverAbstract|null $driver = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'toyi:sync-database
    {--no-migrations : Do not execute pending migrations.}
    {--dump-file= : Directly import the database from this file}
    {--delete-local-dump : Delete the local dump after the import is completed}
    {--connection= : Connection to import the database to}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync database';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        if ($this->confirmToProceed() === false) {
            return 1;
        }

        $default_connection = Config::get('database.default');
        $connection = $this->option('connection') ?: $default_connection;
        $no_migrations = $this->option('no-migrations') || $connection !== $default_connection;
        $driver_name = Config::get("database.connections.{$connection}.driver");

        switch ($driver_name) {
            case 'mariadb':
            case 'mysql':
                $this->driver = app(MysqlDriver::class, ['connection' => $this->option('connection')]);
                break;
            case 'pgsql':
                $this->driver = app(PgsqlDriver::class, ['connection' => $this->option('connection')]);
                break;
        }

        if(!$this->driver){
            throw new Exception("Driver \"$driver_name\" not found.");
        }

        $this->delete_local_dump = $this->option('delete-local-dump');

        $dump_file = $this->option('dump-file') ? $this->providedDump() : $this->remoteDump();

        $this->info("Dropping local database...");
        Artisan::call('db:wipe', ['--quiet' => true, '--force' => true, '--database' => $connection]);;

        $this->info("Importing...");

        $import_cmd = $this->driver->makeImportCmd($dump_file);

        $process = new Process(['sh', '-c', $import_cmd]);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error("There was an error during the import.");
            $this->error($process->getErrorOutput());
            return Command::FAILURE;
        }

        if ($this->delete_local_dump) {
            exec('rm ' . $dump_file, $output, $code);
            $this->info("Local dump deleted.");
        }

        if ($no_migrations !== true) {
            $this->call('migrate', [
                '--step' => true
            ]);
        }

        if (Config::get('sync-database.post_scripts.enabled', false)) {
            $post_scripts = (array)Config::get('sync-database.post_scripts.scripts', []);

            foreach ($post_scripts as $script) {
                if (!class_exists($script)) {
                    $this->error("Post script $script not found.");
                    continue;
                }

                $this->info("Executing post script $script");
                Container::getInstance()->make($script)();
            }
        }

        return 0;
    }

    /**
     * @throws Exception
     */
    protected function providedDump(): string
    {
        $this->delete_local_dump = $this->option('delete-local-dump', false);

        $dump_file = $this->option('dump-file');

        if (file_exists($dump_file) === false) {
            throw new Exception("File $dump_file not found.");
        }

        if (str_ends_with($dump_file, '.gz')) {
            exec("gzip -fd $dump_file");
            $dump_file = substr($dump_file, 0, strlen($dump_file) - 3);
        }

        return $dump_file;
    }

    /**
     * @throws Exception
     */
    protected function remoteDump(): string
    {
        $ssh_config = Config::get('sync-database.ssh');

        $ssh_config['timeout'] ??= 300;

        if ($ssh_config['host'] == '' || $ssh_config['port'] == '' || $ssh_config['user'] == '' || ($ssh_config['password'] == '' && $ssh_config['key'] == '')) {
            throw new Exception("Missing ssh configuration.");
        }

        if ($ssh_config['key'] != '' && file_exists($ssh_config['key']) === false) {
            throw new Exception("SSH Key not found.");
        }

        $key = PublicKeyLoader::load(file_get_contents($ssh_config['key']));
        $ssh_client = new SSH2($ssh_config['host'], $ssh_config['port'], $ssh_config['timeout']);
        $sftp_client = new SFTP($ssh_config['host'], $ssh_config['port'], $ssh_config['timeout']);

        $ssh_client->login($ssh_config['user'], $key);
        $sftp_client->login($ssh_config['user'], $key);

        if ($ssh_client->isConnected() === false || $ssh_client->isAuthenticated() === false) {
            throw new Exception("SSH connection cannot be established.");
        }

        if ($sftp_client->isConnected() === false || $sftp_client->isAuthenticated() === false) {
            throw new Exception("SFTP connection cannot be established.");
        }

        $database_config = Config::get('sync-database.database');

        if ($database_config['host'] == '' || $database_config['port'] == '' || $database_config['user'] == '' || $database_config['password'] == '') {
            throw new Exception("Missing database configuration.");
        }

        $filename = 'toyi-sync-database-' . md5(uniqid(rand(), true)) . '.sql';
        $dump_file_remote = '/tmp/' . $filename;
        $dump_file_local = '/tmp/local-' . $filename;
        $dump_file_remote_gz = $dump_file_remote . '.gz';
        $dump_file_local_gz = $dump_file_local . '.gz';

        $dump_cmd = $this->driver->makeDumpCmd($dump_file_remote);

        $this->info("Dumping...");
        $ssh_client->exec($dump_cmd);

        $this->info("Compressing...");
        $ssh_client->exec('gzip ' . $dump_file_remote);

        $size = $sftp_client->filesize($dump_file_remote_gz);
        $size_mb = str_replace('MB', '', Metric::bytes($size)->format('MB'));
        $this->info('Downloading (' . $size_mb . 'MB)...');
        $sftp_client->get($dump_file_remote_gz, $dump_file_local_gz);

        $ssh_client->exec('rm ' . $dump_file_remote_gz);
        $this->info("Remote dump deleted.");

        exec("gzip -fd $dump_file_local_gz");
        $this->info("Dump extracted.");

        if (Config::get('sync-database.post_dump_scripts.enabled', false)) {
            $post_scripts = (array)Config::get('sync-database.post_dump_scripts.scripts', []);

            foreach ($post_scripts as $script) {
                if (!class_exists($script)) {
                    $this->error("Post dump script $script not found.");
                    continue;
                }

                $this->info("Executing post dump script $script...");
                Container::getInstance()->make($script)($dump_file_local);
            }
        }

        return $dump_file_local;
    }
}
