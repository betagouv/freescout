<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SchedulerDaemonQueue extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'schedule:daemonqueue {--sleep=60}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Call the scheduler every minute.';

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle()
  {
      while (true) {
          $this->line('Calling Daemon scheduler');
          $this->call('queue:work', [
            '--queue' => 'emails,default',
            '--once' => true,
            '--tries' => '1'
          ]);
          sleep($this->option('sleep'));
      }
  }
}
