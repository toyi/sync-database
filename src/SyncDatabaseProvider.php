<?php namespace Toyi\SyncDatabase;

use Illuminate\Support\ServiceProvider;

class SyncDatabaseProvider extends ServiceProvider
{
    public function register()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncDatabaseCommand::class
            ]);
        }

        $this->mergeConfigFrom(__DIR__ . '/../config/sync-database.php', 'sync-database');

        $this->publishes([
            __DIR__ . '/../config/sync-database.php' => $this->app->basePath() . '/config/sync-database.php',
        ]);
    }
}
