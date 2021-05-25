add_library(zai_assert INTERFACE)

target_include_directories(zai_assert INTERFACE
                                      $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                      $<INSTALL_INTERFACE:include>)

target_compile_features(zai_assert INTERFACE c_std_99)

target_link_libraries(zai_assert INTERFACE "${PHP_LIB}")

add_library(Zai::Assert ALIAS zai_assert)

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/zai_assert.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/zai_assert/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_assert)

install(TARGETS zai_assert EXPORT ZendAbstractInterfaceTargets)
