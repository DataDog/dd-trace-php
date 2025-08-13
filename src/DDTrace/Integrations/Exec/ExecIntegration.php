<?php

namespace DDTrace\Integrations\Exec;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

use function DDTrace\active_stack;
use function DDtrace\close_span;
use function DDTrace\create_stack;
use function DDTrace\start_span;
use function DDTrace\switch_stack;

class ExecIntegration extends Integration
{
    const MAX_CMD_SIZE = 4 * 1024;
    const NAME = "exec";
    const REDACTED_PARAM_PAT =
          '/\A(?i)-{0,2}(?:p(?:ass(?:w(?:or)?d)?)?|api_?key|secret|'
              . 'a(?:ccess|uth)_token|mysql_pwd|credentials|(?:stripe)?token)\z/';
    const REDACTED_BINARIES = array('md5' => null);
    const UNREDACTED_ENV_VARS = array('LD_PRELOAD' => null, 'LD_LIBRARY_PATH' => null, 'PATH' => null);

    public static function init(): int
    {
        \DDTrace\install_hook(
            'exec',
            self::preHookShell('exec'),
            self::postHookShell('exec')
        );
        \DDTrace\install_hook(
            'system',
            self::preHookShell('system'),
            self::postHookShell('system')
        );
        \DDTrace\install_hook(
            'passthru',
            self::preHookShell('passthru'),
            self::postHookShell('passthru')
        );
        \DDTrace\install_hook(
            'shell_exec',
            self::preHookShell('shell_exec'),
            self::postHookShell('shell_exec')
        );

        \DDTrace\install_hook(
            'popen',
            self::preHookShell('popen'),
            static function (HookData $hook) {
                /** @var SpanData $span */
                $span = $hook->data;
                if (!$span) {
                    return;
                }

                if ($hook->exception) {
                    $span->exception = $hook->exception;
                } elseif (!is_resource($hook->returned)) {
                    $span->meta[Tag::ERROR_MSG] = 'popen() did not return a resource';
                } else {
                    register_stream($hook->returned, $span);
                }
            }
        );


        /*
         * This instrumentation works by creating a span on the enter callback, and then
         * associating this span with the resource returned by proc_open. This association
         * is done by adding a resource to the list of pipes of the proc resource. This
         * resource (of type dd_proc_span) is not an actual pipe, but it doesn't matter;
         * PHP will only ever destroy this resource.
         *
         * When the proc resource is destroyed, the dd_proc_span resource is destroyed as
         * well, and in the process the span is finished, unless it was finished before
         * in proc_get_status.
         */
        \DDTrace\install_hook(
            'proc_open',
            static function (HookData $hook) {
                if (count($hook->args) == 0) {
                    return;
                }

                $arg = $hook->args[0];
                if (is_string($arg)) {
                    $tags = self::createTagsShellString($arg);
                    if ($tags === null) {
                        return;
                    }

                    $span = self::createSpan($tags, 'sh');
                } elseif (is_array($arg) && PHP_VERSION_ID >= 70400 && count($arg) && is_string($arg[0])) {
                    $tags = self::createTagsExec($arg);
                    if ($tags === null) {
                        return;
                    }

                    $spanName = self::baseBinary($arg);
                    $span = self::createSpan($tags, $spanName);
                } else {
                    return;
                }

                $hook->data = $span;
            },
            static function (HookData $hook) {
                /** @var SpanData $span */
                $span = $hook->data;
                if (!$span) {
                    return;
                }

                if ($hook->exception) {
                    $span->exception = $hook->exception;
                } elseif (!is_resource($hook->returned)) {
                    $span->meta[Tag::ERROR_MSG] = 'proc_open() did not return a resource';
                } else {
                    proc_assoc_span($hook->returned, $span);
                }
            }
        );

        \DDTrace\install_hook(
            'proc_get_status',
            null,
            static function (HookData $hook) {
                if (count($hook->args) != 1 || !is_resource($hook->args[0])) {
                    return;
                }

                $span = proc_get_span($hook->args[0]);
                if (!$span) {
                    return;
                }

                if ($span->getDuration() != 0) {
                    // already finished
                    return;
                }

                if (!is_array($hook->returned) || $hook->exception) {
                    return;
                }

                if ($hook->returned['running']) {
                    return;
                }

                if ($hook->returned['signaled']) {
                    $span->meta[Tag::ERROR_MSG] = 'The process was terminated by a signal';
                    $span->meta[Tag::EXEC_EXIT_CODE] = $hook->returned['termsig'];
                } else {
                    $span->meta[Tag::EXEC_EXIT_CODE] = $hook->returned['exitcode'];
                }

                self::finishSpanRestoreStack($span);
            }
        );

        \DDTrace\install_hook(
            'proc_close',
            static function (HookData $hook) {
                if (count($hook->args) != 1 || !is_resource($hook->args[0])) {
                    return;
                }

                // the span must be stored in $hook because by the time the post
                //hook runs, the resource has already been destroyed
                $span = proc_get_span($hook->args[0]);
                if (!$span) {
                    return;
                }
                // must match condition in dd_proc_wrapper_rsrc_dtor before
                // calling dd_waitpid()
                if ($span->getDuration() != 0) {
                    return;
                }
                // if we get here, we will call waitpid() in the resource
                // destructor and very likely reap the process, resulting in
                // proc_close() returning -1
                $hook->data = $span;
            },
            static function (HookData $hook) {
                /** @var SpanData $span */
                $span = $hook->data ?? null;
                if (!$span) {
                    return;
                }

                if ($hook->returned === -1 && isset($span->meta[Tag::EXEC_EXIT_CODE])) {
                    $hook->overrideReturnValue($span->meta[Tag::EXEC_EXIT_CODE]);
                }
            }
        );

        return Integration::LOADED;
    }

