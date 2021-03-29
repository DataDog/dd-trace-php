#include "container_id.h"

#include <regex.h>
#include <stdio.h>
#include <string.h>

#define LINE_REGEX "^[0-9]+:[^:]*:.+$"
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
#define CONTAINER_REGEX "[0-9a-f]{64}"
/* Example of a valid task ID.
 *
 * Fargate 1.4+
 * 1:name=systemd:/ecs/34dc0b5e626f2c5c4c5170e34b10e765-1234567890
 */
#define TASK_REGEX "[0-9a-f]{32}-[0-9]+"

#define MIN_ID_LEN DATADOG_PHP_CONTAINER_ID_MIN_LEN
#define MAX_ID_LEN DATADOG_PHP_CONTAINER_ID_MAX_LEN

static size_t dd_find_id_regex(regex_t *regex, char *buf, const char *line) {
    size_t len;
    regmatch_t pmatch[1];

    if (regexec(regex, line, 1, pmatch, 0) != 0) return 0;

    len = (size_t)pmatch[0].rm_eo - pmatch[0].rm_so;
    if (len < MIN_ID_LEN || len > MAX_ID_LEN) return 0;

    memcpy(buf, line + pmatch[0].rm_so, len);
    buf[len] = '\0';

    return len;
}

void datadog_php_container_id(char *buf, const char *file) {
    FILE *fp;
    char task_buf[MAX_ID_LEN + 1];

    if (buf == NULL) return;

    buf[0] = '\0';
    task_buf[0] = '\0';

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
    int l_res = regcomp(&line_regex, LINE_REGEX, REG_EXTENDED | REG_NOSUB);
    int t_res = regcomp(&task_regex, TASK_REGEX, REG_EXTENDED);
    int c_res = regcomp(&container_regex, CONTAINER_REGEX, REG_EXTENDED);
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

        /* Once we find a task ID we can stop scanning the cgroup file since
         * those take precedence over a standard container ID. In many examples
         * such as Fargate 1.4+, the task ID is on the last line of the file
         * after many lines of valid container IDs. It is for this reason that
         * we must scan the file until the very end even after finding a valid
         * container ID.
         */
        size_t task_len = dd_find_id_regex(&task_regex, task_buf, line);
        if (task_buf[0] != '\0') {
            memcpy(buf, task_buf, task_len + 1);
            break;
        }
        if (buf[0] == '\0') {
            dd_find_id_regex(&container_regex, buf, line);
        }
    }

    regfree(&container_regex);
    regfree(&task_regex);
    regfree(&line_regex);

    fclose(fp);
}
