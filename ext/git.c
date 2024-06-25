#include "git.h"
#include "configuration.h"
#include "ddtrace.h"
#include <string.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

zend_string* execute_command(char* command) {
    FILE* pipe = popen(command, "r");
    if (!pipe) {
        return NULL;
    }

    char buffer[128];
    zend_string* result = NULL;
    while (!feof(pipe)) {
        if (fgets(buffer, 128, pipe) != NULL) {
            if (result) {
                result = zend_string_extend(result, ZSTR_LEN(result) + strlen(buffer), 0);
                memcpy(ZSTR_VAL(result) + ZSTR_LEN(result), buffer, strlen(buffer));
                ZSTR_VAL(result)[ZSTR_LEN(result)] = '\0';
            } else {
                result = zend_string_init(buffer, strlen(buffer), 0);
            }
        }
    }

    pclose(pipe);
    return result;
}

void removeCredentials(zend_string* repo_url) {
    char* url = ZSTR_VAL(repo_url);
    char* at = strchr(url, '@');
    if (at != NULL) {
        char* start = strstr(url, "://");
        if (start != NULL) {
            start += 3; // Move pointer past "://"
            size_t remaining_length = strlen(at + 1);
            memmove(start, at + 1, remaining_length + 1);
            ZSTR_LEN(repo_url) = (start - url) + remaining_length;
        }
    }
}

void cache_git_metadata(zend_string* commit_sha, zend_string* repository_url) {
    if (DDTRACE_G(git_metadata).commit_sha) {
        zend_string_release(DDTRACE_G(git_metadata).commit_sha);
    }

    if (DDTRACE_G(git_metadata).repository_url) {
        zend_string_release(DDTRACE_G(git_metadata).repository_url);
    }

    DDTRACE_G(git_metadata).commit_sha = zend_string_copy(commit_sha);
    DDTRACE_G(git_metadata).repository_url = zend_string_copy(repository_url);
}

static bool add_git_info(zval* meta, zend_string* commit_sha, zend_string* repository_url, bool is_root_span, bool cache) {
    if (commit_sha && repository_url && ZSTR_LEN(commit_sha) > 0 && ZSTR_LEN(repository_url) > 0) {
        removeCredentials(repository_url);

        if (is_root_span) {
            add_assoc_str(meta, "_dd.git.commit.sha", zend_string_copy(commit_sha));
            add_assoc_str(meta, "_dd.git.repository_url", zend_string_copy(repository_url));
        } else {
            add_assoc_str(meta, "git.commit.sha", zend_string_copy(commit_sha));
            add_assoc_str(meta, "git.repository_url", zend_string_copy(repository_url));
        }

        if (cache) {
            cache_git_metadata(commit_sha, repository_url);
        }

        return true;
    }

    return false;
}

bool inject_from_env(zval* meta, bool is_root_span) {
    return add_git_info(meta, get_DD_GIT_COMMIT_SHA(), get_DD_GIT_REPOSITORY_URL(), is_root_span, true);
}

bool inject_from_global_tags(zval* meta, bool is_root_span) {
    zend_array* global_tags = get_DD_TAGS();
    bool success = false;

    if (global_tags) {
        zval* git_commit_sha = zend_hash_str_find(global_tags, ZEND_STRL("git.commit.sha"));
        zval* git_repository_url = zend_hash_str_find(global_tags, ZEND_STRL("git.repository_url"));

        if (git_commit_sha && git_repository_url && Z_TYPE_P(git_commit_sha) == IS_STRING &&
            Z_TYPE_P(git_repository_url) == IS_STRING) {
            success = add_git_info(meta, Z_STR_P(git_commit_sha), Z_STR_P(git_repository_url), is_root_span, true);
        }
    }

    if (success && is_root_span) {
        zend_hash_str_del(Z_ARR_P(meta), ZEND_STRL("git.commit.sha"));
        zend_hash_str_del(Z_ARR_P(meta), ZEND_STRL("git.repository_url"));
    }

    return success;
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
        return false;
    }

    char git_commit_sha_command[PATH_MAX];
    char git_repository_url_command[PATH_MAX];
    snprintf(git_commit_sha_command, sizeof(git_commit_sha_command), "git -C %s rev-parse HEAD 2>/dev/null", cwd);
    snprintf(git_repository_url_command, sizeof(git_repository_url_command), "git -C %s config --get remote.origin.url 2>/dev/null", cwd);

    zend_string* git_commit_sha = execute_command(git_commit_sha_command);
    zend_string* git_repository_url = execute_command(git_repository_url_command);

    if (!git_commit_sha || !git_repository_url) {
        if (git_commit_sha) zend_string_release(git_commit_sha);
        if (git_repository_url) zend_string_release(git_repository_url);
    }

    normalize_string(git_commit_sha);
    normalize_string(git_repository_url);
    bool result = add_git_info(meta, git_commit_sha, git_repository_url, is_root_span, true);

    zend_string_release(git_commit_sha);
    zend_string_release(git_repository_url);

    return result;
}

void ddtrace_inject_git_metadata(zval* meta, bool is_root_span) {
    ddtrace_git_metadata git_metadata = DDTRACE_G(git_metadata);
    if (git_metadata.called_once) {
        add_git_info(meta, git_metadata.commit_sha, git_metadata.repository_url, is_root_span, false);
        return;
    }

    git_metadata.called_once = true;

    if (inject_from_env(meta, is_root_span) ||
        inject_from_global_tags(meta, is_root_span) ||
        inject_from_binary(meta, is_root_span)) {
        return;
    }
}