    const RET_CODE_ARGNUM = [
        'exec'       => 2,
        'system'     => 1,
        'passthru'   => 1,
        'shell_exec' => null,
    ];

    private static function preHookShell($variant)
    {
        return static function (HookData $hook) use ($variant) {
            if (count($hook->args) == 0 || !is_string($hook->args[0])) {
                return;
            }

            $tags = self::createTagsShellString($hook->args[0]);
            if ($tags === null) {
                return;
            }

            $span = self::createSpan($tags, 'sh');
            $hook->data = $span;

            $retCodeArg = self::RET_CODE_ARGNUM[$variant];
            if (empty($retCodeArg)) {
                return;
            }

            while (count($hook->args) < $retCodeArg) {
                $hook->args[] = null;
            }
            if (count($hook->args) == $retCodeArg) {
                $exitCode = null;
                $hook->args[] =& $exitCode;
            }

            // can fail if there isn't enough stack space
            $hook->overrideArguments($hook->args);
        };
    }

    private static function postHookShell($variant)
    {
        return static function (HookData $hook) use ($variant) {
            /** @var SpanData $span */
            $span = $hook->data;
            if (!$span) {
                return;
            }

            $retCodeArg = self::RET_CODE_ARGNUM[$variant];

            if ($hook->exception) {
                $span->exception = $hook->exception;
            } elseif ($hook->returned === false) {
                $span->meta[Tag::ERROR_MSG] = "$variant() returned false";
            } elseif ($hook->returned === null && $variant === 'shell_exec') {
                $span->meta[Tag::ERROR_MSG] = "shell_exec() returned null";
            } elseif (
                !empty($retCodeArg) &&
                isset($hook->args[$retCodeArg]) &&
                $hook->args[$retCodeArg] !== null
            ) {
                $span->meta[Tag::EXEC_EXIT_CODE] = $hook->args[$retCodeArg];
            }

            self::finishSpanRestoreStack($span);
        };
    }

    private static function createSpan(array $tags, string $resource)
    {
        create_stack();
        $span = start_span();
        $span->name = 'command_execution';
        $span->meta = $tags;
        $span->type = Type::SYSTEM;
        $span->resource = $resource;
        \DDTrace\collect_code_origins(2); // manually collect origin, otherwise the top frame will be this integration
        switch_stack();

        return $span;
    }

    /**
     * Tags for execution of ['/bin/sh', '-c', $cmd]
     *
     * @param $cmd the command to be executed
     * @return array|null
     */
    private static function createTagsShellString($cmd)
    {
        if (!is_string($cmd) || trim($cmd) === '') {
            return null;
        }

        $cmd = self::redactParametersShell(self::redactEnvVariablesShell($cmd));
        $truncated = strlen($cmd) > self::MAX_CMD_SIZE;
        if ($truncated) {
            $cmd = substr($cmd, 0, self::MAX_CMD_SIZE);
        }

        $ret = [
            Tag::EXEC_CMDLINE_SHELL => $cmd,
            Tag::COMPONENT => 'subprocess',
        ];
        if ($truncated) {
            $ret[Tag::EXEC_TRUNCATED] = true;
        }
        return $ret;
    }


