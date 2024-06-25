#include "git.h"
#include "configuration.h"
#include "ddtrace.h"
#include <string.h>
#include <components/log/log.h>

#ifdef PHP_WIN32
#include <direct.h>
#define getcwd _getcwd
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

int remove_trailing_newline(char* str) {
    size_t len = strlen(str);
    while (len > 0 && (str[len - 1] == '\n' || str[len - 1] == '\r')) {
        len--;
    }
    str[len] = '\0';
    return len;
}

void normalize_string(zend_string* str) {
    ZSTR_LEN(str) = remove_trailing_newline(ZSTR_VAL(str));
}


zend_string* read_git_file(char* path) {
    remove_trailing_newline(path);

    FILE* file = fopen(path, "r");
    if (!file) {
        LOG(DEBUG, "Failed to open file: %s", path);
        return NULL;
    }

    char buffer[256];
    size_t len = fread(buffer, 1, sizeof(buffer) - 1, file);
    fclose(file);

    if (len == 0) {
        LOG(DEBUG, "Failed to read from file: %s", path);
        return NULL;
    }

    buffer[len] = '\0';
    len = remove_trailing_newline(buffer);
    return zend_string_init(buffer, len, 0);
}

zend_string* get_commit_sha(const char* git_dir) {
    char head_path[PATH_MAX];
    snprintf(head_path, sizeof(head_path), "%s/HEAD", git_dir);

    zend_string* head_content = read_git_file(head_path);
    if (!head_content) {
        return NULL;
    }

    const char* ref_prefix = "ref: ";
    if (strncmp(ZSTR_VAL(head_content), ref_prefix, strlen(ref_prefix)) == 0) {
        char ref_path[PATH_MAX];
        snprintf(ref_path, sizeof(ref_path), "%s/%s", git_dir, ZSTR_VAL(head_content) + strlen(ref_prefix));
        zend_string_release(head_content);
        return read_git_file(ref_path);
    }

    return head_content;
}

zend_string* get_repository_url(const char* git_dir) {
    char config_path[PATH_MAX];
    snprintf(config_path, sizeof(config_path), "%s/config", git_dir);

    FILE* file = fopen(config_path, "r");
    if (!file) {
        LOG(DEBUG, "Failed to open file: %s", config_path);
        return NULL;
    }

    char buffer[256];
    zend_string* result = NULL;

    while (fgets(buffer, sizeof(buffer), file)) {
        if (strncmp(buffer, "[remote \"origin\"]", 17) == 0) {
            while (fgets(buffer, sizeof(buffer), file)) {
                if (buffer[0] == '[') break;
                const char* url_prefix = "url = ";
                char* url = strstr(buffer, url_prefix);
                if (url) {
                    result = zend_string_init(url + strlen(url_prefix), strlen(url) - strlen(url_prefix), 0);
                    normalize_string(result);
                    break;
                }
            }
            break;
        }
    }

    fclose(file);
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
    LOG(DEBUG, "Adding git metadata");
    if (commit_sha && repository_url && ZSTR_LEN(commit_sha) > 0 && ZSTR_LEN(repository_url) > 0) {
        removeCredentials(repository_url);
        LOG(DEBUG, "Git commit sha: %s", ZSTR_VAL(commit_sha));
        LOG(DEBUG, "Git repository url: %s", ZSTR_VAL(repository_url));

        if (is_root_span) {
            LOG(DEBUG, "Adding git metadata to root span");
            add_assoc_str(meta, "_dd.git.repository_url", zend_string_copy(repository_url));
            add_assoc_str(meta, "_dd.git.commit.sha", zend_string_copy(commit_sha));
        } else {
            LOG(DEBUG, "Adding git metadata to span");
            add_assoc_str(meta, "git.repository_url", zend_string_copy(repository_url));
            add_assoc_str(meta, "git.commit.sha", zend_string_copy(commit_sha));
        }

        if (cache) {
            LOG(DEBUG, "Caching git metadata");
            cache_git_metadata(commit_sha, repository_url);
        }

        return true;
    }
    LOG(DEBUG, "Git metadata is invalid");

    return false;
}

bool inject_from_env(zval* meta, bool is_root_span) {
    LOG(DEBUG, "Injecting from environment variables...");
    return add_git_info(meta, get_DD_GIT_COMMIT_SHA(), get_DD_GIT_REPOSITORY_URL(), is_root_span, true);
}

bool inject_from_global_tags(zval* meta, bool is_root_span) {
    LOG(DEBUG, "Injecting from global tags...");
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

bool inject_from_git_files(zval* meta, bool is_root_span) {
    LOG(DEBUG, "Injecting from git files...");
    char cwd[PATH_MAX];
    if (!getcwd(cwd, sizeof(cwd))) {
        LOG(DEBUG, "Failed to get current working directory");
        return false;
    }
    LOG(DEBUG, "Current working directory: %s", cwd);

    char git_dir[PATH_MAX];
    snprintf(git_dir, sizeof(git_dir), "%s/.git", cwd);

    zend_string* git_commit_sha = get_commit_sha(git_dir);
    zend_string* git_repository_url = get_repository_url(git_dir);

    if (!git_commit_sha || !git_repository_url) {
        if (git_commit_sha) zend_string_release(git_commit_sha);
        if (git_repository_url) zend_string_release(git_repository_url);
        LOG(DEBUG, "Failed to read git files");
        return false;
    }

    bool result = add_git_info(meta, git_commit_sha, git_repository_url, is_root_span, true);

    zend_string_release(git_commit_sha);
    zend_string_release(git_repository_url);

    return result;
}

void ddtrace_inject_git_metadata(zval* meta, bool is_root_span) {
    ddtrace_git_metadata git_metadata = DDTRACE_G(git_metadata);
    LOG(DEBUG, "Called once: %d", git_metadata.called_once);
    if (git_metadata.called_once) {
        add_git_info(meta, git_metadata.commit_sha, git_metadata.repository_url, is_root_span, false);
        return;
    }

    LOG(DEBUG, "Setting called once to true");
    DDTRACE_G(git_metadata).called_once = true;

    if (inject_from_env(meta, is_root_span) ||
        inject_from_global_tags(meta, is_root_span) ||
        inject_from_git_files(meta, is_root_span)) {
        return;
    }
}
