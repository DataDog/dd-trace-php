#include "../tsrmls_cache.h"

#include <components-rs/library-config.h>

#include "config_stable_file.h"

#define DDOG_SLICE_CHARSLICE(arr)                                                                  \
  ((ddog_Slice_CharSlice){.ptr = arr, .len = sizeof(arr) / sizeof(arr[0])})

void zai_config_stable_file_rinit(void) {
    // FIXME: should be done directly in the library-config crate?
    ddog_CharSlice args[] = {
        DDOG_CHARSLICE_C("/usr/bin/php"),
    };

    ddog_CharSlice envp[] = {
        DDOG_CHARSLICE_C("FOO=BAR"),
    };
    ddog_ProcessInfo process_info = {
        .args = DDOG_SLICE_CHARSLICE(args),
        .envp = DDOG_SLICE_CHARSLICE(envp),
        .language = DDOG_CHARSLICE_C("php")
    };

    ddog_Configurator *configurator = ddog_library_configurator_new(false, DDOG_CHARSLICE_C("php"));

    ddog_library_configurator_with_process_info(configurator, process_info);

    ddog_Result_VecLibraryConfig config_result = ddog_library_configurator_get(configurator);

    if (config_result.tag != DDOG_RESULT_VEC_LIBRARY_CONFIG_ERR_VEC_LIBRARY_CONFIG) {
        ddog_Vec_LibraryConfig configs = config_result.ok;
        for (uintptr_t i = 0; i < configs.len; i++) {
            const ddog_LibraryConfig *cfg = &configs.ptr[i];
            ddog_CStr name = ddog_library_config_name_to_env(cfg->name);

            // printf("Setting env variable: %s=%s\n", name.ptr, cfg->value.ptr);
            setenv(name.ptr, cfg->value.ptr, 1);
        }
    } else {
        ddog_Error err = config_result.err;
        // fprintf(stderr, "%.*s", (int)err.message.len, err.message.ptr);
        ddog_Error_drop(&err);
    }

}
