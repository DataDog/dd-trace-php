#define _GNU_SOURCE

#include <errno.h>
#include <limits.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

enum interpreter {
    DYNAMIC,
    PERL,
    SHELL,
    BUSYBOX,
    LLVM,
};

struct command {
    const char *name;
    const char *program;
    enum interpreter interpreter;
};

static const struct command commands[] = {
    {"aclocal", "usr/bin/aclocal", PERL},
    {"autom4te", "usr/bin/autom4te", PERL},
    {"automake", "usr/bin/automake", PERL},
    {"autoconf", "usr/bin/autoconf", PERL},
    {"autoheader", "usr/bin/autoheader", PERL},
    {"autoreconf", "usr/bin/autoreconf", PERL},
    {"autoscan", "usr/bin/autoscan", PERL},
    {"autoupdate", "usr/bin/autoupdate", PERL},
    {"awk", "bin/busybox.static", BUSYBOX},
    {"bash", "bin/bash", DYNAMIC},
    {"bison", "usr/bin/bison", DYNAMIC},
    {"cmake", "usr/bin/cmake", DYNAMIC},
    {"cmp", "usr/bin/cmp", DYNAMIC},
    {"coreutils", "bin/coreutils", DYNAMIC},
    {"cpack", "usr/bin/cpack", DYNAMIC},
    {"ctest", "usr/bin/ctest", DYNAMIC},
    {"diff", "usr/bin/diff", DYNAMIC},
    {"diff3", "usr/bin/diff3", DYNAMIC},
    {"file", "usr/bin/file", DYNAMIC},
    {"find", "usr/bin/find", DYNAMIC},
    {"grep", "bin/grep", DYNAMIC},
    {"gzip", "bin/gzip", DYNAMIC},
    {"ifnames", "usr/bin/ifnames", PERL},
    {"libtool", "usr/bin/libtool", SHELL},
    {"libtoolize", "usr/bin/libtoolize", SHELL},
    {"clang", "bin/clang", LLVM},
    {"clang++", "bin/clang++", LLVM},
    {"clang-cpp", "bin/clang-cpp", LLVM},
    {"ld.lld", "bin/ld.lld", LLVM},
    {"llvm-ar", "bin/llvm-ar", LLVM},
    {"llvm-nm", "bin/llvm-nm", LLVM},
    {"llvm-objcopy", "bin/llvm-objcopy", LLVM},
    {"llvm-objdump", "bin/llvm-objdump", LLVM},
    {"llvm-ranlib", "bin/llvm-ranlib", LLVM},
    {"llvm-strip", "bin/llvm-strip", LLVM},
    {"m4", "usr/bin/m4", DYNAMIC},
    {"make", "usr/bin/make", DYNAMIC},
    {"patch", "usr/bin/patch", DYNAMIC},
    {"perl", "usr/bin/perl", DYNAMIC},
    {"pkg-config", "usr/bin/pkg-config", DYNAMIC},
    {"pkgconf", "usr/bin/pkgconf", DYNAMIC},
    {"python", "usr/bin/python3", DYNAMIC},
    {"python3", "usr/bin/python3", DYNAMIC},
    {"re2c", "usr/bin/re2c", DYNAMIC},
    {"sed", "bin/sed", DYNAMIC},
    {"sh", "bin/busybox.static", BUSYBOX},
    {"tar", "bin/tar", DYNAMIC},
    {"which", "bin/busybox.static", BUSYBOX},
    {"xargs", "usr/bin/xargs", DYNAMIC},
};

static const char *bin_coreutils[] = {
    "cat", "chmod", "chown", "cp", "date", "dd", "df", "echo", "false",
    "ln", "ls", "mkdir", "mknod", "mktemp", "mv", "nice", "pwd", "rm",
    "rmdir", "sleep", "stat", "sync", "touch", "true", "uname",
};

static const char *usr_coreutils[] = {
    "basename", "cksum", "comm", "cut", "dirname", "du", "env", "expr",
    "head", "id", "install", "mkfifo", "nl", "nproc", "od", "paste",
    "printf", "readlink", "realpath", "sha1sum", "sha256sum", "sort",
    "split", "sum", "tac", "tail", "tee", "test", "timeout", "tr",
    "truncate", "tsort", "tty", "uniq", "wc", "whoami", "yes",
};

static int in_list(const char *name, const char *const *list, size_t count) {
    for (size_t i = 0; i < count; ++i) {
        if (strcmp(name, list[i]) == 0) return 1;
    }
    return 0;
}

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

