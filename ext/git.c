#include "git.h"
#include "configuration.h"
#include "datadog.h"
#include <SAPI.h>
#include <string.h>

#ifdef PHP_WIN32
#include <direct.h>
#define getcwd _getcwd
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

typedef struct _git_metadata {
    zend_string *property_commit;
    zend_string *property_repository;
} git_metadata_t;

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

zend_string *read_git_file(const char *path) {
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
    return zend_string_init(buffer, len, true);
}

zend_string *get_commit_sha(const char *git_dir) {
    char head_path[PATH_MAX];
    snprintf(head_path, sizeof(head_path), "%s/HEAD", git_dir);

    zend_string *head_content = read_git_file(head_path);
    if (!head_content) {
        return NULL;
    }

    const char *ref_prefix = "ref: ";
    if (strncmp(ZSTR_VAL(head_content), ref_prefix, strlen(ref_prefix)) == 0) {
        char ref_path[PATH_MAX];
        snprintf(ref_path, sizeof(ref_path), "%s/%s", git_dir, ZSTR_VAL(head_content) + strlen(ref_prefix));
        zend_string_release(head_content);
        return read_git_file(ref_path);
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

zend_string* remove_credentials(zend_string *repo_url) {
    char *url = ZSTR_VAL(repo_url);
    char *at = strchr(url, '@');
    if (at != NULL) {
        char *start = strstr(url, "://");
        if (start != NULL) {
            start += 3; // Move pointer past "://"
            size_t remaining_length = strlen(at + 1);
            size_t new_length = (start - url) + remaining_length;

            zend_string *new_url = zend_string_alloc(new_length, 0);
            char *new_url_val = ZSTR_VAL(new_url);

            // Copy the part before "://" and after "@"
            memcpy(new_url_val, url, start - url);
            memcpy(new_url_val + (start - url), at + 1, remaining_length + 1);

            ZSTR_LEN(new_url) = new_length;

            return new_url;
        }
    }

    return zend_string_copy(repo_url);
}

zend_string* get_directory_from_path_translated() {
    const char *path_translated = SG(request_info).path_translated;
    if (path_translated) {
        const char *last_slash = find_last_dir_separator(path_translated);
        if (last_slash) {
            return zend_string_init(path_translated, last_slash - path_translated, 1);
        }
    }
    return zend_string_init(ZEND_STRL("."), 1);
}

zend_string* get_directory_from_script_filename(zval *script_filename) {
    const char *last_slash = find_last_dir_separator(Z_STRVAL_P(script_filename));
    if (last_slash) {
        return zend_string_init(Z_STRVAL_P(script_filename), last_slash - Z_STRVAL_P(script_filename), 1);
    }
    return zend_string_init(ZEND_STRL("."), 1);
}

zend_string* get_directory_from_getcwd() {
    char buffer[PATH_MAX];
    if (getcwd(buffer, sizeof(buffer)) == NULL) {
        return NULL;
    }
    return zend_string_init(buffer, strlen(buffer), 1);
}

zend_string* get_current_working_directory() {
    if (SG(options) & SAPI_OPTION_NO_CHDIR) {
        return get_directory_from_path_translated();
    }

    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
    zval *_server_zv = &PG(http_globals)[TRACK_VARS_SERVER];
    zval *script_filename = zend_hash_str_find(Z_ARRVAL_P(_server_zv), ZEND_STRL("SCRIPT_FILENAME"));

    if (script_filename) {
        return get_directory_from_script_filename(script_filename);
    }

    return get_directory_from_getcwd();
}

void cache_git_metadata(zend_string *cwd, zend_string *commit_sha, zend_string *repository_url) {
    git_metadata_t *git_metadata = pemalloc(sizeof(git_metadata_t), 1);
    git_metadata->property_commit = commit_sha && ZSTR_LEN(commit_sha) > 0 ? zend_string_copy(commit_sha) : NULL;
    git_metadata->property_repository = repository_url && ZSTR_LEN(repository_url) > 0 ? zend_string_copy(repository_url) : NULL;
    zend_hash_update_ptr(&DATADOG_G(git_metadata), cwd, git_metadata);
}

void replace_git_metadata(git_metadata_t *git_metadata, zend_string *commit_sha, zend_string *repository_url) {
    if (git_metadata->property_commit) {
        zend_string_release(git_metadata->property_commit);
    }
    if (git_metadata->property_repository) {
        zend_string_release(git_metadata->property_repository);
    }
    git_metadata->property_commit = commit_sha;
    git_metadata->property_repository = repository_url;
}

void refresh_git_metadata_if_needed(zend_string *cwd, git_metadata_t *git_metadata) {
    zend_string *git_dir = find_git_dir(ZSTR_VAL(cwd));
    if (!git_dir) {
        // Git directory no longer exists - invalidate cached entry
        zend_hash_del(&DATADOG_G(git_metadata), cwd);
        return;
    }
    zend_string *commit_sha = get_commit_sha(ZSTR_VAL(git_dir));

    if (commit_sha && git_metadata->property_commit) {
        if (!zend_string_equals(git_metadata->property_commit, commit_sha)) {
            zend_string *repository_url = get_repository_url(ZSTR_VAL(git_dir));
            replace_git_metadata(git_metadata, commit_sha, repository_url);
        } else {
            zend_string_release(commit_sha);
        }
    } else if (commit_sha) {
        zend_string *repository_url = get_repository_url(ZSTR_VAL(git_dir));
        replace_git_metadata(git_metadata, commit_sha, repository_url);
    } else if (git_metadata->property_commit) {
        // If we previously had a commit SHA but now can't read it, the git folder became invalid
        zend_hash_del(&DATADOG_G(git_metadata), cwd);
    }

    zend_string_release(git_dir);
}

void update_git_metadata(void) {
    zend_string *cwd;
    git_metadata_t *git_metadata;
    ZEND_HASH_FOREACH_STR_KEY_PTR(&DATADOG_G(git_metadata), cwd, git_metadata) {
        refresh_git_metadata_if_needed(cwd, git_metadata);
    }
    ZEND_HASH_FOREACH_END();
}

void datadog_git_metadata_dtor(zval *val) {
    git_metadata_t *git_metadata = Z_PTR_P(val);
    if (git_metadata->property_commit) zend_string_release(git_metadata->property_commit);
    if (git_metadata->property_repository) zend_string_release(git_metadata->property_repository);
    pefree(git_metadata, 1);
}

bool datadog_get_git_metadata(zend_string **out_commit, zend_string **out_repo) {
    if (DATADOG_G(git_resolved)) {
        *out_commit = DATADOG_G(git_commit);
        *out_repo = DATADOG_G(git_repository_url);
        return *out_commit != NULL || *out_repo != NULL;
    }

    DATADOG_G(git_resolved) = true;

    zend_string *commit = NULL, *repo = NULL;

    // From env vars
    {
        zend_string *env_commit = get_DD_GIT_COMMIT_SHA();
        zend_string *env_repo = get_DD_GIT_REPOSITORY_URL();
        if (ZSTR_LEN(env_commit) > 0 || ZSTR_LEN(env_repo) > 0) {
            if (ZSTR_LEN(env_commit) > 0) {
                commit = zend_string_copy(env_commit);
            }
            if (ZSTR_LEN(env_repo) > 0) {
                repo = remove_credentials(env_repo);
            }
            goto cache;
        }
    }

    // From global tags
    {
        zend_array *global_tags = get_DD_TAGS();
        zval *commit_zv = zend_hash_str_find(global_tags, ZEND_STRL("git.commit.sha"));
        zval *repo_zv = zend_hash_str_find(global_tags, ZEND_STRL("git.repository_url"));
        if (commit_zv && repo_zv && Z_TYPE_P(commit_zv) == IS_STRING && Z_TYPE_P(repo_zv) == IS_STRING) {
            commit = zend_string_copy(Z_STR_P(commit_zv));
            repo = remove_credentials(Z_STR_P(repo_zv));
            goto cache;
        }
    }

    // From git directory
    {
        zend_string *cwd = get_current_working_directory();
        if (cwd) {
            update_git_metadata();

            git_metadata_t *meta = zend_hash_find_ptr(&DATADOG_G(git_metadata), cwd);
            if (meta) {
                if (meta->property_commit) {
                    commit = zend_string_copy(meta->property_commit);
                }
                if (meta->property_repository) {
                    repo = remove_credentials(meta->property_repository);
                }
                zend_string_release(cwd);
            } else {
                zend_string *git_dir = find_git_dir(ZSTR_VAL(cwd));
                if (git_dir) {
                    zend_string *raw_commit = get_commit_sha(ZSTR_VAL(git_dir));
                    zend_string *raw_repo = get_repository_url(ZSTR_VAL(git_dir));

                    if ((raw_commit && ZSTR_LEN(raw_commit) > 0) || (raw_repo && ZSTR_LEN(raw_repo) > 0)) {
                        if (raw_commit && ZSTR_LEN(raw_commit) > 0) {
                            commit = zend_string_copy(raw_commit);
                        }
                        if (raw_repo && ZSTR_LEN(raw_repo) > 0) {
                            repo = remove_credentials(raw_repo);
                        }
                        cache_git_metadata(cwd, raw_commit, raw_repo);
                    }

                    if (raw_commit) zend_string_release(raw_commit);
                    if (raw_repo) zend_string_release(raw_repo);
                    zend_string_release(git_dir);
                }
                zend_string_release(cwd);
            }
        }
    }

cache:
    DATADOG_G(git_commit) = commit;
    DATADOG_G(git_repository_url) = repo;
    *out_commit = commit;
    *out_repo = repo;
    return commit != NULL || repo != NULL;
}

void datadog_git_rshutdown(void) {
    if (DATADOG_G(git_commit)) {
        zend_string_release(DATADOG_G(git_commit));
        DATADOG_G(git_commit) = NULL;
    }
    if (DATADOG_G(git_repository_url)) {
        zend_string_release(DATADOG_G(git_repository_url));
        DATADOG_G(git_repository_url) = NULL;
    }
    DATADOG_G(git_resolved) = false;
}
