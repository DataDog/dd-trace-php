#define _GNU_SOURCE

#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <unistd.h>

#ifndef RAW_TOOL_PATH
#error RAW_TOOL_PATH must be defined
#endif

#ifndef EXEC_LOADER_PATH
#error EXEC_LOADER_PATH must be defined
#endif

#ifndef DECLARED_LIBRARY_DIRS
#error DECLARED_LIBRARY_DIRS must be defined
#endif

#ifndef AUTO_STATIC_EXEC_BIN
#define AUTO_STATIC_EXEC_BIN 0
#endif

#ifndef STATIC_EXEC_LIBRARY_DIR
#error STATIC_EXEC_LIBRARY_DIR must be defined
#endif

extern char **environ;

static int copy(char *output, size_t size, const char *input) {
    size_t length = strlen(input);
    if (length >= size) return 0;
    memcpy(output, input, length + 1);
    return 1;
}

static int append(char *output, size_t size, const char *input) {
    size_t used = strlen(output);
    return used < size && copy(output + used, size - used, input);
}

static int join(char *output, size_t size, const char *left, const char *right) {
    if (!copy(output, size, left) || !append(output, size, "/")) return 0;
    return append(output, size, right);
}

static int parent(char *path) {
    char *slash = strrchr(path, '/');
    if (!slash) return 0;
    if (slash == path) slash[1] = '\0';
    else *slash = '\0';
    return 1;
}

static int invocation_path(char *output, size_t size, const char *argv0) {
    if (argv0[0] == '/') return copy(output, size, argv0);
    char cwd[PATH_MAX];
    return getcwd(cwd, sizeof(cwd)) && join(output, size, cwd, argv0);
}

static int relocated_library_path(char *output, size_t size, const char *sysroot) {
    const char *cursor = DECLARED_LIBRARY_DIRS;
    output[0] = '\0';
    while (*cursor) {
        const char *separator = strchr(cursor, ':');
        size_t length = separator ? (size_t)(separator - cursor) : strlen(cursor);
        if (length == 0 || length >= PATH_MAX) return 0;
        char relative[PATH_MAX];
        memcpy(relative, cursor, length);
        relative[length] = '\0';
        if (*output && !append(output, size, ":")) return 0;
        if (!append(output, size, sysroot) || !append(output, size, "/") ||
            !append(output, size, relative)) return 0;
        if (!separator) break;
        cursor = separator + 1;
    }
    return *output != '\0';
}

static void report(const char *message, const char *detail) {
    static const char prefix[] = "hermetic Rust tool wrapper: ";
    write(STDERR_FILENO, prefix, sizeof(prefix) - 1);
    write(STDERR_FILENO, message, strlen(message));
    if (detail) write(STDERR_FILENO, detail, strlen(detail));
    write(STDERR_FILENO, "\n", 1);
}

static int exec_config_path(const char *path) {
    const char *output = strstr(path, "bazel-out/");
    if (!output) return 0;
    const char *bin = strstr(output, "/bin/");
    if (!bin) return 0;
    const char *exec = strstr(output, "-exec");
    return exec && exec < bin;
}

enum invocation_kind {
    INVOCATION_BINARY = 1,
    INVOCATION_PROC_MACRO = 2,
    INVOCATION_EXEC_OUTPUT = 4,
    INVOCATION_DYNAMIC_EXEC_TOOL = 32,
};

static char *read_response_file(const char *path) {
    int fd = open(path, O_RDONLY | O_CLOEXEC);
    struct stat stat_buffer;
    if (fd < 0 || fstat(fd, &stat_buffer) != 0 ||
        stat_buffer.st_size < 0 || stat_buffer.st_size > 16 * 1024 * 1024) {
        if (fd >= 0) close(fd);
        return NULL;
    }
    size_t size = (size_t)stat_buffer.st_size;
    char *content = malloc(size + 1);
    if (!content) {
        close(fd);
        return NULL;
    }
    size_t used = 0;
    while (used < size) {
        ssize_t count = read(fd, content + used, size - used);
        if (count > 0) used += (size_t)count;
        else if (count < 0 && errno == EINTR) continue;
        else break;
    }
    close(fd);
    if (used != size) {
        free(content);
        return NULL;
    }
    content[size] = '\0';
    return content;
}

static int classify_token(const char *token, const char *next) {
    int kind = 0;
    if (!strcmp(token, "--crate-type=bin") ||
        (!strcmp(token, "--crate-type") && next && !strcmp(next, "bin"))) {
        kind |= INVOCATION_BINARY;
    }
    if (!strcmp(token, "--crate-type=proc-macro") ||
        (!strcmp(token, "--crate-type") && next && !strcmp(next, "proc-macro"))) {
        kind |= INVOCATION_PROC_MACRO;
    }
    if (!strcmp(token, "--cfg=ddtrace_dynamic_exec_tool") ||
        (!strcmp(token, "--cfg") && next && !strcmp(next, "ddtrace_dynamic_exec_tool"))) {
        kind |= INVOCATION_DYNAMIC_EXEC_TOOL;
    }
    if ((!strcmp(token, "-o") || !strcmp(token, "--out-dir")) && next) {
        if (exec_config_path(next)) kind |= INVOCATION_EXEC_OUTPUT;
    } else if (!strncmp(token, "--out-dir=", sizeof("--out-dir=") - 1)) {
        if (exec_config_path(token + sizeof("--out-dir=") - 1)) {
            kind |= INVOCATION_EXEC_OUTPUT;
        }
    }
    return kind;
}

