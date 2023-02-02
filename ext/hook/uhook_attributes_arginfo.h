/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: f89e44d830bdaf827effa53dd5d7643c90974b11 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_DDTrace_Trace___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, name, IS_STRING, 0, "\"\"")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, resource, IS_STRING, 0, "\"\"")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, type, IS_STRING, 0, "\"\"")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, service, IS_STRING, 0, "\"\"")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, tags, IS_ARRAY, 0, "[]")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, recurse, _IS_BOOL, 0, "true")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, run_if_limited, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()


ZEND_METHOD(DDTrace_Trace, __construct);


static const zend_function_entry class_DDTrace_Trace_methods[] = {
	ZEND_ME(DDTrace_Trace, __construct, arginfo_class_DDTrace_Trace___construct, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_DDTrace_Trace(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DDTrace", "Trace", class_DDTrace_Trace_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	zend_string *attribute_name_Attribute_class_DDTrace_Trace = zend_string_init_interned("Attribute", sizeof("Attribute") - 1, 1);
	zend_attribute *attribute_Attribute_class_DDTrace_Trace = zend_add_class_attribute(class_entry, attribute_name_Attribute_class_DDTrace_Trace, 1);
	zend_string_release(attribute_name_Attribute_class_DDTrace_Trace);
	zval attribute_Attribute_class_DDTrace_Trace_arg0;
	ZVAL_LONG(&attribute_Attribute_class_DDTrace_Trace_arg0, ZEND_ATTRIBUTE_TARGET_FUNCTION | ZEND_ATTRIBUTE_TARGET_METHOD);
	ZVAL_COPY_VALUE(&attribute_Attribute_class_DDTrace_Trace->args[0].value, &attribute_Attribute_class_DDTrace_Trace_arg0);

	return class_entry;
}
