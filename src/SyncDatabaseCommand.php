<?php

namespace Toyi\SyncDatabase;

use ByteUnits\Metric;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

class SyncDatabaseCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'toyi:sync-database 
    {--keep-definers : Do not remove DEFINER clauses}
    {--no-migrations : Do not execute pending migrations.}
    {--tables-no-data= : A comma separated list of tables. Only their structure will be dumped (no data)}
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

        $tables_no_data = $this->option('tables-no-data') ?: '';
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
            $this->info($dump_cmd);
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

        exec("gzip -d $dump_file_local_gz");
        $this->info("Local dump extracted.");

        DB::connection()->getSchemaBuilder()->dropAllTables();
        $this->info("Importing...");
        $default_config = Config::get('database.connections.' . DB::getDefaultConnection());
        $import_cmd = [];
        $import_cmd[] = 'mysql';
        $import_cmd[] = '-h ' . $default_config['host'];
        $import_cmd[] = '-P ' . $default_config['port'];
        $import_cmd[] = '-u ' . $default_config['username'];
        $import_cmd[] = '-p' . $default_config['password'];
        $import_cmd[] = $default_config['database'];
        $import_cmd[] = ' < ' . $dump_file_local;
        $import_cmd = implode(' ', $import_cmd);
        exec($import_cmd);
        exec('rm ' . $dump_file_local, $output, $code);


        $this->info("Local dump deleted.");

        if ($this->option('no-migrations') !== true) {
            $this->call('migrate', [
                '--step' => true
            ]);
        }

        return 0;
    }
}
