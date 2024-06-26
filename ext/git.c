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


zend_string *read_git_file(char *path) {
    remove_trailing_newline(path);

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
    return zend_string_init(buffer, len, 0);
}

zend_string *get_commit_sha(const char *git_dir) {
    char head_path[PATH_MAX];
    snprintf(head_path, sizeof(head_path), "%s/HEAD", git_dir);

    zend_string * head_content = read_git_file(head_path);
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
    zend_string * result = NULL;

    while (fgets(buffer, sizeof(buffer), file)) {
        if (strncmp(buffer, "[remote \"origin\"]", 17) == 0) {
            while (fgets(buffer, sizeof(buffer), file)) {
                if (buffer[0] == '[') break;
                const char *url_prefix = "url = ";
                char *url = strstr(buffer, url_prefix);
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

void add_git_info(zval *carrier, zend_string *commit_sha, zend_string *repository_url) {
    object_init_ex(carrier, ddtrace_ce_git_metadata);
    ddtrace_git_metadata *git_metadata = (ddtrace_git_metadata *) Z_OBJ_P(carrier);
    zend_string_addref(commit_sha);
    ZVAL_STR(&git_metadata->property_commit, commit_sha);
    zend_string_addref(repository_url);
    ZVAL_STR(&git_metadata->property_repository, repository_url);
}

bool inject_from_env(zval *carrier) {
    zend_string *commit_sha = get_DD_GIT_COMMIT_SHA();
    zend_string *repository_url = get_DD_GIT_REPOSITORY_URL();
    if (commit_sha && repository_url) {
        remove_credentials(repository_url);
        add_git_info(carrier, commit_sha, repository_url);
        return true;
    }
    return false;
}

bool inject_from_global_tags(zval *carrier) {
    zend_array * global_tags = get_DD_TAGS();
    zval *repository_url = zend_hash_str_find(global_tags, ZEND_STRL("git.repository_url"));
    zval *commit_sha = zend_hash_str_find(global_tags, ZEND_STRL("git.commit.sha"));
    if (repository_url && commit_sha) {
        remove_credentials(Z_STR_P(repository_url));
        add_git_info(carrier, Z_STR_P(commit_sha), Z_STR_P(repository_url));
        return true;
    }
    return false;
}

void ddtrace_inject_git_metadata(zval *git_metadata_zv) {
    zend_string *cwd_zstr = NULL;

    if (SG(options) & SAPI_OPTION_NO_CHDIR) {
        const char *script_filename = SG(request_info).path_translated;
        cwd_zstr = zend_string_init(script_filename, strlen(script_filename), 1);
    } else {
        char cwd[PATH_MAX];
        if (getcwd(cwd, sizeof(cwd)) == NULL) {
            return;
        }
        cwd_zstr = zend_string_init(cwd, strlen(cwd), 1);
    }
    
    zval *entry = zend_hash_find(&DDTRACE_G(git_metadata), cwd_zstr);

    if (entry && Z_TYPE_P(entry) == IS_FALSE) {
        zend_string_release(cwd_zstr);
        return;
    }

    if (entry) {
        zend_string_release(cwd_zstr);
        ZVAL_COPY(git_metadata_zv, entry);
        //add_git_info(git_metadata_zv, commit_sha, repository_url);
        return;
    }

    inject_from_env(git_metadata_zv);

    // Add the entry to the hash table
    if (Z_TYPE_P(git_metadata_zv) == IS_OBJECT) {
        zend_hash_add(&DDTRACE_G(git_metadata), cwd_zstr, git_metadata_zv);
    } else {
        ZVAL_FALSE(git_metadata_zv);
        zend_hash_add(&DDTRACE_G(git_metadata), cwd_zstr, git_metadata_zv);
    }

    zend_string_release(cwd_zstr);

    return;
}
