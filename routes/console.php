<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:backup', function () {
    abort_unless(config('database.default') === 'mysql', 422, 'La sauvegarde automatique attend une connexion MySQL.');

    $database = config('database.connections.mysql');
    $directory = storage_path('app/backups');
    File::ensureDirectoryExists($directory, 0750, true);
    $filename = sprintf('%s/multishop-%s.sql', $directory, now()->format('Y-m-d_H-i-s'));

    $process = new Process([
        'mysqldump',
        '--single-transaction',
        '--quick',
        '--host='.$database['host'],
        '--port='.$database['port'],
        '--user='.$database['username'],
        $database['database'],
    ], null, ['MYSQL_PWD' => (string) $database['password']]);
    $process->setTimeout(300);
    $process->run();

    if (! $process->isSuccessful()) {
        $this->error('Échec de la sauvegarde MySQL : '.$process->getErrorOutput());
        return self::FAILURE;
    }

    File::put($filename, $process->getOutput());
    chmod($filename, 0640);
    $this->info('Sauvegarde créée : '.$filename);
    return self::SUCCESS;
})->purpose('Sauvegarder la base MySQL dans storage/app/backups');

Schedule::command('db:backup')
    ->dailyAt('02:00')
    ->name('backup-mysql-database')
    ->withoutOverlapping();
