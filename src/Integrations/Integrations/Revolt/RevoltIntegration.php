<?php

namespace DDTrace\Integrations\Revolt;

use DDTrace\HookData;
use DDTrace\RootSpanData;
use DDTrace\SpanData;
use DDTrace\SpanStack;
use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use function DDTrace\active_span;
use function DDTrace\active_stack;
use function DDTrace\close_span;
use function DDTrace\create_stack;
use function DDTrace\set_priority_sampling;
use function DDTrace\start_trace_span;
use function DDTrace\switch_stack;
use function DDTrace\try_drop_span;

/**
 * Revolt integration
 */
class RevoltIntegration extends Integration
{
    const NAME = 'revolt';

    public static \WeakMap $registeredHooks;
    public static array $watcherRegistrations = [];
    public static bool $dropOrphans = true;

    /**
     * @return int
     */
    public function init(): int
    {
        self::$registeredHooks = new \WeakMap;

        // immediates: only track stack?
        // rw/signal/timer: separate trace; dropped if no child spans ... retained if child span created on other stack?!
        // keep span open as long as transitive dependencies exist? weakmap notify on stack??
        // span links ... passthrough?

        // (Promise:) add span links on DriverSuspension resumption ?!

        $fiberHookEndClosure = function (HookData $hook) {
            if (isset($hook->data)) {
                unset(active_stack()->spanCreationObservers["revolt-event-loop-stack-switch"]);
                switch_stack($hook->data);
            }
        };

        $queuedClosures = [];
        \DDTrace\hook_method('Revolt\EventLoop', 'queue', [
            'prehook' => function ($revolt, $scope, $args) use (&$queuedClosures, $fiberHookEndClosure) {
                $closure = $args[0];
                $queuedClosures[] = [create_stack(), microtime(true)];
                switch_stack();
                if (!isset(RevoltIntegration::$registeredHooks[$closure])) {
                    RevoltIntegration::$registeredHooks[$closure] = true;
                    \DDTrace\install_hook($closure, function (HookData $hook) use (&$queuedClosures) {
                        if (!str_ends_with(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? "", 'EventLoop' . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'AbstractDriver.php')) {
                            return;
                        }
                        list($stack, $queueTime) = array_shift($queuedClosures);
                        RevoltIntegration::hookStackSwitch($hook, "queue", $stack, $queueTime);
                    }, $fiberHookEndClosure, \DDTrace\HOOK_INSTANCE);
                }
            }
        ]);


        \DDTrace\hook_method('Revolt\EventLoop', 'defer', [
            'posthook' => function ($revolt, $scope, $args, $retval) use ($fiberHookEndClosure) {
                $closure = $args[0];
                RevoltIntegration::$watcherRegistrations[$retval] = [false, create_stack(), microtime(true)];
                switch_stack();
                if (!isset(RevoltIntegration::$registeredHooks[$closure])) {
                    RevoltIntegration::$registeredHooks[$closure] = true;
                    \DDTrace\install_hook($closure, function (HookData $hook) {
                        if (!str_ends_with(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? "", 'EventLoop' . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'AbstractDriver.php')) {
                            return;
                        }
                        list(, $stack, $queueTime) = RevoltIntegration::$watcherRegistrations[$hook->args[0]];
                        RevoltIntegration::hookStackSwitch($hook, "defer", $stack, $queueTime);
                        unset(RevoltIntegration::$watcherRegistrations[$hook->args[0]]);
                    }, $fiberHookEndClosure, \DDTrace\HOOK_INSTANCE);
                }
            }
        ]);

        $fiberHookEndClosure = function (HookData $hook) {
            if (isset($hook->data)) {
                list($stack, $object) = $hook->data;
                $object->finishTime = microtime(true);
                switch_stack($stack);
            }
        };

        // Note: delay and defer callbacks, when disabled, and re-enabled have their timers reset to now() + $delay
        \DDTrace\hook_method('Revolt\EventLoop', 'delay', [
            'posthook' => function ($revolt, $scope, $args, $retval) use ($fiberHookEndClosure) {
                $delay = $args[0];
                $closure = $args[1];
                RevoltIntegration::$watcherRegistrations[$retval] = [true, active_span()?->getLink(), microtime(true), $delay];
                if (!isset(RevoltIntegration::$registeredHooks[$closure])) {
                    RevoltIntegration::$registeredHooks[$closure] = true;
                    \DDTrace\install_hook($closure, function (HookData $hook) {
                        if (!str_ends_with(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? "", 'EventLoop' . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'AbstractDriver.php')) {
                            return;
                        }
                        $span = RevoltIntegration::hookTraceEvent($hook, "delay");
                        list(, , , $delay) = RevoltIntegration::$watcherRegistrations[$hook->args[0]];
                        $span->meta["event-loop.interval"] = $delay;
                        $span->metrics["event-loop.invocation_delay"] -= $delay;
                        unset(RevoltIntegration::$watcherRegistrations[$hook->args[0]]);
                    }, $fiberHookEndClosure, \DDTrace\HOOK_INSTANCE);
                }
            }
        ]);
        \DDTrace\hook_method('Revolt\EventLoop', 'repeat', [
            'posthook' => function ($revolt, $scope, $args, $retval) use ($fiberHookEndClosure) {
                $delay = $args[0];
                $closure = $args[1];
                RevoltIntegration::$watcherRegistrations[$retval] = [true, active_span()?->getLink(), microtime(true), $delay];
                if (!isset(RevoltIntegration::$registeredHooks[$closure])) {
                    RevoltIntegration::$registeredHooks[$closure] = true;
                    \DDTrace\install_hook($closure, function (HookData $hook) {
                        if (!str_ends_with(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? "", 'EventLoop' . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'AbstractDriver.php')) {
                            return;
                        }
                        $span = RevoltIntegration::hookTraceEvent($hook, "repeat");
                        list(, , , $delay) = RevoltIntegration::$watcherRegistrations[$hook->args[0]];
                        $span->meta["event-loop.interval"] = $delay;
                        $span->metrics["event-loop.invocation_delay"] -= $delay;
                    }, $fiberHookEndClosure, \DDTrace\HOOK_INSTANCE);
                }
            }
        ]);

        foreach ([["stream", "onWritable"], ["stream", "onReadable"], ["signal", "onSignal"]] as list($type, $method)) {
            \DDTrace\hook_method('Revolt\EventLoop', $method, [
                'posthook' => function ($revolt, $scope, $args, $retval) use ($method, $type, $fiberHookEndClosure) {
                    $closure = $args[1];
                    RevoltIntegration::$watcherRegistrations[$retval] = [true, active_span()?->getLink(), microtime(true)];
                    if (!isset(RevoltIntegration::$registeredHooks[$closure])) {
                        RevoltIntegration::$registeredHooks[$closure] = true;
                        \DDTrace\install_hook($closure, function (HookData $hook) use ($type, $method) {
                            if (!str_ends_with(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? "", 'EventLoop' . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'AbstractDriver.php')) {
                                return;
                            }
                            $span = RevoltIntegration::hookTraceEvent($hook, $method);
                            $span->metrics["event-loop.$type"] = (int)$hook->args[1];
                        }, $fiberHookEndClosure, \DDTrace\HOOK_INSTANCE);
                    }
                }
            ]);
        }

        \DDTrace\hook_method('Revolt\EventLoop', 'cancel', function ($revolt, $scope, $args) {
            unset(RevoltIntegration::$watcherRegistrations[$args[0]]);
        });

        \DDTrace\hook_method('Revolt\EventLoop', 'disable', function ($revolt, $scope, $args) {
            unset(RevoltIntegration::$watcherRegistrations[$args[0]][1]);
        });

        // ensure the right stack is used
        \DDTrace\hook_method('Revolt\EventLoop', 'enable', function ($revolt, $scope, $args) {
            $watcher = $args[0];
            if (isset(RevoltIntegration::$watcherRegistrations[$watcher]) && !isset(RevoltIntegration::$watcherRegistrations[$watcher][1])) {
                if (RevoltIntegration::$watcherRegistrations[$watcher][0]) {
                    RevoltIntegration::$watcherRegistrations[$watcher][1] = create_stack();
                    switch_stack();
                } else {
                    RevoltIntegration::$watcherRegistrations[$watcher][1] = active_span()?->getLink();
                }
                RevoltIntegration::$watcherRegistrations[$watcher][2] = microtime(true);
            }
        });


        return Integration::LOADED;
    }

