add_library(zai_string string.c)

target_include_directories(
  zai_string PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                    $<INSTALL_INTERFACE:include>)

target_compile_features(zai_string PUBLIC c_std_99)

target_link_libraries(zai_string PUBLIC Tea::Php Zai::Assert)

add_library(Zai::String ALIAS zai_string)

if (${BUILD_ZAI_TESTING})
    add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/string.h ${CMAKE_CURRENT_SOURCE_DIR}/tests/symbols.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/zai_string/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_string)

install(TARGETS zai_string EXPORT ZendAbstractInterfaceTargets)