static void error_command(const char *prefix, const char *command) {
    write(STDERR_FILENO, "hermetic launcher: ", 19);
    write(STDERR_FILENO, prefix, strlen(prefix));
    write(STDERR_FILENO, command, strlen(command));
    write(STDERR_FILENO, "\n", 1);
}

static int set_path_env(const char *name, const char *directory, const char *entry) {
    char value[PATH_MAX];
    return join(value, sizeof(value), directory, entry) && setenv(name, value, 1) == 0;
}

static int command_path(char *output, size_t size, const char *argv0) {
    if (strchr(argv0, '/')) {
        if (argv0[0] == '/') {
            return copy(output, size, argv0);
        }
        char cwd[PATH_MAX];
        return getcwd(cwd, sizeof(cwd)) && join(output, size, cwd, argv0);
    }

    const char *path = getenv("PATH");
    if (!path) return 0;
    char *path_copy = strdup(path);
    if (!path_copy) return 0;
    char *cursor = path_copy;
    char *directory;
    int found = 0;
    while ((directory = strsep(&cursor, ":")) != NULL) {
        if (!*directory) directory = ".";
        char absolute_directory[PATH_MAX];
        if (directory[0] == '/') {
            if (!copy(absolute_directory, sizeof(absolute_directory), directory)) continue;
        } else {
            char cwd[PATH_MAX];
            if (!getcwd(cwd, sizeof(cwd)) || !join(absolute_directory, sizeof(absolute_directory), cwd, directory)) continue;
        }
        if (join(output, size, absolute_directory, argv0) && access(output, X_OK) == 0) {
            found = 1;
            break;
        }
    }
    free(path_copy);
    return found;
}

static int parent(char *path) {
    char *slash = strrchr(path, '/');
    if (!slash) return 0;
    if (slash == path) slash[1] = '\0';
    else *slash = '\0';
    return 1;
}

static int find_root(char *root, size_t size, const char *path, const char *loader) {
    const char *declared = getenv("HERMETIC_TOOLS_ROOT");
    if (declared && *declared) {
        if (declared[0] == '/') return copy(root, size, declared);
        char cwd[PATH_MAX];
        return getcwd(cwd, sizeof(cwd)) && join(root, size, cwd, declared);
    }

    char directory[PATH_MAX];
    if (!copy(directory, sizeof(directory), path) || !parent(directory)) return 0;
    for (int depth = 0; depth < 6; ++depth) {
        char candidate[PATH_MAX];
        char probe[PATH_MAX];
        if (!join(candidate, sizeof(candidate), directory, "root") ||
            !join(probe, sizeof(probe), candidate, loader)) return 0;
        if (access(probe, X_OK) == 0) return copy(root, size, candidate);
        if (!parent(directory)) break;
    }
    return 0;
}

static const struct command *lookup(const char *name, struct command *generated) {
    for (size_t i = 0; i < sizeof(commands) / sizeof(commands[0]); ++i) {
        if (strcmp(name, commands[i].name) == 0) return &commands[i];
    }
    const char *directory = NULL;
    if (in_list(name, bin_coreutils, sizeof(bin_coreutils) / sizeof(bin_coreutils[0]))) directory = "bin";
    if (in_list(name, usr_coreutils, sizeof(usr_coreutils) / sizeof(usr_coreutils[0]))) directory = "usr/bin";
    if (!directory) return NULL;
    char *program = malloc(strlen(directory) + strlen(name) + 2);
    if (!program) return NULL;
    if (!join(program, strlen(directory) + strlen(name) + 2, directory, name)) {
        free(program);
        return NULL;
    }
    generated->name = name;
    generated->program = program;
    generated->interpreter = DYNAMIC;
    return generated;
}

