<?php

namespace Randomized\LongRunning;

use DDTrace\SpanData;
use RandomizedTests\RandomExecutionPath;
use RandomizedTests\RandomExecutionPathConfiguration;
use RandomizedTests\SnippetsConfiguration;

$composerVendor = getenv('COMPOSER_VENDOR_DIR') ?: __DIR__ . '/../vendor';
require "$composerVendor/autoload.php";

// Setting up Datadog manual tracing
\DDTrace\trace_function('processMessage', function (SpanData $span, $args) {
    // Access method arguments and change resource name
    $span->resource =  'message:' . $args[0]->id;
    $span->meta['message.content'] = $args[0]->content;
    $span->service = \ddtrace_config_app_name();
});

\DDTrace\trace_method('ProcessingStage1', 'process', function (SpanData $span, $args) {
    $span->service = \ddtrace_config_app_name();
    // Resource name defaults to the fully qualified method name.
});

\DDTrace\trace_method('ProcessingStage2', 'process', function (SpanData $span, $args) {
    $span->service = \ddtrace_config_app_name();
    $span->resource = 'message:' . $args[0]->id;
});
// End of Datadog manual tracing

/** Represents a message to be received and processed */
class Message
{
    public $id;
    public $content;

    public function __construct($id, $content)
    {
        $this->id   = $id;
        $this->content = $content;
    }
}

/** One of possibly many processing stages, each of which should have a Span */
class ProcessingStage1
{
    /** @var RandomExecutionPath */
    private $randomizer;

    public function __construct(RandomExecutionPath $randomizer)
    {
        $this->randomizer = $randomizer;
    }

    public function process(Message $message)
    {
        $this->randomizer->randomPath();
    }
}

/** One of possibly many processing stages, each of which should have a Span */
class ProcessingStage2
{
    /** @var RandomExecutionPath */
    private $randomizer;

    public function __construct(RandomExecutionPath $randomizer)
    {
        $this->randomizer = $randomizer;
    }

    public function process(Message $message)
    {
        $this->randomizer->randomPath();
    }
}

/** In a real world application, this will read new messages from a source, for example a queue */
function waitForNewMessages()
{
    return [
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
        new Message($id = (time() + rand(1, 1000)), 'content of a message: ' . $id),
    ];
}

/** This function is the "unit of work", each execution of it will generate one single trace */
function processMessage(Message $m, array $processors)
{
    foreach ($processors as $processor) {
        try {
            $processor->process($m);
        } catch (Exception $exception) {
            // handle
        }
    }
}

// Reading command line options
$options = getopt('', ['seed:', 'repeat:', 'file:']);
$seed =  isset($options['seed']) ? intval($options['seed']) : rand();
$repetitions = isset($options['repeat']) ? intval($options['repeat']) : 1000;
$file = isset($options['file']) ? $options['file'] : 'memory.out';
echo "Using seed $seed to run $repetitions repetitions.\n";

// Initializing the randomizer
$snippetsConfiguration = (new SnippetsConfiguration())
    ->withHttpBinHost('httpbin')
    ->withElasticSearchHost('elasticsearch')
    ->withMysqlHost('mysql')
    ->withMysqlUser('test')
    ->withMysqlPassword('test')
    ->withMysqlDb('test')
    ->withRedisHost('redis')
    ->withMemcachedHost('memcached');
$randomizerConfiguration = new RandomExecutionPathConfiguration(
    $snippetsConfiguration,
    $seed,
    false, // because for exceptions/error handlers: "Execution will stop after the callback is called".
    false
);
$randomizer = new RandomExecutionPath($randomizerConfiguration);
set_error_handler([$randomizer, 'handleError']);
set_exception_handler([$randomizer, 'handleException']);

$processors = [new ProcessingStage1($randomizer), new ProcessingStage2($randomizer)];

/* Open the file before and then using fwrite, as the first time file_put_content is called it causes a step in
 * measured memory.
 */
$memoryLogHandle = fopen($file, 'a+');

// We use the first 5 cycles to warm up
for ($repetition = -4; $repetition <= $repetitions; $repetition++) {
    echo "Running repetition $repetition of $repetitions\n";
    // Dumping memory
    \gc_collect_cycles();
    $memory = \memory_get_usage();
    if ($repetition > 0) {
        \fwrite($memoryLogHandle, $memory . "\n");
    }
    foreach (waitForNewMessages() as $message) {
        processMessage($message, $processors);
    }
}

fclose($memoryLogHandle);
