/* This is a generated file, edit uhook_attributes.stub.php instead.
 * Stub hash: 9f833ba566426979c9ee7ec5c22dcd2f7053cc1d */

#include "zend_attributes.h"

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_DDTrace_Trace___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, name, IS_STRING, 0, "\"\"")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, resource, IS_STRING, 0, "\"\"")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, type, IS_STRING, 0, "\"\"")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, service, IS_STRING, 0, "\"\"")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, tags, IS_ARRAY, 0, "[]")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, recurse, _IS_BOOL, 0, "true")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, run_if_limited, _IS_BOOL, 0, "false")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, attributes, IS_ARRAY, 0, "[]")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, kind, IS_LONG, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, component, IS_STRING, 1, "null")
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
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);

	zend_string *attribute_name_Attribute_class_DDTrace_Trace_0 = zend_string_init_interned("Attribute", sizeof("Attribute") - 1, true);
	zend_attribute *attribute_Attribute_class_DDTrace_Trace_0 = zend_add_class_attribute(class_entry, attribute_name_Attribute_class_DDTrace_Trace_0, 1);
	zend_string_release_ex(attribute_name_Attribute_class_DDTrace_Trace_0, true);
	ZVAL_LONG(&attribute_Attribute_class_DDTrace_Trace_0->args[0].value, ZEND_ATTRIBUTE_TARGET_FUNCTION | ZEND_ATTRIBUTE_TARGET_METHOD);

	return class_entry;
}
