/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 97cc0e5375b6d1d07fa0db5a4dba3e102709674f */

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
