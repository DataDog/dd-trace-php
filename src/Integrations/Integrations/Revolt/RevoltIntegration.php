<?php

namespace DDTrace\Integrations\Revolt;

use DDTrace\HookData;
use DDTrace\Tag;
use DDTrace\Integrations\Integration;

/**
 * Revolt integration
 */
class RevoltIntegration extends Integration
{
    const NAME = 'revolt';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    /**
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;
        $registeredHooks = new \WeakMap;
        $fiberHookSpan = new \SplObjectStorage;
        $watcherRegistrations = [];

        /* Cover:
            // StreamReader ctor does: EventLoop::disable(EventLoop::onReadable($stream, ...))
            $reader = new StreamReader($stream);
            // read() does: EventLoop::enable(...), until data is read
            while ($reader->read()) {
                // ...
            }
            // must reconstruct to: read() -> eventloop onReadable callback (assuming read() is traced)
        */

        $activeTick = null; // TODO span-link to all executed closures
        \DDTrace\trace_method('Revolt\EventLoop\Internal\AbstractDriver', 'tick', [
            'prehook' => function ($span) use ($registeredHooks, &$activeTick) {
                $span->name = "EventLoop.tick";
                $span->meta[Tag::COMPONENT] = RevoltIntegration::NAME;
                $activeTick = $span;
            }
        ]);

        \DDTrace\install_hook('Revolt\EventLoop\Internal\AbstractDriver::invokeCallbacks', function (HookData $hook) {
            $hook->data = microtime(true);
        }, function (HookData $hook) use (&$activeTick) {
            $activeTick->metrics["event-loop.active"] = ($activeTick->metrics["event-loop.active"] ?? 0) + (microtime(true) - $hook->data);
        });

        \DDTrace\install_hook('Fiber::suspend', function (HookData $hook) use ($fiberHookSpan) {
            if ($span = $fiberHookSpan[\Fiber::getCurrent()] ?? null) {
                $hook->data = [microtime(true), $span];
                $span->metrics["event-loop.suspensions"] = ($span->metrics["event-loop.suspensions"] ?? 0) + 1;
            }
        }, function (HookData $hook) {
            if (list($start, $span) = $hook->data) {
                $span->metrics["event-loop.suspended_time"] = ($span->metrics["event-loop.suspended_time"] ?? 0) + (microtime(true) - $start);
            }
        });

        // Driver tick hook, log actual execution time,
        // log suspension count, log total execution time of fibers in real time active
        // create hook per invocation
        // ensure meaningful name for anon closures?!
        // log stream id and signal for streams
        // add DDTrace\install_trace_hook ...
        // trace watcher creations and cancellations, configurable, default off.
        // handle fiber abortions?!
        // clever trace_id management? if no parent span, then create new trace ids for recvs on server sockets (configurable!)?! SPAAAAN LIIINKS
        // propagate trace_id of queueing task
        // suspension trace mode with values, configurable, default off.
        // Do a switch stack, if the active span of the queueing stack is still the same as when the event was registered on the loop

        $fiberHookEndClosure = function (HookData $hook) use ($fiberHookSpan) {
            $span = $hook->span();
            $span->metrics["event-loop.active_time"] = $span->getDuration() / 1e9 - ($span->metrics["event-loop.suspended_time"] ?? 0);
            unset($fiberHookSpan[\Fiber::getCurrent()]);
        };

        $queuedClosures = [];
        \DDTrace\hook_method('Revolt\EventLoop', 'queue', [
            'posthook' => function ($revolt, $scope, $args) use ($registeredHooks, &$queuedClosures, $fiberHookSpan, $fiberHookEndClosure) {
                $closure = $args[0];
                $queuedClosures[] = [\DDTrace\create_stack(), microtime(true)];
                \DDTrace\switch_stack();
                if (!isset($registeredHooks[$closure])) {
                    $registeredHooks[$closure] = true;
                    \DDTrace\install_hook($closure, function (HookData $hook) use (&$queuedClosures, $fiberHookSpan) {
                        $now = microtime(true);
                        list($stack, $queueTime) = array_shift($queuedClosures);
                        $fiberHookSpan[\Fiber::getCurrent()] = $span = $hook->span($stack);
                        $span->meta["event-loop.type"] = "queue";
                        $span->metrics["event-loop.invocation_delay"] = $now - $queueTime;
                    }, $fiberHookEndClosure);
                }
            }
        ]);


        \DDTrace\hook_method('Revolt\EventLoop', 'defer', [
            'posthook' => function ($revolt, $scope, $args, $retval) use ($registeredHooks, &$watcherRegistrations, $fiberHookSpan, $fiberHookEndClosure) {
                $closure = $args[0];
                $watcherRegistrations[$retval] = [\DDTrace\create_stack(), microtime(true)];
                \DDTrace\switch_stack();
                if (!isset($registeredHooks[$closure])) {
                    $registeredHooks[$closure] = true;
                    \DDTrace\install_hook($closure, function (HookData $hook) use (&$watcherRegistrations, $fiberHookSpan) {
                        $now = microtime(true);
                        list($stack, $queueTime) = $watcherRegistrations[$hook->args[0]];
                        $fiberHookSpan[\Fiber::getCurrent()] = $span = $hook->span($stack);
                        $span->meta["event-loop.type"] = "defer";
                        $span->metrics["event-loop.invocation_delay"] = $now - $queueTime;
                        unset($watcherRegistrations[$hook->args[0]]);
                    }, $fiberHookEndClosure);
                }
            }
        ]);

