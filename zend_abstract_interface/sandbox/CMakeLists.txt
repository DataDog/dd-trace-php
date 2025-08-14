add_library(zai_sandbox "${PHP_VERSION_DIRECTORY}/sandbox.c" "call.c")

target_include_directories(zai_sandbox PUBLIC
                                       $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                       $<INSTALL_INTERFACE:include>)

target_compile_features(zai_sandbox PUBLIC c_std_99)

target_link_libraries(zai_sandbox PUBLIC Tea::Php)

set_target_properties(zai_sandbox PROPERTIES
                                  EXPORT_NAME Sandbox
                                  VERSION ${PROJECT_VERSION})

add_library(Zai::Sandbox ALIAS zai_sandbox)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
# TODO: How to make this zai/sandbox.h?
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/sandbox.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/sandbox/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_sandbox)

install(TARGETS zai_sandbox EXPORT ZendAbstractInterfaceTargets)
