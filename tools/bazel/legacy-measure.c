/* Measure a production command and all children waited for by its shell.
 * Built with the compiler already present in each release build image. */
#define _GNU_SOURCE
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/resource.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

static double seconds(struct timeval value) {
    return (double)value.tv_sec + (double)value.tv_usec / 1000000.0;
}

static double elapsed(struct timespec start, struct timespec end) {
    return (double)(end.tv_sec - start.tv_sec) +
           (double)(end.tv_nsec - start.tv_nsec) / 1000000000.0;
}

static void json_string(FILE *out, const char *value) {
    fputc('"', out);
    for (const unsigned char *p = (const unsigned char *)value; *p; ++p) {
        if (*p == '"' || *p == '\\') fprintf(out, "\\%c", *p);
        else if (*p < 32) fprintf(out, "\\u%04x", *p);
        else fputc(*p, out);
    }
    fputc('"', out);
}

int main(int argc, char **argv) {
    if (argc < 5) {
        fprintf(stderr, "usage: legacy-measure RECORD CATEGORY ID COMMAND...\n");
        return 2;
    }
    struct timespec start, end;
    struct rusage usage;
    clock_gettime(CLOCK_REALTIME, &start);
    double started_at = (double)start.tv_sec + (double)start.tv_nsec / 1000000000.0;
    clock_gettime(CLOCK_MONOTONIC, &start);
    pid_t child = fork();
    if (child == 0) {
        execvp(argv[4], argv + 4);
        fprintf(stderr, "legacy-measure: exec %s: %s\n", argv[4], strerror(errno));
        _exit(127);
    }
    if (child < 0) {
        perror("legacy-measure: fork");
        return 2;
    }
    int status;
    while (wait4(child, &status, 0, &usage) < 0) {
        if (errno == EINTR) continue;
        perror("legacy-measure: wait4");
        return 2;
    }
    clock_gettime(CLOCK_MONOTONIC, &end);
    int code = WIFEXITED(status) ? WEXITSTATUS(status) :
               WIFSIGNALED(status) ? 128 + WTERMSIG(status) : 2;
    FILE *out = fopen(argv[1], "w");
    if (!out) {
        perror("legacy-measure: record");
        return code ? code : 2;
    }
    fputs("{\"category\":", out); json_string(out, argv[2]);
    fputs(",\"identity\":", out); json_string(out, argv[3]);
    fputs(",\"command\":[", out);
    for (int i = 4; i < argc; ++i) {
        if (i > 4) fputc(',', out);
        json_string(out, argv[i]);
    }
    fprintf(out, "],\"started_at_epoch\":%.6f,\"elapsed_seconds\":%.6f,"
                 "\"cpu_seconds\":%.6f,\"exit_code\":%d}\n",
            started_at, elapsed(start, end),
            seconds(usage.ru_utime) + seconds(usage.ru_stime), code);
    if (fclose(out) != 0) return code ? code : 2;
    return code;
}
