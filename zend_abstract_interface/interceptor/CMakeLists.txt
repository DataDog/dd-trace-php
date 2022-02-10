add_library(zai_interceptor "${PHP_VERSION_DIRECTORY}/interceptor.c"
                            "${PHP_VERSION_DIRECTORY}/resolver.c")

target_include_directories(zai_interceptor PUBLIC
                                       $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                       $<INSTALL_INTERFACE:include>)

target_compile_features(zai_interceptor PUBLIC c_std_99)

target_link_libraries(zai_interceptor PUBLIC "${PHP_LIB}" Zai::Hook)

set_target_properties(zai_interceptor PROPERTIES
                                  EXPORT_NAME Interceptor
                                  VERSION ${PROJECT_VERSION})

add_library(Zai::Interceptor ALIAS zai_interceptor)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/interceptor.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/interceptor/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_interceptor)

install(TARGETS zai_interceptor EXPORT ZendAbstractInterfaceTargets)
