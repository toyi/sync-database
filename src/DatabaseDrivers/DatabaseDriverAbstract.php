<?php namespace Toyi\SyncDatabase\DatabaseDrivers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

abstract class DatabaseDriverAbstract
{
    protected array $default_database_config;

    public function __construct()
    {
        $driver_name = DB::getDefaultConnection();
        $this->default_database_config = Config::get("database.connections.$driver_name");
        $this->database_config = Config::get('sync-database.database');
        $this->dump_options = Config::get("sync-database.dump_options.$driver_name");
    }

    abstract public function makeImportCmd(string $dump_file): string;
    abstract public function makeDumpCmd(string $dump_file_remote): string;
}
