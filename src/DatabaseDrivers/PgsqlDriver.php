<?php namespace Toyi\SyncDatabase\DatabaseDrivers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class PgsqlDriver extends DatabaseDriverAbstract
{
    public function makeImportCmd(string $dump_file): string
    {
        $basename = Str::of($dump_file)->basename($dump_file);

        $cmd = [];
        $cmd[] = Config::get('sync-database.bin.pg_restore') ?: 'pg_restore';
        $cmd[] = '--no-owner';
        $cmd[] = '--dbname ' . $this->makeDbName(
                user: $this->default_database_config['username'],
                password: $this->default_database_config['password'],
                host: $this->default_database_config['host'],
                port: $this->default_database_config['port'],
                name: $this->default_database_config['database'],
            );
        $cmd[] = '<';
        $cmd[] = $dump_file;
        $cmd[] = '2>&1';

        return implode(' ', $cmd);
    }

    public function makeDumpCmd(string $dump_file_remote): string
    {
        $cmd = [];
        $cmd[] = Config::get('sync-database.bin.pg_dump') ?: 'pg_dump';
        $cmd[] = '-F c';
        $cmd[] = '--no-owner';
        $cmd[] = '--dbname ' . $this->makeDbName(
                user: $this->database_config['user'],
                password: $this->database_config['password'],
                host: $this->database_config['host'],
                port: $this->database_config['port'],
                name: $this->database_config['name'],
            );
        $cmd[] = '>';
        $cmd[] = $dump_file_remote;

        return implode(' ', $cmd);
    }

    private function makeDbName(
        string $user,
        string $password,
        string $host,
        string $port,
        string $name,
    ): string
    {
        $dbname = ['postgres://'];
        $dbname[] = $user;
        $dbname[] = ':';
        $dbname[] = $password;
        $dbname[] = '@';
        $dbname[] = $host;
        $dbname[] = ':';
        $dbname[] = $port;
        $dbname[] = '/';
        $dbname[] = $name;

        return implode('', $dbname);
    }
}