static int classify_response_file(const char *path) {
    char *content = read_response_file(path);
    if (!content) return 0;
    int kind = 0;
    char *save = NULL;
    char *token = strtok_r(content, " \t\r\n", &save);
    while (token) {
        char *next = strtok_r(NULL, " \t\r\n", &save);
        kind |= classify_token(token, next);
        token = next;
    }
    free(content);
    return kind;
}

static int classify_invocation(int argc, char **argv) {
    int kind = 0;
    for (int index = 1; index < argc; ++index) {
        const char *arg = argv[index];
        if (arg[0] == '@' && arg[1]) {
            kind |= classify_response_file(arg + 1);
        } else {
            const char *next = index + 1 < argc ? argv[index + 1] : NULL;
            kind |= classify_token(arg, next);
        }
    }
    return kind;
}

static int write_all(int fd, const char *buffer, size_t size) {
    while (size) {
        ssize_t count = write(fd, buffer, size);
        if (count > 0) {
            buffer += count;
            size -= (size_t)count;
        } else if (count < 0 && errno == EINTR) {
            continue;
        } else {
            return 0;
        }
    }
    return 1;
}

static int static_response_file(const char *source, char *argument, size_t argument_size) {
    static const char needle[] = "--codegen=link-arg=-Wl,--start-group";
    static const char force_static[] = "--codegen=link-arg=-Wl,-Bstatic\n";
    int source_fd = open(source, O_RDONLY | O_CLOEXEC);
    struct stat stat_buffer;
    if (source_fd < 0 || fstat(source_fd, &stat_buffer) != 0 ||
        stat_buffer.st_size < 0 || stat_buffer.st_size > 16 * 1024 * 1024) {
        if (source_fd >= 0) close(source_fd);
        return 0;
    }
    size_t size = (size_t)stat_buffer.st_size;
    char *content = malloc(size + 1);
    if (!content) {
        close(source_fd);
        return 0;
    }
    size_t used = 0;
    while (used < size) {
        ssize_t count = read(source_fd, content + used, size - used);
        if (count > 0) used += (size_t)count;
        else if (count < 0 && errno == EINTR) continue;
        else break;
    }
    close(source_fd);
    content[size] = '\0';
    char *insertion = used == size ? strstr(content, needle) : NULL;
    if (!insertion) {
        free(content);
        return 0;
    }

    char temporary[] = "/tmp/dd-rustc-static-XXXXXX";
    int output_fd = mkstemp(temporary);
    if (output_fd < 0) {
        free(content);
        return 0;
    }
    unlink(temporary);
    size_t prefix = (size_t)(insertion - content);
    int ok = write_all(output_fd, content, prefix) &&
             write_all(output_fd, force_static, sizeof(force_static) - 1) &&
             write_all(output_fd, insertion, size - prefix) &&
             lseek(output_fd, 0, SEEK_SET) == 0 &&
             snprintf(argument, argument_size, "@/proc/self/fd/%d", output_fd) > 0;
    free(content);
    if (!ok) close(output_fd);
    return ok;
}

static int validate_runtime(const char *loader, const char *library_path,
                            const char *raw_tool, const char *sysroot) {
    int output_pipe[2];
    if (pipe(output_pipe) != 0) return 0;
    pid_t child = fork();
    if (child < 0) {
        close(output_pipe[0]);
        close(output_pipe[1]);
        return 0;
    }
    if (child == 0) {
        close(output_pipe[0]);
        if (dup2(output_pipe[1], STDOUT_FILENO) < 0 ||
            dup2(output_pipe[1], STDERR_FILENO) < 0) {
            _exit(127);
        }
        close(output_pipe[1]);
        char *const list_argv[] = {
            (char *)loader,
            "--inhibit-cache",
            "--library-path",
            (char *)library_path,
            "--list",
            (char *)raw_tool,
            NULL,
        };
        execve(loader, list_argv, environ);
        _exit(127);
    }
    close(output_pipe[1]);
    char output[PATH_MAX * 32];
    size_t used = 0;
    for (;;) {
        ssize_t count = read(output_pipe[0], output + used, sizeof(output) - used - 1);
        if (count > 0) {
            used += (size_t)count;
            if (used == sizeof(output) - 1) break;
        } else if (count < 0 && errno == EINTR) {
            continue;
        } else {
            break;
        }
    }
    close(output_pipe[0]);
    int status = 0;
    if (waitpid(child, &status, 0) != child || !WIFEXITED(status) || WEXITSTATUS(status) != 0) {
        return 0;
    }
    output[used] = '\0';
    size_t sysroot_length = strlen(sysroot);
    char *cursor = output;
    while ((cursor = strstr(cursor, "=>")) != NULL) {
        cursor += 2;
        while (*cursor == ' ' || *cursor == '\t') ++cursor;
        if (!strncmp(cursor, "not found", sizeof("not found") - 1) ||
            strncmp(cursor, sysroot, sysroot_length) || cursor[sysroot_length] != '/') {
            return 0;
        }
    }
    return 1;
}

