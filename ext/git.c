#include "git.h"
#include "configuration.h"
#include "ddtrace.h"
#include <SAPI.h>
#include <string.h>

#ifdef PHP_WIN32
#include <direct.h>
#define getcwd _getcwd
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

typedef struct _git_metadata {
    zend_string *property_commit;
    zend_string *property_repository;
} git_metadata_t;

git_metadata_t empty_git_metadata = {NULL, NULL};

int remove_trailing_newline(char *str) {
    size_t len = strlen(str);
    while (len > 0 && (str[len - 1] == '\n' || str[len - 1] == '\r')) {
        len--;
    }
    str[len] = '\0';
    return len;
}

void normalize_string(zend_string *str) {
    ZSTR_LEN(str) = remove_trailing_newline(ZSTR_VAL(str));
}

static char *find_last_dir_separator(const char *path) {
    char *last_forward_slash = strrchr(path, '/');
#ifdef _WIN32
    char *last_back_slash = strrchr(path, '\\');
    return (last_forward_slash > last_back_slash) ? last_forward_slash : last_back_slash;
#else
    return last_forward_slash;
#endif
}

zend_string *read_git_file(const char *path, bool persistent) {
    FILE *file = fopen(path, "r");
    if (!file) {
        return NULL;
    }

    char buffer[256];
    size_t len = fread(buffer, 1, sizeof(buffer) - 1, file);
    fclose(file);

    if (len == 0) {
        return NULL;
    }

    buffer[len] = '\0';
    len = remove_trailing_newline(buffer);
    return zend_string_init(buffer, len, persistent);
}

zend_string *get_commit_sha(const char *git_dir) {
    char head_path[PATH_MAX];
    snprintf(head_path, sizeof(head_path), "%s/HEAD", git_dir);

    zend_string *head_content = read_git_file(head_path, false);
    if (!head_content) {
        return NULL;
    }

    const char *ref_prefix = "ref: ";
    if (strncmp(ZSTR_VAL(head_content), ref_prefix, strlen(ref_prefix)) == 0) {
        char ref_path[PATH_MAX];
        snprintf(ref_path, sizeof(ref_path), "%s/%s", git_dir, ZSTR_VAL(head_content) + strlen(ref_prefix));
        zend_string_release(head_content);
        return read_git_file(ref_path, true);
    }

    return head_content;
}

