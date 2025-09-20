<?php namespace Toyi\SyncDatabase\DatabaseDrivers;

use Illuminate\Support\Facades\Config;

class MysqlDriver extends DatabaseDriverAbstract
{
    public function makeImportCmd(string $dump_file): string
    {
        $this->dump_options['max_allowed_packet'] ??= '256M';
        $mysql_bin = Config::get('sync-database.bin.mysql') ?: 'mysql';

        $mysql_client_version = strtolower(shell_exec("$mysql_bin --version"));

        $cmd = [];
        $cmd[] = $mysql_bin;

        if(str_contains($mysql_client_version, 'mariadb')){
            $cmd[] = '--ssl-verify-server-cert=off';
        }else if(str_contains($mysql_client_version, 'mysql')){
            $cmd[] = '--ssl-mode=DISABLED';
        }

        $cmd[] = '-h ' . $this->default_database_config['host'];
        if (array_key_exists('port', $this->default_database_config)) {
            $cmd[] = '-P ' . $this->default_database_config['port'];
        }
        $cmd[] = '--max_allowed_packet=' . $this->dump_options['max_allowed_packet'];
        $cmd[] = '-u ' . $this->default_database_config['username'];
        $cmd[] = '-p' . $this->default_database_config['password'];
        $cmd[] = $this->default_database_config['database'];
        $cmd[] = '<';
        $cmd[] = $dump_file;

        return implode(' ', $cmd);
    }

    public function makeDumpCmd(string $dump_file_remote): string
    {
        $this->dump_options['max_allowed_packet'] ??= '256M';
        $this->dump_options['remove_database_qualifier'] ??= null;

        $dump_cmd = [];
        $dump_cmd[] = Config::get('sync-database.bin.mysqldump') ?: 'mysqldump';
        $dump_cmd[] = '--max_allowed_packet=' . $this->dump_options['max_allowed_packet'];
        $dump_cmd[] = '--no-tablespaces';
        $dump_cmd[] = '--single-transaction';
        $dump_cmd[] = '-h ' . $this->database_config['host'];
        $dump_cmd[] = '-P ' . $this->database_config['port'];
        $dump_cmd[] = '-u ' . $this->database_config['user'];
        $dump_cmd[] = '-p' . $this->database_config['password'];
        $dump_cmd[] = $this->database_config['name'];

        if ($qualifier = $this->dump_options['remove_database_qualifier']) {
            $dump_cmd[] = "| sed -e 's/`$qualifier`\.//g' ";
        }

        $dump_cmd[] = '> ' . $dump_file_remote;

        return implode(' ', $dump_cmd);
    }
}