int main(int argc, char **argv) {
    char wrapper[PATH_MAX];
    char sysroot[PATH_MAX];
    char loader[PATH_MAX];
    char raw_tool[PATH_MAX];
    char library_path[PATH_MAX * 32];

    int resolved = getcwd(sysroot, sizeof(sysroot)) &&
                   join(loader, sizeof(loader), sysroot, EXEC_LOADER_PATH) &&
                   join(raw_tool, sizeof(raw_tool), sysroot, RAW_TOOL_PATH) &&
                   relocated_library_path(library_path, sizeof(library_path), sysroot) &&
                   access(loader, X_OK) == 0 && access(raw_tool, X_OK) == 0;
    if (!resolved &&
        (!invocation_path(wrapper, sizeof(wrapper), argv[0]) ||
         !copy(sysroot, sizeof(sysroot), wrapper) ||
         !parent(sysroot) || !parent(sysroot) ||
         !join(loader, sizeof(loader), sysroot, EXEC_LOADER_PATH) ||
         !join(raw_tool, sizeof(raw_tool), sysroot, RAW_TOOL_PATH) ||
         !relocated_library_path(library_path, sizeof(library_path), sysroot))) {
        report("cannot resolve the declared toolchain closure for ", argv[0]);
        return 127;
    }
    if (access(loader, X_OK) != 0 || access(raw_tool, X_OK) != 0) {
        report("missing declared loader or raw tool: ", strerror(errno));
        return 127;
    }

    unsetenv("LD_AUDIT");
    unsetenv("LD_DEBUG");
    unsetenv("LD_LIBRARY_PATH");
    unsetenv("LD_PRELOAD");

    if (!validate_runtime(loader, library_path, raw_tool, sysroot)) {
        report("a compiler dependency resolved outside the declared runtime closure", NULL);
        return 127;
    }

    int invocation = classify_invocation(argc, argv);
    int make_static = AUTO_STATIC_EXEC_BIN &&
                      (invocation & INVOCATION_BINARY) &&
                      (invocation & INVOCATION_EXEC_OUTPUT) &&
                      !(invocation & INVOCATION_DYNAMIC_EXEC_TOOL);
    int make_proc_macro = invocation & INVOCATION_PROC_MACRO;
    int needs_exec_runtime_link = make_static || make_proc_macro;
    char rewritten_response[PATH_MAX];
    rewritten_response[0] = '\0';
    char **exec_argv = calloc((size_t)argc + 16, sizeof(char *));
    if (!exec_argv) return 127;
    int offset = 0;
    exec_argv[offset++] = loader;
    exec_argv[offset++] = "--inhibit-cache";
    exec_argv[offset++] = "--library-path";
    exec_argv[offset++] = library_path;
    exec_argv[offset++] = "--argv0";
    exec_argv[offset++] = raw_tool;
    exec_argv[offset++] = raw_tool;
    // Execution libraries take precedence over target adapters passed by the
    // Rust toolchain, preserving proc-macro and static host-tool linkage.
    static char native_library_dir[PATH_MAX + 10];
    if (needs_exec_runtime_link) {
        if (!copy(native_library_dir, sizeof(native_library_dir), "-Lnative=") ||
            !append(native_library_dir, sizeof(native_library_dir), STATIC_EXEC_LIBRARY_DIR)) {
            report("static execution library path is too long", NULL);
            return 127;
        }
        exec_argv[offset++] = native_library_dir;
    }
    for (int index = 1; index < argc; ++index) {
        if (make_static && !strcmp(argv[index], "--codegen=link-arg=-Wl,--start-group")) {
            exec_argv[offset++] = "--codegen=link-arg=-Wl,-Bstatic";
        }
        if (make_static && !rewritten_response[0] && argv[index][0] == '@' && argv[index][1] &&
            static_response_file(argv[index] + 1, rewritten_response, sizeof(rewritten_response))) {
            exec_argv[offset++] = rewritten_response;
        } else {
            exec_argv[offset++] = argv[index];
        }
    }
    if (make_static) {
        exec_argv[offset++] = "-Ctarget-feature=+crt-static";
        exec_argv[offset++] = "-Crelocation-model=static";
        exec_argv[offset++] = "-Clink-arg=-Wl,--no-dynamic-linker";
        exec_argv[offset++] = "-Clink-arg=-Wl,--no-pie";
        exec_argv[offset++] = "-Clink-arg=-static";
        exec_argv[offset++] = "-Clink-arg=-Wl,-Bstatic";
    }
    if (make_proc_macro) exec_argv[offset++] = "-Clink-arg=-Wl,-z,nodefaultlib";
    exec_argv[offset] = NULL;
    execve(loader, exec_argv, environ);
    report("exec failed: ", strerror(errno));
    return 127;
}
