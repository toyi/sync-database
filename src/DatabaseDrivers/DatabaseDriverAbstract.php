<?php namespace Toyi\SyncDatabase\DatabaseDrivers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

abstract class DatabaseDriverAbstract
{
    protected array $default_database_config;

    public function __construct(string $connection = null)
    {
        $connection_name = $connection ?: DB::getDefaultConnection();
        $driver = Config::get("database.connections.$connection_name.driver");
        $this->default_database_config = Config::get("database.connections.$connection_name");
        $this->database_config = Config::get('sync-database.database');
        $this->dump_options = Config::get("sync-database.dump_options.$driver");
    }

    abstract public function makeImportCmd(string $dump_file): string;
    abstract public function makeDumpCmd(string $dump_file_remote): string;
}
