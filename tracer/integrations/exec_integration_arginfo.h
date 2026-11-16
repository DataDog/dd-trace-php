/* This is a generated file, edit exec_integration.stub.php instead.
 * Stub hash: f92693ec69345c95bf956a1fed3b64c5c8981165 */

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

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Integrations_Exec_proc_inject_session_ids, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(1, env, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_Integrations_Exec_test_rshutdown, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(DDTrace_Integrations_Exec_register_stream);
ZEND_FUNCTION(DDTrace_Integrations_Exec_proc_assoc_span);
ZEND_FUNCTION(DDTrace_Integrations_Exec_proc_get_span);
ZEND_FUNCTION(DDTrace_Integrations_Exec_proc_get_pid);
ZEND_FUNCTION(DDTrace_Integrations_Exec_proc_inject_session_ids);
ZEND_FUNCTION(DDTrace_Integrations_Exec_test_rshutdown);

static const zend_function_entry ext_functions[] = {
	ZEND_RAW_FENTRY(ZEND_NS_NAME("DDTrace\\Integrations\\Exec", "register_stream"), zif_DDTrace_Integrations_Exec_register_stream, arginfo_DDTrace_Integrations_Exec_register_stream, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("DDTrace\\Integrations\\Exec", "proc_assoc_span"), zif_DDTrace_Integrations_Exec_proc_assoc_span, arginfo_DDTrace_Integrations_Exec_proc_assoc_span, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("DDTrace\\Integrations\\Exec", "proc_get_span"), zif_DDTrace_Integrations_Exec_proc_get_span, arginfo_DDTrace_Integrations_Exec_proc_get_span, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("DDTrace\\Integrations\\Exec", "proc_get_pid"), zif_DDTrace_Integrations_Exec_proc_get_pid, arginfo_DDTrace_Integrations_Exec_proc_get_pid, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("DDTrace\\Integrations\\Exec", "proc_inject_session_ids"), zif_DDTrace_Integrations_Exec_proc_inject_session_ids, arginfo_DDTrace_Integrations_Exec_proc_inject_session_ids, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("DDTrace\\Integrations\\Exec", "test_rshutdown"), zif_DDTrace_Integrations_Exec_test_rshutdown, arginfo_DDTrace_Integrations_Exec_test_rshutdown, 0, NULL, NULL)
	ZEND_FE_END
};