    private static function createTagsExec(array $cmd)
    {
        if (empty($cmd)) {
            return null;
        }

        $cmd = self::redactParametersExec($cmd);
        $totalLen = 0;
        $truncated = false;
        $cmdTmp = [];
        foreach ($cmd as $arg) {
            if ($totalLen + strlen($arg) > self::MAX_CMD_SIZE) {
                $left = self::MAX_CMD_SIZE - $totalLen;
                $arg = substr($arg, 0, $left);
                $cmdTmp[] = $arg;
                $truncated = true;
            } else {
                $cmdTmp[] = $arg;
            }
            $totalLen += strlen($arg);
        }
        $cmd = $cmdTmp;

        $ret = [
            Tag::EXEC_CMDLINE_EXEC => self::encodeArray($cmd),
            Tag::COMPONENT => 'subprocess',
        ];
        if ($truncated) {
            $ret[Tag::EXEC_TRUNCATED] = true;
        }
        return $ret;
    }

    private static function each_shell_word($cmd, $f)
    {
        preg_match_all('/(?:
            \\\\.             |
            [^\s"\';|&]       |
            "(?:\\\\.|[^"])*" |
            \'(?:\\\\.|[^\'])*\'
            )+/x', $cmd, $result, PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE);
        for ($i = 0; $i < count($result[0]); $i++) {
            list($text, $offset) = $result[0][$i];
            if (!$f($text, $offset, $offset + strlen($text))) {
                break;
            }
        }
    }

    /**
     * On a POSIX shell command, replace environment variable values.
     *
     * Example:
     *   FOO=xxx command;BAR=yyy cmd
     * turns into
     *   FOO=? command;BAR=? cmd
     *
     * Due to the complexity of the shell language, this will not catch 100% of the cases
     * (e.g. case...esac).
     *
     * @param string $cmd the original command
     * @return string the redacted command
     */
    private static function redactEnvVariablesShell($cmd)
    {
        $redacted = $cmd;
        $offset = 0;
        $prevEnd = 0;
        self::each_shell_word($cmd, static function ($text, $start, $end) use ($cmd, &$redacted, &$offset, &$prevEnd) {
            $commandBegin = ($prevEnd === 0 || self::intersticeHasCommandSeparator($cmd, $prevEnd, $start));

            if (!$commandBegin) {
                $prevEnd = $end;
                return true; // it's likely an argument; continue
            }

            if ($text === 'do' || $text === 'then') {
                // ignore the keyword
                // do not adjust prevEnd so the separator is found again next time
                return true;
            }


            if (self::matchEnvVariableAssignment($text, $matches)) {
                // matches a valid name for an environment variable
                // note that quotes/escapes are not allowed in env var names
                // `"FOO=x" command` doesn't set an env var
                if (key_exists($matches[1], self::UNREDACTED_ENV_VARS)) {
                    return true;
                }

                self::replaceWithOffset(
                    $redacted,
                    $offset,
                    $start + strlen($matches[0]),
                    $end,
                    '?'
                );

                // do not adjust prevEnd so we find separators/start again
                // this is because we can have several env vars: A=x B=y cmd
            } else {
                $prevEnd = $end;
            }

            return true; // continue
        });

        return $redacted;
    }

    private static function redactParametersShell($cmd)
    {
        $redacted = $cmd;
        $offset = 0;
        $prevEnd = 0;
        $redactAll = false;
        $redactNext = false;
        self::each_shell_word($cmd, static function ($text, $start, $end) use ($cmd, &$redacted, &$offset, &$prevEnd, &$redactAll, &$redactNext) {
            // start of command
            if ($prevEnd === 0 || self::intersticeHasCommandSeparator($cmd, $prevEnd, $start)) {
                // simplified way to handle fors and ifs
                // it's not strictly correct: `X=y do` tries to execute 'do'
                if ($text === 'do' || $text === 'then') {
                    return true;
                }
                if (self::matchEnvVariableAssignment($text)) {
                    return true;
                }

                // then assume we have the command
                $baseName = basename(self::unquote($text));
                if (key_exists($baseName, self::REDACTED_BINARIES)) {
                    $redactAll = true;
                } else {
                    $redactAll = false;
                    $redactNext = false;
                }

                $prevEnd = $end;
                return true;
            }

            $prevEnd = $end;

            // apply the argument redaction logic
            if ($redactAll) {
                self::replaceWithOffset($redacted, $offset, $start, $end, '?');
                return true;
            }

            if ($redactNext) {
                $redactNext = false;
                self::replaceWithOffset($redacted, $offset, $start, $end, '?');
                return true;
            }

            $unquotedText = self::unquote($text);
            $equalsPos = strpos($unquotedText, '=');
            if ($equalsPos === false) { // no =
                if (preg_match(self::REDACTED_PARAM_PAT, $unquotedText)) {
                    $redactNext = true;
                }
            } else { // we have =
                if (preg_match(self::REDACTED_PARAM_PAT, substr($unquotedText, 0, $equalsPos))) {
                    self::replaceWithOffset(
                        $redacted,
                        $offset,
                        $start + strpos($text, '=') + 1,
                        $end - (($text[0] === '"' || $text[0] === "'") ? 1 : 0),
                        '?'
                    );
                }
            }

            return true; // continue
        });

        return $redacted;
    }

    private static function baseBinary(array $cmd)
    {
        return basename($cmd[0]);
    }

    private static function redactParametersExec(array $cmd)
    {
        if (key_exists(self::baseBinary($cmd), self::REDACTED_BINARIES)) {
            $ret = array($cmd[0]);
            array_fill($ret, 1, count($cmd) - 1, '?');
            return $ret;
        }

        $redactNext = false;
        foreach ($cmd as &$arg) {
            if ($redactNext) {
                $arg = '?';
                $redactNext = false;
            }
            $equalsPos = strpos($arg, '=');
            if ($equalsPos === false) {
                if (preg_match(self::REDACTED_PARAM_PAT, $arg)) {
                    $redactNext = true;
                }
            } else { // we have =
                if (preg_match(self::REDACTED_PARAM_PAT, substr($arg, 0, $equalsPos))) {
                    $offset = 0;
                    self::replaceWithOffset($arg, $offset, $equalsPos + 1, strlen($arg), '?');
                }
            }
        }
        return $cmd;
    }

    private static function intersticeHasCommandSeparator($cmd, $prevEnd, $curWordStart)
    {
        $interstice = substr($cmd, $prevEnd, $curWordStart - $prevEnd);
        // between two tokens we have ; & | && || \n
        // this is for a POSIX shell
        // bash for instance would require much more logic
        return preg_match('/[;|&\n]/', $interstice);
    }

    private static function matchEnvVariableAssignment($word, &$matches = null)
    {
        return preg_match('/\A\s*([a-zA-Z_][a-zA-Z\d]*)=/', $word, $matches);
    }

    /**
     * Do an inline replacement on a string where the indices $start and $end
     * are shifted by $offset (the reflect offsets in the original string).
     *
     * @param $str string the string to be modified
     * @param $offset int how much to shift the indices
     * @param $start int the original start index
     * @param $end int the original end index
     * @param $replacement string the replacement string
     * @return void
     */
    private static function replaceWithOffset(&$str, &$offset, $start, $end, $replacement)
    {
        $replacedLen = $end - $start;
        $ret = substr($str, 0, $start + $offset);
        $ret .= $replacement;
        $ret .= substr($str, $end + $offset);
        $offset += strlen($replacement) - $replacedLen;
        $str = $ret;
    }

    /**
     * Removes outside quotes on an argument. It does not remove inside quotes (a'b'c) or
     * unescapes sequences, though it could be improved so that it does.
     *
     * @param $str string the string to unquote
     * @return string
     */
    private static function unquote($str)
    {
        if (strlen($str) < 2) {
            return $str;
        }
        if ($str[0] == "'" && $str[strlen($str) - 1] === "'") {
            return substr($str, 1, strlen($str) - 2);
        }
        if ($str[0] == '"' && $str[strlen($str) - 1] === '"') {
            return substr($str, 1, strlen($str) - 2);
        }
        return $str;
    }

    private static function encodeArray(array $arr)
    {
        return '[' . implode(',', array_map(
            static function ($str) {
                return '"' . str_replace('"', '\"', $str) . '"';
            },
            $arr
        )) . ']';
    }

    private static function finishSpanRestoreStack(SpanData $span)
    {
        $stackBefore = active_stack();
        switch_stack($span->stack);
        close_span();
        switch_stack($stackBefore);
    }
}
