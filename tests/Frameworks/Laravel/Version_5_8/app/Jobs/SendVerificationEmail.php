<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendVerificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout;
    public $tries;
    public $triggerException;
    public $backoff;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $timeout = 42, bool $triggerException = false, int $tries = 1)
    {
        $this->timeout = $timeout;
        $this->triggerException = $triggerException;
        $this->tries = $tries;
        $this->backoff = [1, 2, 3, 4, 5];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->triggerException) {
            throw new \Exception('Triggered Exception');
        }

        logger('email sent');
    }
}
