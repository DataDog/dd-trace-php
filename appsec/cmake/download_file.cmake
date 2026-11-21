# cmake -DURL=<url> -DDEST=<file> -P download_file.cmake
file(DOWNLOAD "${URL}" "${DEST}.tmp" STATUS _status TLS_VERIFY ON)
list(GET _status 0 _code)
if(NOT _code EQUAL 0)
    list(GET _status 1 _message)
    file(REMOVE "${DEST}.tmp")
    message(FATAL_ERROR "Downloading ${URL} failed: ${_message}")
endif()
file(RENAME "${DEST}.tmp" "${DEST}")