        // Note: delay and defer callbacks, when disabled, and re-enabled have their timers reset to now() + $delay
        \DDTrace\hook_method('Revolt\EventLoop', 'delay', [
            'posthook' => function ($revolt, $scope, $args, $retval) use ($registeredHooks, &$watcherRegistrations, $fiberHookSpan, $fiberHookEndClosure) {
                $delay = $args[0];
                $closure = $args[1];
                $watcherRegistrations[$retval] = [\DDTrace\create_stack(), microtime(true), $delay];
                \DDTrace\switch_stack();
                if (!isset($registeredHooks[$closure])) {
                    $registeredHooks[$closure] = true;
                    \DDTrace\install_hook($closure, function (HookData $hook) use (&$watcherRegistrations, $fiberHookSpan) {
                        $now = microtime(true);
                        list($stack, $enableTime, $delay) = $watcherRegistrations[$hook->args[0]];
                        \DDTrace\switch_stack($stack);
                        $fiberHookSpan[\Fiber::getCurrent()] = $span = $hook->span($stack);
                        $span->meta["event-loop.type"] = "delay";
                        $span->meta["event-loop.delay"] = $delay;
                        $span->metrics["event-loop.invocation_delay"] = $now - $enableTime - $delay;
                        unset($watcherRegistrations[$hook->args[0]]);
                    }, $fiberHookEndClosure);
                }
            }
        ]);
        \DDTrace\hook_method('Revolt\EventLoop', 'repeat', [
            'posthook' => function ($revolt, $scope, $args, $retval) use ($registeredHooks, &$watcherRegistrations, $fiberHookSpan, $fiberHookEndClosure) {
                $delay = $args[0];
                $closure = $args[1];
                $watcherRegistrations[$retval] = [\DDTrace\create_stack(), microtime(true), $delay];
                \DDTrace\switch_stack();
                if (!isset($registeredHooks[$closure])) {
                    $registeredHooks[$closure] = true;
                    \DDTrace\install_hook($closure, function (HookData $hook) use (&$watcherRegistrations, $fiberHookSpan) {
                        $now = microtime(true);
                        list($stack, $enableTime, $delay) = $watcherRegistrations[$hook->args[0]];
                        $fiberHookSpan[\Fiber::getCurrent()] = $span = $hook->span($stack);
                        $span->meta["event-loop.type"] = "repeat";
                        $span->meta["event-loop.interval"] = $delay;
                        $span->metrics["event-loop.invocation_delay"] = $now - $enableTime - $delay;
                        $watcherRegistrations[$hook->args[0]][1] = $now;
                    }, $fiberHookEndClosure);
                }
            }
        ]);

        foreach ([["stream", "onWritable"], ["stream", "onReadable"], ["signal", "onSignal"]] as list($type, $method)) {
            \DDTrace\hook_method('Revolt\EventLoop', $method, [
                'posthook' => function ($revolt, $scope, $args, $retval) use ($method, $type, $registeredHooks, &$watcherRegistrations, $fiberHookSpan, $fiberHookEndClosure) {
                    $closure = $args[1];
                    $watcherRegistrations[$retval] = [\DDTrace\create_stack(), microtime(true)];
                    \DDTrace\switch_stack();
                    if (!isset($registeredHooks[$closure])) {
                        $registeredHooks[$closure] = true;
                        \DDTrace\install_hook($closure, function (HookData $hook) use ($type, $method, &$watcherRegistrations, $fiberHookSpan) {
                            $now = microtime(true);
                            list($stack, $enableTime) = $watcherRegistrations[$hook->args[0]];
                            $fiberHookSpan[\Fiber::getCurrent()] = $span = $hook->span($stack);
                            $span->meta["event-loop.type"] = $method;
                            $span->metrics["event-loop.invocation_delay"] = $now - $enableTime;
                            $span->metrics["event-loop.$type"] = (int)$hook->args[1];
                            $watcherRegistrations[$hook->args[0]][1] = $now;
                        }, $fiberHookEndClosure);
                    }
                }
            ]);
        }

        \DDTrace\hook_method('Revolt\EventLoop', 'cancel', function ($revolt, $scope, $args) use (&$watcherRegistrations) {
            unset($watcherRegistrations[$args[0]]);
        });

        \DDTrace\hook_method('Revolt\EventLoop', 'disable', function ($revolt, $scope, $args) use (&$watcherRegistrations) {
            unset($watcherRegistrations[$args[0]][0]);
        });

        // ensure the right stack is used
        \DDTrace\hook_method('Revolt\EventLoop', 'enable', function ($revolt, $scope, $args) use (&$watcherRegistrations) {
            if (isset($watcherRegistrations[$args[0]]) && !isset($watcherRegistrations[$args[0]][0])) {
                $watcherRegistrations[$args[0]] = [\DDTrace\create_stack(), microtime(true)] + ($watcherRegistrations[$args[0]] ?? []);
                \DDTrace\switch_stack();
            }
        });


        return Integration::LOADED;
    }
}
