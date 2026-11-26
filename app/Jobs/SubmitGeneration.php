<?php

namespace App\Jobs;

use App\Services\PixelForge\SubmitGenerationAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the first submission attempt after the generation row commits. It
 * gets a single try: the row stays due, so the recovery sweep owns every
 * later attempt.
 */
class SubmitGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(private readonly string $generationId) {}

    public function handle(SubmitGenerationAttempt $attempt): void
    {
        $attempt->run($this->generationId);
    }
}
