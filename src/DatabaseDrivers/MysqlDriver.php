<?php namespace Toyi\SyncDatabase\DatabaseDrivers;

use Illuminate\Support\Facades\Config;

class MysqlDriver extends DatabaseDriverAbstract
{
    public function makeImportCmd(string $dump_file): string
    {
        $cmd = [];
        $cmd[] = Config::get('sync-database.bin.mysql', 'mysql');
        $cmd[] = '-h ' . $this->default_database_config['host'];
        if (array_key_exists('port', $this->default_database_config)) {
            $cmd[] = '-P ' . $this->default_database_config['port'];
        }
        $cmd[] = '-u ' . $this->default_database_config['username'];
        $cmd[] = '-p' . $this->default_database_config['password'];
        $cmd[] = $this->default_database_config['database'];
        $cmd[] = '<';
        $cmd[] = $dump_file;
        $cmd[] = '2>&1';

        return implode(' ', $cmd);
    }

    public function makeDumpCmd(string $dump_file_remote): string
    {
        $this->dump_options['max_allowed_packet'] ??= '64M';
        $this->dump_options['keep_definers'] ??= false;

        $dump_cmd = [];
        $dump_cmd[] = Config::get('sync-database.bin.mysqldump') ?: 'mysqldump';
        $dump_cmd[] = '--max_allowed_packet=' . $this->database_config['max_allowed_packet'];
        $dump_cmd[] = '--no-tablespaces';
        $dump_cmd[] = '--single-transaction';
        $dump_cmd[] = '-h ' . $this->database_config['host'];
        $dump_cmd[] = '-P ' . $this->database_config['port'];
        $dump_cmd[] = '-u ' . $this->database_config['user'];
        $dump_cmd[] = '-p' . $this->database_config['password'];
        $dump_cmd[] = $this->database_config['name'];

        if ($this->dump_options['keep_definers']) {
            $dump_cmd[] = "| sed -e 's/DEFINER[ ]*=[ ]*[^*]*\*/\*/' ";
        }

        $dump_cmd[] = '> ' . $dump_file_remote;

        return implode(' ', $dump_cmd);
    }
}