zend_string *get_repository_url(const char *git_dir) {
    char config_path[PATH_MAX];
    snprintf(config_path, sizeof(config_path), "%s/config", git_dir);

    FILE *file = fopen(config_path, "r");
    if (!file) {
        return NULL;
    }

    char buffer[256];
    zend_string *result = NULL;
    const char *url_prefix = "url = ";

    while (fgets(buffer, sizeof(buffer), file)) {
        if (strncmp(buffer, "[remote \"origin\"]", 17) == 0) {
            while (fgets(buffer, sizeof(buffer), file)) {
                if (buffer[0] == '[') break;
                char *url = strstr(buffer, url_prefix);
                if (url) {
                    result = zend_string_init(url + strlen(url_prefix), strlen(url) - strlen(url_prefix), 1);
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

zend_string *find_git_dir(const char *start_dir) {
    char current_dir[PATH_MAX];
    snprintf(current_dir, sizeof(current_dir), "%s", start_dir);

    while (true) {
        char git_dir[PATH_MAX];
        snprintf(git_dir, sizeof(git_dir), "%s/.git", current_dir);

        if (access(git_dir, F_OK) == 0) {
            return zend_string_init(git_dir, strlen(git_dir), 0);
        } else if (errno == EACCES || errno == EPERM) {
            // If we don't have permission, assume we're in a git dir but can't access the metadata
            return NULL;
        }

        char *last_slash = find_last_dir_separator(current_dir);
        if (!last_slash) {
            return NULL;
        }

        *last_slash = '\0';
    }
}

void remove_credentials(zend_string *repo_url) {
    char *url = ZSTR_VAL(repo_url);
    char *at = strchr(url, '@');
    if (at != NULL) {
        char *start = strstr(url, "://");
        if (start != NULL) {
            start += 3; // Move pointer past "://"
            size_t remaining_length = strlen(at + 1);
            memmove(start, at + 1, remaining_length + 1);
            ZSTR_LEN(repo_url) = (start - url) + remaining_length;
        }
    }
}

bool add_git_info(zend_string *commit_sha, zend_string *repository_url, zval *carrier) {
    if (!commit_sha || !repository_url || ZSTR_LEN(commit_sha) == 0 || ZSTR_LEN(repository_url) == 0) {
        return false;
    }

    remove_credentials(repository_url);
    ddtrace_git_metadata *git_metadata = NULL;
    if (carrier) {
        object_init_ex(carrier, ddtrace_ce_git_metadata);
        git_metadata = (ddtrace_git_metadata *) Z_OBJ_P(carrier);
    } else {
        DDTRACE_G(git_object) = zend_objects_new(ddtrace_ce_git_metadata);
        git_metadata = (ddtrace_git_metadata *) DDTRACE_G(git_object);
    }
    ZVAL_STR_COPY(&git_metadata->property_commit, commit_sha);
    ZVAL_STR_COPY(&git_metadata->property_repository, repository_url);
    return true;
}

bool inject_from_env(zval *carrier) {
    zend_string *commit_sha = get_DD_GIT_COMMIT_SHA();
    zend_string *repository_url = get_DD_GIT_REPOSITORY_URL();
    return add_git_info(commit_sha, repository_url, carrier);
}

bool inject_from_global_tags(zval *carrier) {
    bool success = false;
    zend_array *global_tags = get_DD_TAGS();
    zval *commit_sha = zend_hash_str_find(global_tags, ZEND_STRL("git.commit.sha"));
    zval *repository_url = zend_hash_str_find(global_tags, ZEND_STRL("git.repository_url"));
    if (commit_sha && repository_url && Z_TYPE_P(commit_sha) == IS_STRING && Z_TYPE_P(repository_url) == IS_STRING) {
        success = add_git_info(Z_STR_P(commit_sha), Z_STR_P(repository_url), carrier);
    }
    return success;
}

void use_cached_metadata(git_metadata_t *git_metadata) {
    zend_string_addref(git_metadata->property_commit);
    zend_string_addref(git_metadata->property_repository);
    add_git_info(git_metadata->property_commit, git_metadata->property_repository, NULL);
}

void replace_git_metadata(git_metadata_t *git_metadata, zend_string *commit_sha, zend_string *repository_url) {
    zend_string_release(git_metadata->property_commit);
    zend_string_release(git_metadata->property_repository);
    git_metadata->property_commit = commit_sha;
    git_metadata->property_repository = repository_url;
}

void refresh_git_metadata_if_needed(zend_string *cwd, git_metadata_t *git_metadata) {
    zend_string *git_dir = find_git_dir(ZSTR_VAL(cwd));
    if (!git_dir) {
        return; // Should we replace git_metadata's properties by (NULL, NULL)?
    }

    zend_string *commit_sha = get_commit_sha(ZSTR_VAL(git_dir));
    if (commit_sha && zend_string_equals(git_metadata->property_commit, commit_sha)) {
        zend_string_release(commit_sha);
    } else {
        zend_string *repository_url = get_repository_url(ZSTR_VAL(git_dir));
        replace_git_metadata(git_metadata, commit_sha, repository_url);
    }

    zend_string_release(git_dir);
}

void update_git_metadata(void) {
    zend_string *cwd;
    git_metadata_t *git_metadata;
    ZEND_HASH_FOREACH_STR_KEY_PTR(&DDTRACE_G(git_metadata), cwd, git_metadata) {
        refresh_git_metadata_if_needed(cwd, git_metadata);
    }
    ZEND_HASH_FOREACH_END();
}

void cache_git_metadata(zend_string *cwd, zend_string *commit_sha, zend_string *repository_url) {
    git_metadata_t *git_metadata = pemalloc(sizeof(git_metadata_t), 1);
    git_metadata->property_commit = zend_string_copy(commit_sha);
    git_metadata->property_repository = zend_string_copy(repository_url);
    zend_hash_add_ptr(&DDTRACE_G(git_metadata), cwd, git_metadata);
}

bool inject_from_git_dir() {
    zend_string *cwd = NULL;
    if (SG(options) & SAPI_OPTION_NO_CHDIR) {
        const char *script_filename = SG(request_info).path_translated;
        const char *last_slash = find_last_dir_separator(script_filename);
        if (last_slash) {
            cwd = zend_string_init(script_filename, last_slash - script_filename, 1);
        } else {
            cwd = zend_string_init(ZEND_STRL("."), 1);
        }
    } else {
        char buffer[PATH_MAX];
        if (getcwd(buffer, sizeof(buffer)) == NULL) {
            return false;
        }
        cwd = zend_string_init(buffer, strlen(buffer), 1);
    }

    update_git_metadata();

    git_metadata_t *git_metadata = zend_hash_find_ptr(&DDTRACE_G(git_metadata), cwd);
    if (git_metadata) {
        use_cached_metadata(git_metadata);
        zend_string_release(cwd);
        return true;
    }

    zend_string *git_dir = find_git_dir(ZSTR_VAL(cwd));
    if (!git_dir) {
        zend_string_release(cwd);
        return false;
    }

    zend_string *commit_sha = get_commit_sha(ZSTR_VAL(git_dir));
    zend_string *repository_url = get_repository_url(ZSTR_VAL(git_dir));
    bool success = add_git_info(commit_sha, repository_url, NULL);
    if (success) {
        cache_git_metadata(cwd, commit_sha, repository_url);
    } else {
        zend_string_release(commit_sha);
        zend_string_release(repository_url);
        zend_hash_add_ptr(&DDTRACE_G(git_metadata), cwd, &empty_git_metadata);
    }
    zend_string_release(git_dir);
    zend_string_release(cwd);

    return success;
}

void ddtrace_inject_git_metadata(zval *carrier) {
    if (DDTRACE_G(git_object)) {
        ZVAL_OBJ_COPY(carrier, DDTRACE_G(git_object));
    } else if (inject_from_env(carrier) || inject_from_global_tags(carrier)) {
        return;
    } else if (inject_from_git_dir()) {
        ZVAL_OBJ_COPY(carrier, DDTRACE_G(git_object));
    }
}

void ddtrace_clean_git_metadata(void) {
    git_metadata_t *val;
    ZEND_HASH_FOREACH_PTR(&DDTRACE_G(git_metadata), val) {
        if (val != &empty_git_metadata) {
            zend_string_release(val->property_commit);
            zend_string_release(val->property_repository);
            pefree(val, 1);
        }
    }
    ZEND_HASH_FOREACH_END();
    zend_hash_destroy(&DDTRACE_G(git_metadata));
}

void ddtrace_clean_git_object(void) {
    if (DDTRACE_G(git_object)) {
        ddtrace_git_metadata *git_metadata = (ddtrace_git_metadata *) DDTRACE_G(git_object);
        zend_string_release(Z_STR(git_metadata->property_commit));
        zend_string_release(Z_STR(git_metadata->property_repository));
        zend_object_release(DDTRACE_G(git_object));
        efree(DDTRACE_G(git_object));
        DDTRACE_G(git_object) = NULL;
    }
}
