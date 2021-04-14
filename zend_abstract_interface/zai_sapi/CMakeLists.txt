add_library(zai_sapi "zai_sapi_ini.c" "zai_sapi_io.c"
                     "${PHP_VERSION_DIRECTORY}/zai_sapi.c")

target_include_directories(zai_sapi
                           PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                  $<INSTALL_INTERFACE:include>)

target_compile_features(zai_sapi PUBLIC c_std_99)

target_link_libraries(zai_sapi PUBLIC "${PHP_LIB}")

set_target_properties(zai_sapi PROPERTIES
  EXPORT_NAME Sapi
  VERSION ${PROJECT_VERSION})

add_library(Zai::Sapi ALIAS zai_sapi)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/zai_sapi.h
              ${CMAKE_CURRENT_SOURCE_DIR}/zai_sapi_ini.h
              ${CMAKE_CURRENT_SOURCE_DIR}/zai_sapi_io.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/zai_sapi/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_sapi)

install(TARGETS zai_sapi EXPORT ZendAbstractInterfaceTargets)
