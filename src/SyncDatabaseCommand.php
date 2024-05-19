<?php

namespace Toyi\SyncDatabase;

use ByteUnits\Metric;
use Exception;
use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Symfony\Component\Process\Process;

class SyncDatabaseCommand extends Command
{
    use ConfirmableTrait;

    protected bool $delete_local_dump = true;
    protected array $default_database_config = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'toyi:sync-database
    {--keep-definers : Do not remove DEFINER clauses}
    {--no-migrations : Do not execute pending migrations.}
    {--tables-no-data= : A comma separated list of tables. Only their structure will be dumped (no data)}
    {--dump-file= : Directly import the database from this file}
    {--delete-local-dump : Delete the local dump after the import is completed}
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

        $this->delete_local_dump = $this->option('delete-local-dump');

        $dump_file = $this->option('dump-file') ? $this->providedDump() : $this->remoteDump();
        $this->default_database_config = Config::get('database.connections.' . DB::getDefaultConnection());

        $this->dropAllTables();

        $this->info("Importing...");

        exec('which pv', $output, $code);

        $has_pv = $code === 0;

        if (!$has_pv) {
            $this->warn("The pv binary has not been found. Install it to see the import progress bar.");
        }

        $import_cmd = [];

        if ($has_pv) {
            $import_cmd[] = 'pv';
            $import_cmd[] = '-n';
            $import_cmd[] = $dump_file;
            $import_cmd[] = '|';
        }

        $import_cmd[] = Config::get('sync-database.bin.mysql') ?: 'mysql';
        $import_cmd[] = '-h ' . $this->default_database_config['host'];
        if (array_key_exists('port', $this->default_database_config)) {
            $import_cmd[] = '-P ' . $this->default_database_config['port'];
        }
        $import_cmd[] = '-u ' . $this->default_database_config['username'];
        $import_cmd[] = '-p' . $this->default_database_config['password'];
        $import_cmd[] = $this->default_database_config['database'];

        if (!$has_pv) {
            $import_cmd[] = '<';
            $import_cmd[] = $dump_file;
        }

        $import_cmd[] = '2>&1';

        $bar = $this->output->createProgressBar(100);

        $process = new Process(['bash', '-c', implode(' ', $import_cmd)]);
        $process->setTimeout(null);

        $process->run(function ($type, $buffer) use ($bar) {
            $lines = explode("\n", $buffer);
            $lines = array_filter($lines, fn(string $line) => $line !== '');
            $buffer = $lines[0];

            if (str_starts_with($buffer, 'mysql: [Warning] Using a password')) {
                return;
            }

            if (!is_numeric($buffer)) {
                $this->warn($buffer);
                return;
            }

            $bar->setProgress((int)$buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error("There was an error during the import.");
            return Command::FAILURE;
        }

        if ($has_pv) {
            $bar->finish();
        }

        if ($this->delete_local_dump) {
            exec('rm ' . $dump_file, $output, $code);
            $this->info("Local dump deleted.");
        }

        if ($this->option('no-migrations') !== true) {
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

        if (substr($dump_file, -3) === '.gz') {
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
        $database_config = Config::get('sync-database.database');
        $ssh_config = Config::get('sync-database.ssh');

        if (!isset($ssh_config['timeout'])) {
            $ssh_config['timeout'] = 300;
        }

        if (!isset($database_config['max_allowed_packet'])) {
            $database_config['max_allowed_packet'] = '64M';
        }

        if ($database_config['host'] == '' || $database_config['port'] == '' || $database_config['user'] == '' || $database_config['password'] == '') {
            throw new Exception("Missing database configuration.");
        }

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

        $tables_no_data = $this->option('tables-no-data') ?: $database_config['tables_no_data'];
        $tables_no_data = str_replace(' ', '', $tables_no_data);
        $tables_no_data_e = array_filter(explode(',', $tables_no_data));
        $tables_no_data_e = array_filter($tables_no_data_e);

        $filename = 'toyi-sync-database-' . md5(uniqid(rand(), true)) . '.sql';
        $dump_file_remote = '/tmp/' . $filename;
        $dump_file_local = '/tmp/local-' . $filename;
        $dump_file_remote_gz = $dump_file_remote . '.gz';
        $dump_file_local_gz = $dump_file_local . '.gz';

        $dump_cmds = [];
        $dump_cmd_base = [];
        $dump_cmd_base[] = Config::get('sync-database.bin.mysqldump') ?: 'mysqldump';
        $dump_cmd_base[] = '--max_allowed_packet=' . $database_config['max_allowed_packet'];
        $dump_cmd_base[] = '--no-tablespaces';
        $dump_cmd_base[] = '--single-transaction';
        $dump_cmd_base[] = '-h ' . $database_config['host'];
        $dump_cmd_base[] = '-P ' . $database_config['port'];
        $dump_cmd_base[] = '-u ' . $database_config['user'];
        $dump_cmd_base[] = '-p' . $database_config['password'];

        $dump_cmd = $dump_cmd_base;

        foreach ($tables_no_data_e as $table_no_data) {
            $dump_cmd[] = '--ignore-table=' . $database_config['name'] . '.' . $table_no_data;
        }

        $dump_cmd[] = $database_config['name'];
        if ($this->option('keep-definers') !== true) {
            $dump_cmd[] = "| sed -e 's/DEFINER[ ]*=[ ]*[^*]*\*/\*/' ";
        }
        $dump_cmd[] = '> ' . $dump_file_remote;
        $dump_cmds[] = implode(' ', $dump_cmd);

        foreach ($tables_no_data_e as $table_no_data) {
            $dump_cmd = $dump_cmd_base;
            $dump_cmd[] = '--no-data';
            $dump_cmd[] = $database_config['name'];
            $dump_cmd[] = $table_no_data;
            $dump_cmd[] = '>> ' . $dump_file_remote;
            $dump_cmds[] = implode(' ', $dump_cmd);
        }

        $this->info("Dumping...");
        foreach ($dump_cmds as $dump_cmd) {
            $ssh_client->exec($dump_cmd);
        }

        $this->info("Compressing remote dump...");
        $ssh_client->exec('gzip ' . $dump_file_remote);

        $this->info("Downloading...");
        $size = $sftp_client->filesize($dump_file_remote_gz);
        $size_mb = str_replace('MB', '', Metric::bytes($size)->format('MB'));

        $this->info('Remote file ' . $dump_file_remote_gz . ' (' . $size_mb . 'MB) will be downloaded.');

        $bar = $this->getOutput()->createProgressBar((float)$size_mb);
        $bar->setOverwrite(true);

        $sftp_client->get($dump_file_remote_gz, $dump_file_local_gz, 0, -1, function ($size) use ($bar) {
            $size = str_replace(',', '.', $size);
            $size = str_replace('MB', '', Metric::bytes($size)->format('MB'));
            $bar->setProgress((float)$size);
        });

        $bar->finish();
        $this->getOutput()->newLine();

        $ssh_client->exec('rm ' . $dump_file_remote_gz);
        $this->info("Remote dump deleted.");

        exec("gzip -fd $dump_file_local_gz");
        $this->info("Dump extracted.");

        return $dump_file_local;
    }

    protected function dropAllTables(): void
    {
        $schema_builder = DB::connection()->getSchemaBuilder();
        if (method_exists($schema_builder, 'dropAllTables')) {
            $schema_builder->dropAllTables();
            return;
        }

        Schema::disableForeignKeyConstraints();
        foreach (DB::select('SHOW TABLES') as $table) {
            $table = array_values((array)$table)[0];
            $table = substr($table, strlen($this->default_database_config['prefix']), strlen($table));
            Schema::drop($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
