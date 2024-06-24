#include "git.h"
#include "configuration.h"
#include "ddtrace.h"
#include <components/log/log.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif



zend_string* execute_command(char* command) {
    LOG(DEBUG, "Executing command: %s", command);
    FILE* pipe = popen(command, "r");
    if (!pipe) {
        LOG(DEBUG, "Failed to open pipe");
        return NULL;
    }

    char buffer[128];
    zend_string* result = NULL;
    while (!feof(pipe)) {
        LOG(DEBUG, "Reading from pipe");
        if (fgets(buffer, 128, pipe) != NULL) {
            LOG(DEBUG, "Read: %s", buffer);
            if (result) {
                LOG(DEBUG, "Extending result");
                result = zend_string_extend(result, ZSTR_LEN(result) + strlen(buffer), 0);
                memcpy(ZSTR_VAL(result) + ZSTR_LEN(result), buffer, strlen(buffer));
                ZSTR_VAL(result)[ZSTR_LEN(result)] = '\0';
            } else {
                LOG(DEBUG, "Initializing result");
                result = zend_string_init(buffer, strlen(buffer), 0);
            }
        }
    }
    LOG(DEBUG, "Closing pipe");

    pclose(pipe);
    return result;
}

void cache_git_metadata(zend_string* commit_sha, zend_string* repository_url) {
    if (DDTRACE_G(git_metadata).commit_sha) {
        zend_string_release(DDTRACE_G(git_metadata).commit_sha);
    }

    if (DDTRACE_G(git_metadata).repository_url) {
        zend_string_release(DDTRACE_G(git_metadata).repository_url);
    }

    DDTRACE_G(git_metadata) = (ddtrace_git_metadata) {
            .commit_sha = zend_string_copy(commit_sha),
            .repository_url = zend_string_copy(repository_url),
    };
}

static bool add_git_info(zval* meta, ddtrace_git_metadata git_metadata, bool is_root_span, bool cache) {
    LOG(DEBUG, "Adding git metadata");
    if (git_metadata.commit_sha && git_metadata.repository_url &&
        ZSTR_LEN(git_metadata.commit_sha) > 0 && ZSTR_LEN(git_metadata.repository_url) > 0) {
        LOG(DEBUG, "Git commit sha: %s", ZSTR_VAL(git_metadata.commit_sha));
        LOG(DEBUG, "Git repository url: %s", ZSTR_VAL(git_metadata.repository_url));
        if (is_root_span) {
            LOG(DEBUG, "Adding git metadata to root span");
            add_assoc_str(meta, "_dd.git.commit.sha", zend_string_copy(git_metadata.commit_sha));
            add_assoc_str(meta, "_dd.git.repository.url", zend_string_copy(git_metadata.repository_url));
        } else {
            LOG(DEBUG, "Adding git metadata to span");
            add_assoc_str(meta, "git.commit.sha", zend_string_copy(git_metadata.commit_sha));
            add_assoc_str(meta, "git.repository.url", zend_string_copy(git_metadata.repository_url));
        }

        if (cache) {
            LOG(DEBUG, "Caching git metadata");
            cache_git_metadata(git_metadata.commit_sha, git_metadata.repository_url);
        }

        return true;
    }
    LOG(DEBUG, "Git metadata is invalid");

    return false;
}

bool inject_from_env(zval* meta, bool is_root_span) {
    zend_string* git_commit_sha = get_DD_GIT_COMMIT_SHA();
    zend_string* git_repository_url = get_DD_GIT_REPOSITORY_URL();
    return add_git_info(meta, (ddtrace_git_metadata){git_commit_sha, git_repository_url}, is_root_span, true);
}

bool inject_from_global_tags(zval* meta, bool is_root_span) {
    zend_array* global_tags = get_DD_TAGS();
    if (global_tags) {
        zval* git_commit_sha = zend_hash_str_find(global_tags, ZEND_STRL("git.commit.sha"));
        zval* git_repository_url = zend_hash_str_find(global_tags, ZEND_STRL("git.repository.url"));

        if (git_commit_sha && git_repository_url && Z_TYPE_P(git_commit_sha) == IS_STRING &&
            Z_TYPE_P(git_repository_url) == IS_STRING) {
            return add_git_info(meta, (ddtrace_git_metadata){Z_STR_P(git_commit_sha), Z_STR_P(git_repository_url)}, is_root_span, true);
        }
    }

    return false;
}

void normalize_string(zend_string* str) {
    size_t len = ZSTR_LEN(str);
    while (len > 0 && (ZSTR_VAL(str)[len - 1] == '\n' || ZSTR_VAL(str)[len - 1] == '\r')) {
        len--;
    }
    ZSTR_VAL(str)[len] = '\0';
    ZSTR_LEN(str) = len;
}

bool inject_from_binary(zval* meta, bool is_root_span) {
    char cwd[PATH_MAX];
    if (!getcwd(cwd, sizeof(cwd))) {
        LOG(DEBUG, "Failed to get current working directory");
        return false;
    }

    LOG(DEBUG, "Current working directory: %s", cwd);

    char git_commit_sha_command[PATH_MAX];
    char git_repository_url_command[PATH_MAX];
    snprintf(git_commit_sha_command, sizeof(git_commit_sha_command), "git -C %s rev-parse HEAD", cwd);
    snprintf(git_repository_url_command, sizeof(git_repository_url_command), "git -C %s config --get remote.origin.url", cwd);

    zend_string* git_commit_sha = execute_command(git_commit_sha_command);
    zend_string* git_repository_url = execute_command(git_repository_url_command);

    if (!git_commit_sha || !git_repository_url) {
        if (git_commit_sha) zend_string_release(git_commit_sha);
        if (git_repository_url) zend_string_release(git_repository_url);
        LOG(DEBUG, "Failed to execute git command");
        return false;
    }

    normalize_string(git_commit_sha);
    normalize_string(git_repository_url);
    bool result = add_git_info(meta, (ddtrace_git_metadata){git_commit_sha, git_repository_url}, is_root_span, true);

    zend_string_release(git_commit_sha);
    zend_string_release(git_repository_url);

    return result;
}

void ddtrace_inject_git_metadata(zval* meta, bool is_root_span) {
    ddtrace_git_metadata git_metadata = DDTRACE_G(git_metadata);
    if (git_metadata.commit_sha || git_metadata.repository_url) {
        add_git_info(meta, git_metadata, is_root_span, false);
        return;
    }

    if (inject_from_env(meta, is_root_span) ||
        inject_from_global_tags(meta, is_root_span) ||
        inject_from_binary(meta, is_root_span)) {
        return;
    }
}
