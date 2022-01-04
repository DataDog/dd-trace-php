add_library(zai_function_call_interceptor "${PHP_VERSION_DIRECTORY}/function_call_interceptor.c")

target_include_directories(zai_function_call_interceptor PUBLIC
                                       $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                       $<INSTALL_INTERFACE:include>)

target_compile_features(zai_function_call_interceptor PUBLIC c_std_99)

target_link_libraries(zai_function_call_interceptor PUBLIC "${PHP_LIB}" Zai::Sandbox)

set_target_properties(zai_function_call_interceptor PROPERTIES
                                  EXPORT_NAME FunctionCallInterceptor
                                  VERSION ${PROJECT_VERSION})

add_library(Zai::FunctionCallInterceptor ALIAS zai_function_call_interceptor)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/function_call_interceptor.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/function_call_interceptor/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_function_call_interceptor)

install(TARGETS zai_function_call_interceptor EXPORT ZendAbstractInterfaceTargets)
