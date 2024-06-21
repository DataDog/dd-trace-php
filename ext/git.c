#include "git.h"
#include "configuration.h"
#include "ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void cache_git_metadata(zend_string* commit_sha, zend_string* repository_url) {
    DDTRACE_G(git_metadata) = (ddtrace_git_metadata) {
            .commit_sha = zend_string_copy(commit_sha),
            .repository_url = zend_string_copy(repository_url),
    };
}

static bool add_git_info(zval* meta, ddtrace_git_metadata git_metadata, bool is_root_span, bool cache) {
    if (git_metadata.commit_sha && git_metadata.repository_url &&
        ZSTR_LEN(git_metadata.commit_sha) > 0 && ZSTR_LEN(git_metadata.repository_url) > 0) {
        add_assoc_str(meta, "git.commit.sha", git_metadata.commit_sha);
        add_assoc_str(meta, "git.repository.url", git_metadata.repository_url);

        if (is_root_span) {
            add_assoc_str(meta, "_dd.git.commit.sha", git_metadata.commit_sha);
            add_assoc_str(meta, "_dd.git.repository.url", git_metadata.repository_url);
        }

        if (cache) {
            cache_git_metadata(git_metadata.commit_sha, git_metadata.repository_url);
        }

        return true;
    }

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
    const char* git_commit_sha_command = "git rev-parse HEAD";
    const char* git_repository_url_command = "git config --get remote.origin.url";

    FILE* git_commit_sha_pipe = popen(git_commit_sha_command, "r");
    FILE* git_repository_url_pipe = popen(git_repository_url_command, "r");
    if (!git_commit_sha_pipe || !git_repository_url_pipe) {
        if (git_commit_sha_pipe) pclose(git_commit_sha_pipe);
        if (git_repository_url_pipe) pclose(git_repository_url_pipe);
        return false;
    }

    char git_commit_sha[41] = {0};
    char git_repository_url[256] = {0};
    if (!fgets(git_commit_sha, sizeof(git_commit_sha), git_commit_sha_pipe) ||
        !fgets(git_repository_url, sizeof(git_repository_url), git_repository_url_pipe)) {
        pclose(git_commit_sha_pipe);
        pclose(git_repository_url_pipe);
        return false;
    }

    pclose(git_commit_sha_pipe);
    pclose(git_repository_url_pipe);

    zend_string* zs_git_commit_sha = zend_string_init(git_commit_sha, strlen(git_commit_sha), 0);
    zend_string* zs_git_repository_url = zend_string_init(git_repository_url, strlen(git_repository_url), 0);
    normalize_string(zs_git_commit_sha);
    normalize_string(zs_git_repository_url);
    bool result = add_git_info(meta, (ddtrace_git_metadata){zs_git_commit_sha, zs_git_repository_url}, is_root_span, true);

    zend_string_release(zs_git_commit_sha);
    zend_string_release(zs_git_repository_url);

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
