#include "span.h"
#include "git_metadata.h"
#include <ext/git.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

static datadog_git_metadata empty_git_object = { 0 };

void ddtrace_inject_git_metadata(zval *carrier) {
    if (DDTRACE_G(git_object) == &empty_git_object.std) {
        return;
    }

    if (!DDTRACE_G(git_object)) {
        zend_string *commit = NULL, *repo = NULL;
        if (!datadog_get_git_metadata(&commit, &repo)) {
            DDTRACE_G(git_object) = &empty_git_object.std;
            return;
        }

        zval git_obj;
        object_init_ex(&git_obj, ddtrace_ce_git_metadata);
        datadog_git_metadata *git_metadata = (datadog_git_metadata *) Z_OBJ(git_obj);
        DDTRACE_G(git_object) = &git_metadata->std;

        if (commit) {
            ZVAL_STR_COPY(&git_metadata->property_commit, commit);
        } else {
            ZVAL_NULL(&git_metadata->property_commit);
        }

        if (repo) {
            ZVAL_STR_COPY(&git_metadata->property_repository, repo);
        } else {
            ZVAL_NULL(&git_metadata->property_repository);
        }
    }

    ZVAL_OBJ_COPY(carrier, DDTRACE_G(git_object));
}

void ddtrace_clean_git_object(void) {
    if (DDTRACE_G(git_object) == &empty_git_object.std) {
        DDTRACE_G(git_object) = NULL;
        return;
    }

    if (DDTRACE_G(git_object)) {
        zend_object_release(DDTRACE_G(git_object));
        DDTRACE_G(git_object) = NULL;
    }
}
