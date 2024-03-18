/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: defedfc462eb97b2649c178fd7b94d880f0d2ea7 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Integrations_Exec_register_stream, 0, 2, _IS_BOOL, 0)
	ZEND_ARG_INFO(0, stream)
	ZEND_ARG_OBJ_INFO(0, span, DDTrace\\SpanData, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Integrations_Exec_proc_assoc_span, 0, 2, _IS_BOOL, 0)
	ZEND_ARG_INFO(0, proc_h)
	ZEND_ARG_OBJ_INFO(0, span, DDTrace\\SpanData, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_DDTrace_Integrations_Exec_proc_get_span, 0, 1, DDTrace\\SpanData, 1)
	ZEND_ARG_INFO(0, proc_h)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Integrations_Exec_proc_get_pid, 0, 1, IS_LONG, 1)
	ZEND_ARG_INFO(0, proc_h)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Integrations_Exec_test_rshutdown, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()


ZEND_FUNCTION(DDTrace_Integrations_Exec_register_stream);
ZEND_FUNCTION(DDTrace_Integrations_Exec_proc_assoc_span);
ZEND_FUNCTION(DDTrace_Integrations_Exec_proc_get_span);
ZEND_FUNCTION(DDTrace_Integrations_Exec_proc_get_pid);
ZEND_FUNCTION(DDTrace_Integrations_Exec_test_rshutdown);


static const zend_function_entry ext_functions[] = {
	ZEND_NS_FALIAS("DDTrace\\Integrations\\Exec", register_stream, DDTrace_Integrations_Exec_register_stream, arginfo_DDTrace_Integrations_Exec_register_stream)
	ZEND_NS_FALIAS("DDTrace\\Integrations\\Exec", proc_assoc_span, DDTrace_Integrations_Exec_proc_assoc_span, arginfo_DDTrace_Integrations_Exec_proc_assoc_span)
	ZEND_NS_FALIAS("DDTrace\\Integrations\\Exec", proc_get_span, DDTrace_Integrations_Exec_proc_get_span, arginfo_DDTrace_Integrations_Exec_proc_get_span)
	ZEND_NS_FALIAS("DDTrace\\Integrations\\Exec", proc_get_pid, DDTrace_Integrations_Exec_proc_get_pid, arginfo_DDTrace_Integrations_Exec_proc_get_pid)
	ZEND_NS_FALIAS("DDTrace\\Integrations\\Exec", test_rshutdown, DDTrace_Integrations_Exec_test_rshutdown, arginfo_DDTrace_Integrations_Exec_test_rshutdown)
	ZEND_FE_END
};
