<?php namespace Toyi\SyncDatabase;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
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

    public function boot()
    {
        $this->app->booted(function () {
            if (Config::get('sync-database.auto.enabled')) {
                $this->app->make(Schedule::class)->command(SyncDatabaseCommand::class)->dailyAt(Config::get('sync-database.auto.at'));
            }
        });
    }
}
