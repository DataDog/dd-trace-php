<?php

namespace DDTrace\Integrations\ReactPromise;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Ratchet\RatchetIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use React\Promise\Promise;
use function DDTrace\active_stack;
use function DDTrace\close_span;
use function DDTrace\create_stack;
use function DDTrace\start_span;
use function DDTrace\switch_stack;

class ReactPromiseIntegration extends Integration
{
    const NAME = 'reactpromise';

    public function init(): int
    {
        $integration = $this;

        \DDTrace\install_hook('React\Promise\Promise::__construct', function () {
            ObjectKVStore::put($this, "span", \DDTrace\active_span());
        });

        \DDTrace\install_hook('React\Promise\Promise::then', function (HookData $hook) {
            /** @var SpanData $span */
            $span = ObjectKVStore::get($this, "span");
            $activeSpan = \DDTrace\active_span();
            if ($activeSpan) {
                $parent = $span;
                do { // TODO: check performance
                    if ($parent === $activeSpan) {
                        $span = null;
                        break;
                    }
                } while ($parent = $parent->parent);
            }
            $stack = \DDTrace\create_stack();
            \DDTrace\switch_stack();
            $argc = min(2, \count($hook->args));
            for ($i = 0; $i < $argc; ++$i) {
                if ($arg = $hook->args[$i]) {
                    $hook->args[$i] = function ($result) use ($arg, $stack, $span) {
                        $activeStack = \DDTrace\active_stack();
                        try {
                            \DDTrace\switch_stack($stack);
                            if ($span) {
                                $stack->spanCreationObservers[] = function ($innerSpan) use (&$span) {
                                    if ($span) {
                                        $innerSpan->links[] = $span->getLink();
                                    }
                                    return false;
                                };
                            }
                            return $arg($result);
                        } finally {
                            $span = null;
                            \DDTrace\switch_stack($activeStack);
                        }
                    };
                }
            }
            $hook->overrideArguments($hook->args);
        });

        return Integration::LOADED;
    }

    public static function attachPromiseSpan(Promise $promise, SpanData $span, $success, $failure = null)
    {
        $promise->then(function ($ret) use ($span, $success) {
            $stackBefore = active_stack();
            switch_stack($span);

            $success($ret);

            close_span();
            switch_stack($stackBefore);
        }, function ($exception) use ($span, $failure) {
            $stackBefore = active_stack();
            switch_stack($span);

            $span->exception = $exception;
            if ($failure) {
                $failure($exception);
            }

            close_span();
            switch_stack($stackBefore);
        });
    }

    public static function tracePromiseFunction(string $target, $begin, $success, $failure = null)
    {
        \DDTrace\install_hook($target, function (HookData $hook) use ($begin) {
            create_stack();
            $begin($hook, start_span());
        }, function (HookData $hook) use ($success, $failure) {
            $span = \DDTrace\active_span();
            if ($hook->exception) {
                $span->exception = $hook->exception;
                if ($failure) {
                    $failure($hook, $span, $hook->exception);
                }
                close_span();
            } else {
                ReactPromiseIntegration::attachPromiseSpan($hook->returned, $span, function ($ret) use ($hook, $span, $success) {
                    $success($hook, $span, $ret);
                }, $failure ? function ($exception) use ($hook, $span, $failure) {
                    $failure($hook, $span, $exception);
                } : null);
                switch_stack();
            }
        });
    }
}