int main(int argc, char **argv) {
#if defined(__x86_64__)
    const char *loader_relative = "lib/ld-musl-x86_64.so.1";
    const char *exec_loader_relative = "lib/x86_64-linux-gnu/ld-linux-x86-64.so.2";
    const char *exec_lib_relative = "lib/x86_64-linux-gnu";
    const char *exec_usr_lib_relative = "usr/lib/x86_64-linux-gnu";
#elif defined(__aarch64__)
    const char *loader_relative = "lib/ld-musl-aarch64.so.1";
    const char *exec_loader_relative = "lib/aarch64-linux-gnu/ld-linux-aarch64.so.1";
    const char *exec_lib_relative = "lib/aarch64-linux-gnu";
    const char *exec_usr_lib_relative = "usr/lib/aarch64-linux-gnu";
#else
#error unsupported execution architecture
#endif
    const char *name = strrchr(argv[0], '/');
    name = name ? name + 1 : argv[0];
    struct command generated;
    const struct command *command = lookup(name, &generated);
    if (!command) {
        error_command("unsupported command ", name);
        return 127;
    }

    char path[PATH_MAX], wrapper_dir[PATH_MAX], root[PATH_MAX], loader[PATH_MAX], program[PATH_MAX];
    char library_path[PATH_MAX * 3], perl5lib[PATH_MAX * 6], shell[PATH_MAX];
    char llvm_program[PATH_MAX], llvm_resource_dir[PATH_MAX], exec_loader[PATH_MAX], exec_library_path[PATH_MAX * 2];
    if (!command_path(path, sizeof(path), argv[0]) ||
        !find_root(root, sizeof(root), path, loader_relative) ||
        !join(loader, sizeof(loader), root, loader_relative) ||
        !join(program, sizeof(program), root, command->program) ||
        !join(shell, sizeof(shell), root, "bin/busybox.static")) {
        error_command("cannot locate runtime root for ", argv[0]);
        return 127;
    }
    if (setenv("HERMETIC_TOOLS_ROOT", root, 1) != 0) return 127;
    /* Resolve the directory Bazel declared in PATH.  The currently executing
     * alias can be in a different configuration directory whose siblings are
     * not action inputs. */
    if (!command_path(wrapper_dir, sizeof(wrapper_dir), "sh") || !parent(wrapper_dir) ||
        !set_path_env("AUTOCONF", wrapper_dir, "autoconf") ||
        !set_path_env("AUTOHEADER", wrapper_dir, "autoheader") ||
        !set_path_env("AUTOM4TE", wrapper_dir, "autom4te") ||
        !set_path_env("AUTOMAKE", wrapper_dir, "automake") ||
        !set_path_env("ACLOCAL", wrapper_dir, "aclocal") ||
        !set_path_env("LIBTOOLIZE", wrapper_dir, "libtoolize") ||
        !set_path_env("MAKE", wrapper_dir, "make") ||
        !set_path_env("M4", wrapper_dir, "m4") ||
        !set_path_env("PERL", wrapper_dir, "perl") ||
        !set_path_env("PYTHON", wrapper_dir, "python3") ||
        !set_path_env("PKG_CONFIG", wrapper_dir, "pkg-config") ||
        !set_path_env("CONFIG_SHELL", wrapper_dir, "sh") ||
        !set_path_env("SHELL", wrapper_dir, "sh") ||
        setenv("COMPILER_PATH", wrapper_dir, 1) != 0 ||
        !set_path_env("trailer_m4", root, "usr/share/autoconf/autoconf/trailer.m4") ||
        !set_path_env("AC_MACRODIR", root, "usr/share/autoconf") ||
        !set_path_env("AUTOM4TE_CFG", root, "usr/share/autoconf/autom4te.cfg") ||
        !set_path_env("ACLOCAL_PATH", root, "usr/share/aclocal") ||
        !set_path_env("ACLOCAL_AUTOMAKE_DIR", root, "usr/share/aclocal-1.17") ||
        !set_path_env("AUTOMAKE_LIBDIR", root, "usr/share/automake-1.17") ||
        !set_path_env("_lt_pkgdatadir", root, "usr/share/libtool")) return 127;
    unsetenv("PYTHONHOME");
    unsetenv("PYTHONPATH");
    unsetenv("PERLLIB");
    unsetenv("PERL5OPT");
    unsetenv("PERL_LOCAL_LIB_ROOT");
    unsetenv("PERL_MB_OPT");
    unsetenv("PERL_MM_OPT");
    setenv("PYTHONNOUSERSITE", "1", 1);
    library_path[0] = '\0';
    if (!append(library_path, sizeof(library_path), root) ||
        !append(library_path, sizeof(library_path), "/lib:") ||
        !append(library_path, sizeof(library_path), root) ||
        !append(library_path, sizeof(library_path), "/usr/lib:") ||
        !append(library_path, sizeof(library_path), root) ||
        !append(library_path, sizeof(library_path), "/usr/lib/perl5/core_perl/CORE")) return 127;
    if (command->interpreter == PERL || strcmp(command->name, "perl") == 0) {
        perl5lib[0] = '\0';
        if (!append(perl5lib, sizeof(perl5lib), root) ||
            !append(perl5lib, sizeof(perl5lib), "/usr/share/autoconf:") ||
            !append(perl5lib, sizeof(perl5lib), root) ||
            !append(perl5lib, sizeof(perl5lib), "/usr/share/automake-1.17:") ||
            !append(perl5lib, sizeof(perl5lib), root) ||
            !append(perl5lib, sizeof(perl5lib), "/usr/lib/perl5/core_perl:") ||
            !append(perl5lib, sizeof(perl5lib), root) ||
            !append(perl5lib, sizeof(perl5lib), "/usr/lib/perl5/vendor_perl:") ||
            !append(perl5lib, sizeof(perl5lib), root) ||
            !append(perl5lib, sizeof(perl5lib), "/usr/share/perl5/core_perl:") ||
            !append(perl5lib, sizeof(perl5lib), root) ||
            !append(perl5lib, sizeof(perl5lib), "/usr/share/perl5/vendor_perl")) return 127;
        if (setenv("PERL5LIB", perl5lib, 1) != 0 ||
            setenv("PERL5OPT", "-MHermeticINC", 1) != 0) return 127;
        char autom4te_perllibdir[PATH_MAX];
        if (!join(autom4te_perllibdir, sizeof(autom4te_perllibdir), root, "usr/share/autoconf")) return 127;
        setenv("autom4te_perllibdir", autom4te_perllibdir, 1);
    }

    char **exec_argv = calloc((size_t)argc + 13, sizeof(char *));
    if (!exec_argv) return 127;
    int offset = 0;
    if (command->interpreter == LLVM) {
        const char *llvm_root = getenv("HERMETIC_LLVM_ROOT");
        const char *exec_root = getenv("HERMETIC_EXEC_RUNTIME_ROOT");
        char exec_lib[PATH_MAX], exec_usr_lib[PATH_MAX];
        if (!llvm_root || !exec_root ||
            !join(llvm_program, sizeof(llvm_program), llvm_root, command->program) ||
            !join(llvm_resource_dir, sizeof(llvm_resource_dir), llvm_root, "lib/clang/20") ||
            !join(exec_loader, sizeof(exec_loader), exec_root, exec_loader_relative) ||
            !join(exec_lib, sizeof(exec_lib), exec_root, exec_lib_relative) ||
            !join(exec_usr_lib, sizeof(exec_usr_lib), exec_root, exec_usr_lib_relative) ||
            !copy(exec_library_path, sizeof(exec_library_path), exec_lib) ||
            !append(exec_library_path, sizeof(exec_library_path), ":") ||
            !append(exec_library_path, sizeof(exec_library_path), exec_usr_lib)) return 127;
        exec_argv[offset++] = exec_loader;
        exec_argv[offset++] = "--library-path";
        exec_argv[offset++] = exec_library_path;
        exec_argv[offset++] = "--argv0";
        exec_argv[offset++] = llvm_program;
        exec_argv[offset++] = llvm_program;
        if (strncmp(command->name, "clang", 5) == 0) {
            exec_argv[offset++] = "-resource-dir";
            exec_argv[offset++] = llvm_resource_dir;
            exec_argv[offset++] = "-no-canonical-prefixes";
            exec_argv[offset++] = "-fintegrated-cc1";
        }
    } else if (command->interpreter == SHELL) {
        exec_argv[offset++] = shell;
        exec_argv[offset++] = "sh";
        exec_argv[offset++] = program;
    } else if (command->interpreter == BUSYBOX) {
        exec_argv[offset++] = shell;
        exec_argv[offset++] = (char *)command->name;
    } else {
        exec_argv[offset++] = loader;
        exec_argv[offset++] = "--library-path";
        exec_argv[offset++] = library_path;
        int cmake_command = strcmp(command->name, "cmake") == 0;
        if (cmake_command) {
            exec_argv[offset++] = "--argv0";
            exec_argv[offset++] = path;
        }
        if (command->interpreter == PERL) {
            if (!join(exec_argv[offset] = malloc(PATH_MAX), PATH_MAX, root, "usr/bin/perl")) return 127;
            exec_argv[++offset] = program;
            ++offset;
        } else {
            exec_argv[offset++] = program;
        }
    }
    for (int i = 1; i < argc; ++i) exec_argv[offset++] = argv[i];
    char make_shell_assignment[PATH_MAX + 7];
    if (strcmp(command->name, "make") == 0) {
        if (!copy(make_shell_assignment, sizeof(make_shell_assignment), "SHELL=") ||
            !append(make_shell_assignment, sizeof(make_shell_assignment), getenv("SHELL"))) return 127;
        /* A command-line assignment overrides CMake's generated
         * `SHELL = /bin/sh`, including for nested try_compile builds. */
        exec_argv[offset++] = make_shell_assignment;
    }
    exec_argv[offset] = NULL;
    execve(exec_argv[0], exec_argv, environ);
    error_command("exec failed: ", exec_argv[0]);
    return 127;
}
