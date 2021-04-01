#include "container_id.h"

#include <ctype.h>
#include <regex.h>
#include <stdio.h>
#include <string.h>

/* The Oniguruma library breaks extended regular expressions (ERE) in POSIX
 * regex (REG_EXTENDED) until 6.9.5-rc1. Simply linking the library '-lonig'
 * will cause the bug to occur. Starting in PHP 7.4, Oniguruma is no longer
 * bundled with ext/mbstring, but is linked in ('-lonig') so we cannot reliably
 * use ERE here. Basic regular expressions (BRE) do not seem to be affected by
 * this bug therefore we used BRE syntax for the container ID pattern matching.
 *
 * https://github.com/kkos/oniguruma/issues/233
 * https://www.php.net/manual/en/mbstring.installation.php
 */
#define LINE_REGEX "^[0-9]\\{1,20\\}:[^:]*:.*$"  // Original ERE: "^[0-9]+:[^:]*:.+$"
/* Examples of some valid container IDs.
 *
 * Docker
 * 13:name=systemd:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860
 *
 * Kubernetes
 * 11:perf_event:/kubepods/something/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1
 *
 * ECS
 * 9:perf_event:/ecs/user-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce
 *
 * Fargate < 1.4
 * 11:something:/ecs/5a081c13-b8cf-4801-b427-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da
 */
#define CONTAINER_REGEX "[0-9a-f]\\{64\\}"  // Original ERE: "[0-9a-f]{64}"
/* Example of a valid task ID.
 *
 * Fargate 1.4+
 * 1:name=systemd:/ecs/34dc0b5e626f2c5c4c5170e34b10e765-1234567890
 */
#define TASK_REGEX "[0-9a-f]\\{32\\}-[0-9]\\{1,20\\}"  // Original ERE: "[0-9a-f]{32}-[0-9]+"

#define MIN_ID_LEN DATADOG_PHP_CONTAINER_ID_MIN_LEN
#define MAX_ID_LEN DATADOG_PHP_CONTAINER_ID_MAX_LEN

static void dd_extract_task_id(char *buf, const char *line) {
    char *pos = (char *)line;
    size_t len = strlen(line);
    unsigned int buf_pos = 0;

    while ((size_t)(pos - line) < len) {
        // [0-9a-f]{32}
        while (isxdigit(pos[0]) && buf_pos < 32) {
            buf[buf_pos++] = pos[0];
            pos++;
        }

        // -
        if (buf_pos != 32 || pos[0] != '-') {
            buf_pos = 0;
            pos++;
            continue;
        }
        buf[buf_pos++] = '-';
        pos++;

        // [0-9]{1,20}
        while (isdigit(pos[0]) && buf_pos < (32 + 1 + 20)) {
            buf[buf_pos++] = pos[0];
            pos++;
        }
        if (buf_pos <= (32 + 1)) {
            buf_pos = 0;
            pos++;
            continue;
        }

        /* We have a valid task ID at this point so we can ignore the rest of
         * the line.
         */
        break;
    }

    buf[buf_pos] = '\0';
}

static void dd_extract_container_id(char *buf, const char *line) {
    char *pos = (char *)line;
    size_t len = strlen(line);
    unsigned int buf_pos = 0;

    // [0-9a-f]{64}
    while ((size_t)(pos - line) < len && buf_pos < 64) {
        if (!isxdigit(pos[0])) {
            buf_pos = 0;
            pos++;
            continue;
        }
        buf[buf_pos++] = pos[0];
        pos++;
    }

    buf[buf_pos] = '\0';
}

void datadog_php_container_id(char *buf, const char *file) {
    FILE *fp;

    if (buf == NULL) return;

    buf[0] = '\0';

    if (file == NULL || file[0] == '\0' || (fp = fopen(file, "r")) == NULL) return;

    /* According to the in-code docs:
     *
     * "Note that the translate table must either have been initialized by
     * 'regcomp', with a malloc'ed value, or set to NULL before calling
     * 'regfree'."
     * https://elixir.bootlin.com/glibc/latest/source/posix/regex.h#L535
     *
     * For this reason we zero-initialize all of the 'regex_t' patterns so that
     * we can still call regfree() on them if regcomp() fails to initialize the
     * pattern buffer.
     */
    regex_t line_regex = {0};
    regex_t task_regex = {0};
    regex_t container_regex = {0};
    int l_res = regcomp(&line_regex, LINE_REGEX, REG_NOSUB);
    int t_res = regcomp(&task_regex, TASK_REGEX, REG_NOSUB);
    int c_res = regcomp(&container_regex, CONTAINER_REGEX, REG_NOSUB);
    if (l_res != 0 || t_res != 0 || c_res != 0) {
        regfree(&container_regex);
        regfree(&task_regex);
        regfree(&line_regex);
        fclose(fp);
        return;
    }

    char line[1024];
    while (!feof(fp)) {
        if (fgets(line, sizeof line, fp) == NULL) continue;

        /* Match a valid cgroup line. */
        if (regexec(&line_regex, line, 0, NULL, 0) != 0) continue;

        /* Normally we could just use 'regmatch_t' to obtain the results from
         * this regex match, but unfortunately if Oniguruma <= 6.9.4 is linked
         * in, the 'regmatch_t' will be mangled so we cannot use it here.
         */
        if (regexec(&task_regex, line, 0, NULL, 0) == 0) {
            dd_extract_task_id(buf, line);
            /* We found a task ID which takes precedence over a standard
             * container ID so we can stop scanning the file.
             */
            break;
        }

        if (buf[0] == '\0' && (regexec(&container_regex, line, 0, NULL, 0) == 0)) {
            dd_extract_container_id(buf, line);
            /* We found a valid container ID but we cannot stop scanning the
             * file because there might be a task ID in the file (as is the
             * case with Fargate 1.4+) and those take precedence over standard
             * container IDs.
             */
            /* break; */
        }
    }

    regfree(&container_regex);
    regfree(&task_regex);
    regfree(&line_regex);

    fclose(fp);
}
