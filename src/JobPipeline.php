<?php

namespace Stancl\JobPipeline;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;

final class JobPipeline implements ShouldQueue
{
    /** @var bool */
    public static $shouldBeQueuedByDefault = false;

    /** @var callable[]|string[] */
    public $jobs;

    /** @var callable */
    public $send;

    /**
     * A value passed to the jobs. This is the return value of $send.
     *
     * @var array<mixed, mixed>
     */
    public $passable;

    /** @var bool */
    public $shouldBeQueued;

    /**
     * @param callable[]|string[] $jobs
     */
    public function __construct($jobs, callable $send = null, bool $shouldBeQueued = null)
    {
        $this->jobs = $jobs;
        $this->send = $send ?? function ($event) {
            // If no $send callback is set, we'll just pass the event through the jobs.
            return $event;
        };
        $this->shouldBeQueued = $shouldBeQueued ?? static::$shouldBeQueuedByDefault;
    }

    /** @param callable[]|string[] $jobs */
    public static function make(array $jobs): self
    {
        return new static($jobs);
    }

    public function send(callable $send): self
    {
        $this->send = $send;

        return $this;
    }

    public function shouldBeQueued(bool $shouldBeQueued): self
    {
        $this->shouldBeQueued = $shouldBeQueued;

        return $this;
    }

    public function handle(): void
    {
        foreach ($this->jobs as $job) {
            /** @var callable $jobHandle */
            $jobHandle = [new $job(...$this->passable), 'handle'];
            app()->call($jobHandle);
        }
    }

    /**
     * Generate a closure that can be used as a listener.
     */
    public function toListener(): Closure
    {
        return function (...$args) {
            $executable = $this->executable($args);

            if ($this->shouldBeQueued) {
                dispatch($executable);
            } else {
                dispatch_now($executable);
            }
        };
    }

    /**
     * Return a serializable version of the current object.
     *
     * @param callable[] $listenerArgs
     */
    public function executable($listenerArgs): self
    {
        $clone = clone $this;

        $passable = ($clone->send)(...$listenerArgs);
        $passable = is_array($passable) ? $passable : [$passable];

        $clone->passable = $passable;
        unset($clone->send);

        return $clone;
    }
}
