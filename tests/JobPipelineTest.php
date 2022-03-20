<?php

namespace Stancl\JobPipeline\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Spatie\Valuestore\Valuestore;
use Stancl\JobPipeline\JobPipeline;

class JobPipelineTest extends TestCase
{
    public $mockConsoleOutput = false;

    /** @var Valuestore */
    protected $valuestore;

    public function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'redis']);

        $this->valuestore = Valuestore::make(__DIR__ . '/tmp/jobpipelinetest.json')->flush();
    }

    /** @test */
    public function job_pipeline_can_listen_to_any_event()
    {
        Event::listen(TestEvent::class, JobPipeline::make([
            FooJob::class,
        ])->send(function () {
            return $this->valuestore;
        })->toListener());

        $this->assertFalse($this->valuestore->has('foo'));

        event(new TestEvent(new TestModel()));

        $this->assertSame('bar', $this->valuestore->get('foo'));
    }

    /** @test */
    public function job_pipeline_can_be_queued()
    {
        Queue::fake();

        Event::listen(TestEvent::class, JobPipeline::make([
            FooJob::class,
        ])->send(function () {
            return $this->valuestore;
        })->shouldBeQueued(true)->toListener());

        Queue::assertNothingPushed();

        event(new TestEvent(new TestModel()));
        $this->assertFalse($this->valuestore->has('foo'));

        Queue::pushed(JobPipeline::class, function (JobPipeline $pipeline) {
            $this->assertSame([FooJob::class], $pipeline->jobs);
        });
    }

    /** @test */
    public function job_pipelines_run_when_queued()
    {
        Event::listen(TestEvent::class, JobPipeline::make([
            FooJob::class,
        ])->send(function () {
            return $this->valuestore;
        })->shouldBeQueued(true)->toListener());

        $this->assertFalse($this->valuestore->has('foo'));
        event(new TestEvent(new TestModel()));
        $this->artisan('queue:work --once');

        sleep(1);

        $this->assertSame('bar', $this->valuestore->get('foo'));
    }

    /** @test */
    public function job_pipeline_executes_jobs_and_passes_the_object_sequentially()
    {
        Event::listen(TestEvent::class, JobPipeline::make([
            FirstJob::class,
            SecondJob::class,
        ])->send(function (TestEvent $event) {
            return [$event->testModel, $this->valuestore];
        })->toListener());

        $this->assertFalse($this->valuestore->has('foo'));

        event(new TestEvent(new TestModel()));

        $this->assertSame('first job changed property', $this->valuestore->get('foo'));
    }

    /** @test */
    public function send_can_return_multiple_arguments()
    {
        Event::listen(TestEvent::class, JobPipeline::make([
            JobWithMultipleArguments::class
        ])->send(function () {
            return ['a', 'b'];
        })->toListener());

        $this->assertFalse(app()->bound('test_args'));

        event(new TestEvent(new TestModel()));

        $this->assertSame(['a', 'b'], app('test_args'));
    }

    /** @test */
    public function the_pipeline_can_be_canceled_by_returning_false_from_any_job()
    {
        Event::listen(TestEvent::class, JobPipeline::make([
            FalseJob::class,
            FooJob::class,
        ])->send(function () {
            return $this->valuestore;
        })->shouldBeQueued(true)->toListener());

        event(new TestEvent(new TestModel()));
        $this->artisan('queue:work --once');

        sleep(1);

        $this->assertTrue($this->valuestore->get('false_job_executed'));

        // Foo job is not excuted
        $this->assertFalse($this->valuestore->has('foo'));
    }
    
    /** @test */
    public function the_pipeline_can_execute_failed_method_on_job()
    {
        Event::listen(TestEvent::class, JobPipeline::make([
            ExceptionJob::class,
        ])->send(function () {
            return $this->valuestore;
        })->toListener());

        event(new TestEvent(new TestModel()));

        sleep(1);

        $this->assertEquals($this->valuestore->get('exeception'), 'pipeline exception');
    }

    /** @test */
    public function closures_can_be_used_as_jobs()
    {
        $passes = false;

        Event::listen(TestEvent::class, JobPipeline::make([
            function (TestModel $model) use (&$passes) {
                $passes = $model instanceof TestModel;
            }
        ])->send(function (TestEvent $event) {
            return $event->testModel;
        })->toListener());

        event(new TestEvent(new TestModel()));

        $this->assertTrue($passes);
    }
}

class FooJob
{
    protected $valuestore;

    public function __construct(Valuestore $valuestore)
    {
        $this->valuestore = $valuestore;
    }

    public function handle()
    {
        $this->valuestore->put('foo', 'bar');
    }
};

class TestModel extends Model
{

}

class TestEvent
{
    /** @var TestModel $testModel */
    public $testModel;

    public function __construct(TestModel $testModel)
    {
        $this->testModel = $testModel;
    }
}

class FirstJob
{
    public $testModel;

    public function __construct(TestModel $testModel)
    {
        $this->testModel = $testModel;
    }

    public function handle()
    {
        $this->testModel->foo = 'first job changed property';
    }
}

class SecondJob
{
    public $testModel;

    protected $valuestore;

    public function __construct(TestModel $testModel, Valuestore $valuestore)
    {
        $this->testModel = $testModel;
        $this->valuestore = $valuestore;
    }

    public function handle()
    {
        $this->valuestore->put('foo', $this->testModel->foo);
    }
}

class JobWithMultipleArguments
{
    protected $first;
    protected $second;

    public function __construct($first, $second)
    {
        $this->first = $first;
        $this->second = $second;
    }

    public function handle()
    {
        // we dont queue this job so no need to use valuestore here
        app()->instance('test_args', [$this->first, $this->second]);
    }
}

class FalseJob
{
    protected $valuestore;

    public function __construct(Valuestore $valuestore)
    {
        $this->valuestore = $valuestore;
    }

    public function handle()
    {
        $this->valuestore->put('false_job_executed', true);

        return false;
    }
}

class ExceptionJob
{
    protected $valuestore;

    public function __construct(Valuestore $valuestore)
    {
        $this->valuestore = $valuestore;
    }

    public function handle()
    {
        throw new \Exception('pipeline exception', 1);
    }

    public function failed(\Throwable $e)
    {
        $this->valuestore->put('exeception', $e->getMessage());
    }
}
