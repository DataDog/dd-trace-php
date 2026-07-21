# Debugging with GDB via tmux

## Setup

```bash
# Start gdb in tmux with pagination off BEFORE attach (-iex runs before target load)
tmux new-session -d -s gdb "gdb -q -iex 'set pagination off' -iex 'set confirm off' /path/to/binary -p PID"
sleep 5  # wait for symbol loading
```

Key: use `-iex` (not `-ex`) for settings that must apply before symbol
loading/attach.

## Sending commands

```bash
tmux send-keys -t gdb 'command here' Enter
sleep 0.5  # give gdb time to process
tmux capture-pane -t gdb -p | tail -15  # read output
```

- Always add a `sleep` between send and capture — gdb needs time.
- Use `tail -N` to read just the relevant output.
- For long output, use `tmux capture-pane -t gdb -p -S -100` to get scrollback.

## Avoid: interactive gdb loops via send-keys

GDB's `while`/`end` interactive loop syntax does NOT work reliably via `tmux
send-keys`. The `end` keyword gets swallowed or the `>` continuation prompt
misaligns with the input.

**Instead: use Python scripts.**

Write a `.py` file and `source` it:

```bash
cat > /tmp/script.py << 'PYEOF'
import gdb
val = gdb.parse_and_eval('some_var')
print(f'val = {val}')
PYEOF

tmux send-keys -t gdb 'source /tmp/script.py' Enter
sleep 2
tmux capture-pane -t gdb -p | tail -10
```

This is the single most important lesson: **any logic beyond a flat sequence of
gdb commands should be a Python script sourced into gdb.**

## Reading optimized-out variables

Local variables are often optimized out at certain breakpoints. Workarounds:
- Read the value from the struct it came from (e.g., `ns->_ns_nloaded` may be
  optimized out, but you can recompute it by walking the linked list in a
  Python script).
- Break earlier (before the variable goes out of scope).
- Use `info registers` and correlate with disassembly.

## Watchpoints

Watchpoints are very effective via tmux — they don't require interaction:

```bash
tmux send-keys -t gdb 'watch var->field' Enter
sleep 0.3
tmux send-keys -t gdb 'c' Enter
sleep 5
tmux capture-pane -t gdb -p | tail -15
```

When the watchpoint fires, gdb shows old/new values and stops. Then inspect
with `bt`, `p`, etc.

## Batch sequences

For multi-step flows, chain commands with sleeps:

```bash
docker exec container bash -c "
tmux send-keys -t gdb 'b some_function' Enter
sleep 0.3
tmux send-keys -t gdb 'c' Enter
sleep 5
tmux capture-pane -t gdb -p | tail -15
"
```

## Language setting: C vs Rust

The sidecar is Rust; PHP extensions are C. **gdb must be in the correct
language mode** for symbol resolution and expression evaluation.

- After `attach`, gdb defaults to the language of the current frame (usually
  C from a syscall).
- Set `set language rust` before working with Rust symbols (breakpoints,
  `p` expressions).
- `(char*)` casts require C mode. Rust mode uses different syntax.
- When mixing: switch with `set language c` / `set language rust` as needed.

### Common pitfall: `gdb.Breakpoint()` in Python

`gdb.Breakpoint('rust::symbol::Name')` silently fails if gdb is in C
language mode — it returns without creating the breakpoint and without
raising an exception. Always ensure `set language rust` before creating
Rust breakpoints from Python.

Interactive `python ... end` blocks work when `set language rust` is set
before the block. But `source script.py` may fail silently if the language
was wrong at the time.

## Attaching to the sidecar

GDB can't find the process image of sidecar (`datadog-ipc-helper`) without
help. You **must** use `file /proc/<pid>/exe` before `attach`:

```bash
docker exec CONTAINER tmux new-session -d -s gdb \
    "gdb -q -iex 'set pagination off' -iex 'set confirm off' \
     -ex 'file /proc/PID/exe' -ex 'attach PID' -ex 'set language rust'"
```

If you restart gdb or kill the tmux session, you must redo `file /proc/PID/exe`
before attach.

Find the sidecar PID:
```bash
docker exec CONTAINER pgrep -f 'datadog-ipc.*ddog_daemon_entry_point' | tail -1
```


## Non-stopping Python breakpoints for filtering

When you need to catch a specific payload among many, use a Python breakpoint
class that auto-continues on non-matching hits. Example:

```python
import gdb, re, json

class CatchMessageBatch(gdb.Breakpoint):
    def stop(self):
        try:
            body_out = gdb.execute("output req.body", to_string=True)
            m_ptr = re.search(r"ptr: (0x[0-9a-f]+)", body_out)
            m_len = re.search(r"len: (\d+)", body_out)
            if not m_ptr or not m_len:
                return False
            ptr = int(m_ptr.group(1), 16)
            length = int(m_len.group(1))
            mem = gdb.selected_inferior().read_memory(ptr, length)
            content = bytes(mem).decode("utf-8", errors="replace")
            d = json.loads(content)
            rt = d.get("request_type", "?")
            if rt == "message-batch":
                sub = [m.get("request_type", "?") for m in d.get("payload", [])]
                print(f">>> message-batch: {sub}")
                if "app-endpoints" in sub:
                    with open("/tmp/ep.json", "w") as f:
                        json.dump(d, f, indent=2)
                    print(">>> CAUGHT - saved to /tmp/ep.json")
                    return True  # stop
        except:
            pass
        return False  # continue

bp = CatchMessageBatch("libdd_telemetry::worker::TelemetryWorker::send_request")
```

## Async Rust stepping

`next` in an async Rust function steps through the tokio state machine, not
the original source lines. Instead of stepping:
- Set breakpoints on specific functions (`build_request`, `send_request`)
- Use `continue` to jump between breakpoints
- Inspect state at each breakpoint rather than trying to step through

## Cleanup

```bash
tmux send-keys -t gdb 'quit' Enter
sleep 0.5
tmux kill-server 2>/dev/null
```
