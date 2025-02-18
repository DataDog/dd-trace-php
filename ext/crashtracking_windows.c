
#include <windows.h>

#include "version.h"
#include "crashtracking_windows.h"

#include <components-rs/common.h>
#include <components-rs/crashtracker.h>
#include <components-rs/sidecar.h>

HMODULE currentModule;

BOOL APIENTRY DllMain(HMODULE hModule,
    DWORD  ul_reason_for_call,
    LPVOID lpReserved) {
    switch (ul_reason_for_call)
    {
    case DLL_PROCESS_ATTACH:
        currentModule = hModule;
        break;
    case DLL_THREAD_ATTACH:
    case DLL_THREAD_DETACH:
    case DLL_PROCESS_DETACH:
        break;
    }
    return TRUE;
}

bool init_crash_tracking(void) {

    char* path = ddog_setup_crashtracking();

    if (!path) {
        OutputDebugStringW(L"Failed to setup crashtracking, path is null");
        return false;
    }
    else {
        OutputDebugStringW(L"Crashtracking path:");
        OutputDebugStringA(path);
    }

    HMODULE crashtracking_module = LoadLibraryA(path);

    if (!crashtracking_module) {
        OutputDebugStringW(L"Failed to load crashtracking module");
        return false;
    }

    ddog_crasht_Metadata metadata = {
        .library_name = DDOG_CHARSLICE_C_BARE("php_ddtrace"),
        .library_version = DDOG_CHARSLICE_C_BARE(PHP_DDTRACE_VERSION),
        .family = DDOG_CHARSLICE_C("php"),
        .tags = NULL
    };

    OutputDebugStringW(L"Loaded crashtracking wrapper");

    ddog_Endpoint *endpoint = ddog_endpoint_from_filename(DDOG_CHARSLICE_C("C:/temp/crash.json"));

    return ddog_crasht_init_windows(crashtracking_module, endpoint, metadata);
}