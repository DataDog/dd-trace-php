#include "stack_collector.hh"

extern "C" {
#include <php.h>
#include <sys/types.h>
#include <sys/uio.h>
#include <unistd.h>
}

#include <memory>
#include <thread>

struct ddtrace_sample_entry {
    zend_string *function;
    zend_string *filename;
    uint32_t lineno;
};

namespace ddtrace {

namespace {

/* Heavily inspired by Nikita Popov's sampling profiler
   https://github.com/nikic/sample_prof */

#define DD_SAMPLE_DEFAULT_INTERVAL 9973  // prime close to 10 millisecond
#define DD_MAX_CALL_DEPTH 64

typedef enum dd_readv_result {
    DD_READV_FAILURE = -1,
    DD_READV_SUCCESS = 0,
    DD_READV_PARTIAL = 1,
} dd_readv_result;

typedef struct dd_readv_t {
    void *local;
    void *remote;
    size_t size;
} dd_readv_t;

dd_readv_result dd_process_vm_readv(pid_t pid, dd_readv_t readv) {
    struct iovec local = {.iov_base = readv.local, .iov_len = readv.size};
    struct iovec remote = {.iov_base = readv.remote, .iov_len = readv.size};

    ssize_t bytes_read = process_vm_readv(pid, &local, 1, &remote, 1, 0);

    ssize_t bytes_expected = readv.size;
    if (EXPECTED(bytes_read == bytes_expected)) {
        return DD_READV_SUCCESS;
    }

    return bytes_read < 0 ? DD_READV_FAILURE : DD_READV_PARTIAL;
}

dd_readv_result dd_process_vm_readv_multiple(pid_t pid, unsigned n, dd_readv_t readvs[]) {
    // we only support up to 4 reads atm
    ZEND_ASSERT(n <= 4);
    struct iovec local[4];
    struct iovec remote[4];

    ssize_t bytes_expected = 0;
    for (unsigned i = 0; i < n; ++i) {
        dd_readv_t readv = readvs[i];
        local[i].iov_len = readv.size;
        local[i].iov_base = readv.local;
        remote[i].iov_len = readv.size;
        remote[i].iov_base = readv.remote;
        bytes_expected += readv.size;
    }

    ssize_t bytes_read = process_vm_readv(pid, local, n, remote, n, 0);

    if (EXPECTED(bytes_read == bytes_expected)) {
        return DD_READV_SUCCESS;
    }

    return bytes_read < 0 ? DD_READV_FAILURE : DD_READV_PARTIAL;
}

zend_string *dd_readv_string(pid_t pid, datadog_arena *arena, zend_string *remote) {
    if (!remote) {
        return nullptr;
    }

    zend_string tmp;
    dd_readv_result result = dd_process_vm_readv(pid, (dd_readv_t){&tmp, remote, sizeof tmp});
    if (UNEXPECTED(result != DD_READV_SUCCESS)) {
        if (result == DD_READV_PARTIAL) {
            // ddtrace_log_errf("Continuous profiling: partial read of zend_string");
        } else {
            // ddtrace_log_errf("Continuous profiling: failed to read zend_string");
        }
        return nullptr;
    }

    // zend_strings are null terminated, hence the +1
    size_t total_size = offsetof(zend_string, val) + tmp.len + 1;
    char *checkpoint = datadog_arena_checkpoint(arena);
    auto local = (zend_string *)datadog_arena_try_alloc(arena, total_size);
    if (!local) {
        // ddtrace_log_errf("Continuous profiling: failed to arena allocate a zend_string of length %l", total_size);
        return nullptr;
    }

    result = dd_process_vm_readv(pid, (dd_readv_t){local, remote, total_size});
    if (UNEXPECTED(result != DD_READV_SUCCESS)) {
        // ddtrace_log_errf("Continuous profiling: failed to read zend_string data");
        datadog_arena_restore(&arena, checkpoint);
        return nullptr;
    }

    local->gc.u.type_info = IS_STR_INTERNED;
    GC_SET_REFCOUNT(local, 1);

    return local;
}

}  // namespace

stack_collector::stack_collector(ddprof::recorder &r) : recorder{r}, m{}, thread{}, running{false}, thread_id{} {}

static void push(ddprof::recorder &recorder, size_t entries_num, ddtrace_sample_entry entries[]) {
    ddprof::event event{};
    event.name = 0;                                 // todo
    event.system_time = ddprof::system_clock::now(); // todo: should these be passed in?
    event.steady_time = ddprof::steady_clock::now();
    event.thread_id = getpid();                                   // todo: get sampled thread, not collector thread
    event.thread_name = 0;                                        // todo

    for (std::size_t i = 0; i < entries_num; ++i) {
        ddtrace_sample_entry *entry = entries + i;

        size_t function = 0;
        size_t filename = 0;
        if (entry->function) {
            auto &interned = recorder.intern({entry->function->len, &entry->function->val[0]});
            function = interned.offset;
        }

        if (entry->filename) {
            auto &interned = recorder.intern({entry->filename->len, &entry->filename->val[0]});
            filename = interned.offset;
        }

        int64_t lineno = entry->lineno;
        ddprof::frame frame{function, filename, lineno};
        event.frames.push_back(frame);
    }

    if (!event.frames.empty()) {
        recorder.push(std::make_unique<ddprof::event>(std::move(event)));
    }
}

void stack_collector::collect() {
#if !defined(ZTS)
    volatile zend_executor_globals *eg = &executor_globals;

    /* Big open question: is process_vm_readv _actually_ providing safety?
     * The thought is that if they aren't in the address space then it won't
     * sigsegv, which is good but not the whole story because of the ZMM. If an
     * address exists in the process but has been efree'd then it will have
     * garbage and I don't think we can tell at all. Maybe we can use some
     * heuristics based on its type and context? For instance, a string that
     * represents a function or class name is probably less than 100 characters
     * and its refcount is probably also less than 100.
     *
     * Really should consider investing into PHP 8.1 some way to do this safely.
     */

    // todo: pass PID of actual thread we're profiling
    pid_t pid = thread_id = getpid();

    size_t entries_num = 0;
    auto *entries = (ddtrace_sample_entry *)calloc(DD_MAX_CALL_DEPTH, sizeof(ddtrace_sample_entry));

    // if we run out of space in a single arena, stop gathering data
    datadog_arena *profiling_arena = datadog_arena_create(1048576);  // 1 MiB
    char *checkpoint = datadog_arena_checkpoint(profiling_arena);

    while (true) {
        {
            std::lock_guard<std::mutex> lock{m};
            if (!running) break;
        }
        std::this_thread::sleep_for(std::chrono::microseconds(DD_SAMPLE_DEFAULT_INTERVAL));

        zend_execute_data local_ex, local_prev_execute_data;

        zend_executor_globals local_globals;
        dd_readv_result read_globals =
            dd_process_vm_readv(pid, (dd_readv_t){&local_globals, (void *)eg, sizeof local_globals});

        /* We're not executing code right now, try again later */
        if (read_globals != DD_READV_SUCCESS || !local_globals.current_execute_data) continue;

        zend_execute_data *remote_ex = local_globals.current_execute_data;

        dd_readv_result readv_result = dd_process_vm_readv(pid, (dd_readv_t){&local_ex, remote_ex, sizeof local_ex});
        if (UNEXPECTED(readv_result != DD_READV_SUCCESS)) {
            if (readv_result == DD_READV_FAILURE) {
                std::cerr << "Continuous profiling: failed to read root execute_data call frame: " << strerror(errno);
                std::cerr << "\n";
            } else {
                std::cerr << "Continuous profiling: partial read on root execute_data call frame\n";
            }
            continue;
        }

        bool should_continue = true;
        do {
            /* Avoid nullptrs in our reads so that we can distinguish between
             * failed and partial reads. Also minimize the number of remote
             * reads for performance.
             * Conditionally build up an array of things to read to solve this.
             */
            unsigned n = 0;
            dd_readv_t readv[3];

            zend_function local_func;
            zend_op local_opline;

            if (local_ex.prev_execute_data) {
                readv[n++] =
                    (dd_readv_t){&local_prev_execute_data, local_ex.prev_execute_data, sizeof local_prev_execute_data};
            } else {
                should_continue = false;
            }

            bool has_func = local_ex.func;
            if (has_func) {
                readv[n++] = (dd_readv_t){&local_func, local_ex.func, sizeof local_func};
            }

            bool has_opline = local_ex.opline;
            if (has_opline) {
                readv[n++] = (dd_readv_t){&local_opline, (void *)local_ex.opline, sizeof local_opline};
            }

            if (n == 0) break;

            dd_readv_result read_result = dd_process_vm_readv_multiple(pid, n, readv);
            if (UNEXPECTED(read_result != DD_READV_SUCCESS)) {
                if (read_result == DD_READV_FAILURE) {
                    std::cerr << "Continuous profiling: failed to read sub-object of execute_data: " << strerror(errno);
                    std::cerr << "\n";
                } else {
                    std::cerr << "Continuous profiling: partial read\n";
                }
                /* if we couldn't fetch the prev_execute_data then we're done
                 * with this specific stack trace.
                 */
                break;
            }

            if (local_ex.prev_execute_data) {
                local_ex = local_prev_execute_data;
            }

            if (has_func) {
                if (ZEND_USER_CODE(local_func.type)) {
                    entries[entries_num].function =
                        dd_readv_string(pid, profiling_arena, local_func.op_array.function_name);
                    if (!entries[entries_num].function && local_func.op_array.function_name) {
                        // ddtrace_log_err("Continuous profiling: failed to read userland function name");
                    }
                    entries[entries_num].filename = dd_readv_string(pid, profiling_arena, local_func.op_array.filename);
                    entries[entries_num].lineno = has_opline ? local_opline.lineno : 0;

                } else {
                    zend_string *function = local_func.internal_function.function_name;
                    entries[entries_num].function = dd_readv_string(pid, profiling_arena, function);
                    if (function && !entries[entries_num].function) {
                        // ddtrace_log_err("Continuous profiling: failed to read internal function name");
                    }
                    entries[entries_num].filename = nullptr;
                    entries[entries_num].lineno = 0;
                }

                if (++entries_num == DD_MAX_CALL_DEPTH) {
                    // todo: emit summary frame "$n frames dropped" or something
                    goto push_events;
                }
            }

        } while (should_continue);

    push_events:
        push(recorder, entries_num, entries);
        // Once events have been pushed we can reset entries and arena
        entries_num = 0;
        datadog_arena_restore(&profiling_arena, checkpoint);
    }

    free(entries);
    datadog_arena_destroy(profiling_arena);
#endif

    std::lock_guard<std::mutex> lock{m};
    running = false;
}

void stack_collector::start() {
    std::lock_guard<std::mutex> lock{m};
    if (!running) {
        running = true;
        thread = std::thread(&stack_collector::collect, this);
    }
}

void stack_collector::stop() noexcept {
    std::lock_guard<std::mutex> lock{m};
    running = false;
}

void stack_collector::join() {
    // careful, we aren't holding a lock here
    if (thread.joinable()) {
        thread.join();
    }
}

}  // namespace ddtrace
