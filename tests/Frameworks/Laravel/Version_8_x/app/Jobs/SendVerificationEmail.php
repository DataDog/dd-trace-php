<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendVerificationEmail implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;
    public bool $triggerException;
    public array $backoff = [1, 2, 3, 4, 5];

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

        //sleep(2);
        logger('email sent');
    }

    public function failed(Throwable $e)
    {
        if ($e->getCode() == 999) { //
            $this->release();
        }
    }
}
