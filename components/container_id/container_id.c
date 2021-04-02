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

typedef datadog_php_container_id_parser dd_parser;

static bool dd_parser_is_valid_line(dd_parser *parser, const char *line) {
    return regexec(&parser->line_regex, line, 0, NULL, 0) == 0;
}

#define LEN_SO_FAR (end - start)

#define TASK_ID_MIN_LEN (32 + 1 + 1)   // [0-9a-f]{32}-[0-9]{1}
#define TASK_ID_MAX_LEN (32 + 1 + 20)  // [0-9a-f]{32}-[0-9]{20}

static bool dd_parser_extract_task_id(dd_parser *parser, char *buf, const char *line) {
    if (regexec(&parser->task_regex, line, 0, NULL, 0) != 0) return false;

    /* Normally we would just use 'regmatch_t' for position matching and
     * extract the desired string from the matched position. But
     * unfortunately if Oniguruma <= 6.9.4 is linked in, the POSIX regex
     * symbols are overridden with Oniguruma flavored ones and 'regmatch_t'
     * will be mangled so we cannot use it here.
     *
     * Ideally we would fall back to extracting the IDs using sscanf(), but
     * since there is no format directive for a minimum or exact field width,
     * sscanf() will often pull out other parts of the cgroup line that are
     * not part of the target ID.
     *
     * That leaves us with our final old-school fallback of traversing the
     * string one character at a time to find start and end of the target ID.
     */
    char *start;
    char *end;
    size_t len;

    start = end = (char *)line;
    len = strlen(line);

    /* Traverse the string to find a task ID with the following pattern:
     *
     * [0-9a-f]{32}-[0-9]{1,20}
     *
     */
    while ((size_t)(start - line + TASK_ID_MIN_LEN) <= len) {
        end = start;

        /* We start off looking for 32 hex chars in a row: [0-9a-f]{32} */
        while (isxdigit(end[0]) && LEN_SO_FAR < 32) {
            end++;
        }

        /* After exactly 32 hex characters, there should be a hyphen: - */
        if (LEN_SO_FAR != 32 || end[0] != '-') {
            start++;
            continue;
        }
        end++;

        /* Finally there should be an unsigned 64-bit int: [0-9]{1,20} */
        while (isdigit(end[0]) && LEN_SO_FAR < TASK_ID_MAX_LEN) {
            end++;
        }

        /* We must capture at least one number. */
        if (LEN_SO_FAR < TASK_ID_MIN_LEN) {
            start++;
            continue;
        }

        /* We have a valid task ID at this point so we can ignore the rest of
         * the line.
         */
        memcpy(buf, start, LEN_SO_FAR);
        buf[LEN_SO_FAR] = '\0';

        return true;
    }

    /* If we made it down here that means our regex pattern matched but we
     * failed to manually extract the ID from the string.
     */
    return false;
}

#define CONTAINER_ID_LEN 64  // [0-9a-f]{64}

static bool dd_parser_extract_container_id(dd_parser *parser, char *buf, const char *line) {
    if (regexec(&parser->container_regex, line, 0, NULL, 0) != 0) return false;

    /* We cannot use 'regmatch_t' for position matching due to the possibility
     * of Oniguruma <= 6.9.4 being linked nor can we use sscanf() (as explained
     * in comments above). So we fall back to traversing the string
     * character-by-character to find the start and end positions of the target
     * ID.
     */
    char *start;
    char *end;
    size_t len;

    start = end = (char *)line;
    len = strlen(line);

    /* Traverse the string to find a container ID with the following pattern:
     *
     * [0-9a-f]{64}
     *
     */
    while ((size_t)(start - line + CONTAINER_ID_LEN) <= len) {
        end = start;

        /* We need exactly 64 hex characters in a row. */
        while (isxdigit(end[0]) && LEN_SO_FAR < CONTAINER_ID_LEN) {
            end++;
        }

        if (LEN_SO_FAR != CONTAINER_ID_LEN) {
            start++;
            continue;
        }

        /* We have a valid container ID at this point so we can ignore the rest
         * of the line.
         */
        memcpy(buf, start, LEN_SO_FAR);
        buf[LEN_SO_FAR] = '\0';

        return true;
    }

    /* If we made it down here that means our regex pattern matched but we
     * failed to manually extract the ID from the string.
     */
    return false;
}

bool datadog_php_container_id_parser_ctor(dd_parser *parser) {
    if (parser == NULL) return false;

    /* According to the in-code docs:
     *
     * "Note that the translate table must either have been initialized by
     * 'regcomp', with a malloc'ed value, or set to NULL before calling
     * 'regfree'."
     * https://elixir.bootlin.com/glibc/latest/source/posix/regex.h#L535
     *
     * For this reason we zero-out all of the 'regex_t' patterns so that we can
     * still call regfree() on them if regcomp() fails to initialize the
     * pattern buffer.
     */
    memset(parser, 0, sizeof *parser);

    int l_res = regcomp(&parser->line_regex, LINE_REGEX, REG_NOSUB);
    int t_res = regcomp(&parser->task_regex, TASK_REGEX, REG_NOSUB);
    int c_res = regcomp(&parser->container_regex, CONTAINER_REGEX, REG_NOSUB);
    if (l_res != 0 || t_res != 0 || c_res != 0) {
        datadog_php_container_id_parser_dtor(parser);
        return false;
    }

    parser->is_valid_line = dd_parser_is_valid_line;
    parser->extract_task_id = dd_parser_extract_task_id;
    parser->extract_container_id = dd_parser_extract_container_id;

    return true;
}

bool datadog_php_container_id_parser_dtor(dd_parser *parser) {
    if (parser == NULL) return false;

    regfree(&parser->container_regex);
    regfree(&parser->task_regex);
    regfree(&parser->line_regex);

    return true;
}

bool datadog_php_container_id_from_file(char *buf, const char *file) {
    FILE *fp;

    if (buf == NULL) return false;

    buf[0] = '\0';

    if (file == NULL || file[0] == '\0' || (fp = fopen(file, "r")) == NULL) return false;

    dd_parser parser;
    if (datadog_php_container_id_parser_ctor(&parser) == false) {
        fclose(fp);
        return false;
    }

    char line[1024];
    while (!feof(fp)) {
        if (fgets(line, sizeof line, fp) == NULL) continue;

        /* Match a valid cgroup line. */
        if (!parser.is_valid_line(&parser, line)) {
            /* This does not look like a valid cgroup line so we skip it. */
            continue;
        }

        /* Match a task ID and fill into buf. */
        if (parser.extract_task_id(&parser, buf, line)) {
            /* We found a task ID which takes precedence over a standard
             * container ID so we can stop scanning the file.
             */
            break;
        }

        /* Match a container ID and fill into empty buf. */
        if (buf[0] == '\0' && parser.extract_container_id(&parser, buf, line)) {
            /* We found a valid container ID but we cannot stop scanning the
             * file because there might be a task ID in the file (as is the
             * case with Fargate 1.4+) and those take precedence over standard
             * container IDs.
             */
            /* break; */
        }
    }

    /* This only fails if parser is NULL. */
    (void)datadog_php_container_id_parser_dtor(&parser);
    fclose(fp);

    return true;
}
