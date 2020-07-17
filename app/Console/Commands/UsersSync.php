<?php

namespace App\Console\Commands;

use App\Services\UserSyncService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class UsersSync extends Command
{
    protected $signature = 'users:sync';
    protected $description = 'Sync users';

    protected UserSyncService $syncService;

    public function __construct(UserSyncService $syncService)
    {
        $this->syncService = $syncService;
        parent::__construct();
    }

    public function handle(): void
    {
        // todo: get lock, before processing file for prevent overlapping if we have many backend
        // todo: instances and ->withoutOverlapping() will not help
        $start = microtime(true);
        $this->info('processing file...');

        try {
            // todo: move filename to config
            $filePath = Storage::disk('local')->path('convertcsv(1).csv');

            if (!$this->syncService->init($filePath)) {
                foreach ($this->syncService->getErrors() as $error) {
                    $this->error($error);
                }
                throw new Exception('File cannot be parsed');
            }

            $this->syncService->process();
        } catch (Exception $exception) {
            $this->error('Something get wrong:');
            $this->error($exception->getMessage());

            return;
        } finally {
            $this->syncService->clean();
        }

        $this->info('finished in ' . round(microtime(true) - $start, 2) . ' sec');
    }
}
