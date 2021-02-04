#include "datadog/container_id.h"

#include <ctype.h>
#include <stdio.h>
#include <string.h>
#include <unistd.h>

#include "datadog/string.h"

#define CGROUP_FILE "/proc/self/cgroup"
#define CONTAINER_ID_LEN 64

struct dd_target {
    const char *name;
    size_t len;
};
typedef struct dd_target dd_target;

dd_target dd_targets[] = {
    // Example Docker
    // 13:name=systemd:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860
    {":/docker/", sizeof(":/docker/") - 1},
    // Example Kubernetes
    // 11:perf_event:/kubepods/something/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1
    {":/kubepods/", sizeof(":/kubepods/") - 1},
    // Example ECS
    // 9:perf_event:/ecs/user-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce
    // Example Fargate
    // 11:something:/ecs/5a081c13-b8cf-4801-b427-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da
    {":/ecs/", sizeof(":/ecs/") - 1},
};
size_t dd_targets_len = sizeof dd_targets / sizeof dd_targets[0];

datadog_string *dd_extract_id(char *pos) {
    size_t len = strlen(pos);
    char *end = pos + len - 1;
    while (end > pos && isspace(end[0])) {
        end--;
        len--;
    }
    if (len < CONTAINER_ID_LEN) {
        return NULL;
    }

    char id[CONTAINER_ID_LEN + 1] = {0};
    for (size_t i = CONTAINER_ID_LEN; i > 0; i--) {
        char c = end[0];
        // [^a-f0-9]
        if (!(c >= 'a' && c <= 'f') && !(c >= '0' && c <= '9')) {
            return NULL;
        }
        id[i - 1] = c;
        end--;
    }
    id[CONTAINER_ID_LEN] = '\0';
    return datadog_string_init(id, CONTAINER_ID_LEN);
}

char *dd_find_target(const char *line) {
    for (size_t i = 0; i < dd_targets_len; ++i) {
        char *pos = strstr(line, dd_targets[i].name);
        if (pos) {
            return pos + dd_targets[i].len;
        }
    }
    return NULL;
}

datadog_string *datadog_container_id(const char *file) {
    const char *cgroup_file = file != NULL ? file : CGROUP_FILE;
    if (access(cgroup_file, F_OK | R_OK) != 0) {
        return NULL;
    }

    FILE *fp;
    char line[1024];

    fp = fopen(cgroup_file, "r");
    if (fp == NULL) {
        return NULL;
    }

    while (!feof(fp)) {
        if (fgets(line, sizeof line, fp) != NULL) {
            char *pos = dd_find_target(line);
            if (pos) {
                datadog_string *id = dd_extract_id(pos);
                if (id) {
                    fclose(fp);
                    return id;
                }
            }
        }
    }
    fclose(fp);

    return NULL;
}
