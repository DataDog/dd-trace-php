add_library(zai_string INTERFACE)

target_include_directories(zai_string INTERFACE
                                      $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                      $<INSTALL_INTERFACE:include>)

target_compile_features(zai_string INTERFACE c_std_99)

target_link_libraries(zai_string INTERFACE "${PHP_LIB}")

add_library(Zai::string ALIAS zai_string)

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/string.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/zai_string/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_string)

install(TARGETS zai_string EXPORT ZendAbstractInterfaceTargets)
