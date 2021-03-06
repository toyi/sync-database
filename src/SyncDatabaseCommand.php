<?php

namespace Toyi\SyncDatabase;

use ByteUnits\Metric;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

class SyncDatabaseCommand extends Command
{
    use ConfirmableTrait;

    protected $delete_local_dump = true;
    protected $default_database_config = [];

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
    public function handle()
    {
        if ($this->confirmToProceed() === false) {
            return 1;
        }

        $this->delete_local_dump = $this->option('delete-local-dump', true);

        $dump_file = $this->option('dump-file') ? $this->providedDump() : $this->remoteDump();
        $this->default_database_config = Config::get('database.connections.' . DB::getDefaultConnection());

        $this->dropAllTables();

        $this->info("Importing...");

        $import_cmd = [];
        $import_cmd[] = 'mysql';
        $import_cmd[] = '-h ' . $this->default_database_config['host'];
        if (array_key_exists('port', $this->default_database_config)) {
            $import_cmd[] = '-P ' . $this->default_database_config['port'];
        }
        $import_cmd[] = '-u ' . $this->default_database_config['username'];
        $import_cmd[] = '-p' . $this->default_database_config['password'];
        $import_cmd[] = $this->default_database_config['database'];
        $import_cmd[] = ' < ' . $dump_file;
        $import_cmd = implode(' ', $import_cmd);
        exec($import_cmd);

        if ($this->delete_local_dump) {
            exec('rm ' . $dump_file, $output, $code);
            $this->info("Local dump deleted.");
        }

        if ($this->option('no-migrations') !== true) {
            $this->call('migrate', [
                '--step' => true
            ]);
        }

        return 0;
    }

    protected function providedDump()
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

    protected function remoteDump()
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
            $this->error("Missing database configuration.");
            return 1;
        }

        if ($ssh_config['host'] == '' || $ssh_config['port'] == '' || $ssh_config['user'] == '' || ($ssh_config['password'] == '' && $ssh_config['key'] == '')) {
            $this->error("Missing ssh configuration.");
            return 1;
        }

        if ($ssh_config['key'] != '' && file_exists($ssh_config['key']) === false) {
            $this->error("SSH Key not found.");
            return 1;
        }

        $key = PublicKeyLoader::load(file_get_contents($ssh_config['key']));
        $ssh_client = new SSH2($ssh_config['host'], $ssh_config['port'], $ssh_config['timeout']);
        $sftp_client = new SFTP($ssh_config['host'], $ssh_config['port'], $ssh_config['timeout']);

        $ssh_client->login($ssh_config['user'], $key);
        $sftp_client->login($ssh_config['user'], $key);

        if ($ssh_client->isConnected() === false || $ssh_client->isAuthenticated() === false) {
            $this->error("SSH connection cannot be established.");
            return 1;
        }

        if ($sftp_client->isConnected() === false || $sftp_client->isAuthenticated() === false) {
            $this->error("SFTP connection cannot be established.");
            return 1;
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
        $dump_cmd_base[] = 'mysqldump';
        $dump_cmd_base[] = '--max_allowed_packet=' . $database_config['max_allowed_packet'];
        $dump_cmd_base[] = '--no-tablespaces';
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

    protected function dropAllTables()
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