    public static function hookStackSwitch(HookData $hook, $method, $stack, $queueTime) {
        $now = microtime(true);
        switch_stack($stack);
        $hook->data = active_stack();
        $stack->spanCreationObservers["revolt-event-loop-stack-switch"] = function ($span) use ($method, $now, $queueTime) {
            $span->meta["event-loop.type"] = $method;
            $span->metrics["event-loop.invocation_delay"] = $now - $queueTime;
            return false;
        };
    }

    public static function hookTraceEvent(HookData $hook, $method)
    {
        $now = microtime(true);
        list(, $parentLink, $enableTime) = RevoltIntegration::$watcherRegistrations[$hook->args[0]];
        $stack = active_stack();

        $dummySpan = $hook->span();
        \DDTrace\try_drop_span($dummySpan);

        switch_stack(new SpanStack);
        $span = start_trace_span();

        // TODO: just get the default hook-span attributes
        $span->meta += $dummySpan->meta;
        $span->name = $dummySpan->name;

        if ($parentLink) {
            $span->links[] = $parentLink;
        }
        $childStack = create_stack();
        RevoltIntegration::$registeredHooks[$childStack] = $object = new class {
            public $finishTime;
            public $span;
            public $spanCreated = false;
            function __destruct()
            {
                $active = active_stack();
                switch_stack($this->span);
                if (RevoltIntegration::$dropOrphans && !$this->spanCreated && !$this->span->exception) {
                    set_priority_sampling(\DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT);
                }
                close_span($this->finishTime);
                switch_stack($active);
            }
        };
        $object->span = $span;
        $spanCreated = &$object->spanCreated;
        $childStack->spanCreationObservers[] = function (SpanData $span) use (&$spanCreated) {
            if ($spanCreated) {
                return false;
            }
            $span->onClose[] = function ($span) use (&$spanCreated) {
                if (!($span instanceof RootSpanData) || $span->samplingPriority >= \DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP) {
                    $spanCreated = true;
                }
            };
        };
        $hook->data = [$stack, $object];

        $span->meta["event-loop.type"] = $method;
        $span->metrics["event-loop.invocation_delay"] = $now - $enableTime;
        RevoltIntegration::$watcherRegistrations[$hook->args[0]][2] = $now;
        return $span;
    }
}
