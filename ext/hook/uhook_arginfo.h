/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 74ab08e7f09c564fabb646706cb9ba0371026875 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_install_hook, 0, 1, IS_LONG, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, target, Closure|Generator, MAY_BE_STRING|MAY_BE_CALLABLE, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, begin, Closure, 1, "null")
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, end, Closure, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, flags, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_DDTrace_remove_hook, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, location, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_DDTrace_HookData_span, 0, 0, DDTrace\\SpanData, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, parent, DDTrace\\SpanStack|DDTrace\\SpanData, MAY_BE_NULL, "null")
ZEND_END_ARG_INFO()

#define arginfo_class_DDTrace_HookData_unlimitedSpan arginfo_class_DDTrace_HookData_span

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DDTrace_HookData_overrideArguments, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, arguments, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DDTrace_HookData_overrideReturnValue, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(ZEND_SEND_PREFER_REF, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DDTrace_HookData_overrideException, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_OBJ_INFO(0, exception, Throwable, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DDTrace_HookData_disableJitInlining, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_DDTrace_HookData_suppressCall arginfo_class_DDTrace_HookData_disableJitInlining

#define arginfo_class_DDTrace_HookData_allowNestedHook arginfo_class_DDTrace_HookData_disableJitInlining

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_DDTrace_HookData_getSourceFile, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(DDTrace_install_hook);
ZEND_FUNCTION(DDTrace_remove_hook);
ZEND_METHOD(DDTrace_HookData, span);
ZEND_METHOD(DDTrace_HookData, unlimitedSpan);
ZEND_METHOD(DDTrace_HookData, overrideArguments);
ZEND_METHOD(DDTrace_HookData, overrideReturnValue);
ZEND_METHOD(DDTrace_HookData, overrideException);
ZEND_METHOD(DDTrace_HookData, disableJitInlining);
ZEND_METHOD(DDTrace_HookData, suppressCall);
ZEND_METHOD(DDTrace_HookData, allowNestedHook);
ZEND_METHOD(DDTrace_HookData, getSourceFile);

static const zend_function_entry ext_functions[] = {
	ZEND_RAW_FENTRY(ZEND_NS_NAME("DDTrace", "install_hook"), zif_DDTrace_install_hook, arginfo_DDTrace_install_hook, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("DDTrace", "remove_hook"), zif_DDTrace_remove_hook, arginfo_DDTrace_remove_hook, 0, NULL, NULL)
	ZEND_FE_END
};

static const zend_function_entry class_DDTrace_HookData_methods[] = {
	ZEND_ME(DDTrace_HookData, span, arginfo_class_DDTrace_HookData_span, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_HookData, unlimitedSpan, arginfo_class_DDTrace_HookData_unlimitedSpan, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_HookData, overrideArguments, arginfo_class_DDTrace_HookData_overrideArguments, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_HookData, overrideReturnValue, arginfo_class_DDTrace_HookData_overrideReturnValue, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_HookData, overrideException, arginfo_class_DDTrace_HookData_overrideException, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_HookData, disableJitInlining, arginfo_class_DDTrace_HookData_disableJitInlining, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_HookData, suppressCall, arginfo_class_DDTrace_HookData_suppressCall, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_HookData, allowNestedHook, arginfo_class_DDTrace_HookData_allowNestedHook, ZEND_ACC_PUBLIC)
	ZEND_ME(DDTrace_HookData, getSourceFile, arginfo_class_DDTrace_HookData_getSourceFile, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static void register_uhook_symbols(int module_number)
{
	REGISTER_STRING_CONSTANT("DDTrace\\HOOK_ALL_FILES", "", CONST_PERSISTENT);
	REGISTER_LONG_CONSTANT("DDTrace\\HOOK_INSTANCE", HOOK_INSTANCE, CONST_PERSISTENT);
}

static zend_class_entry *register_class_DDTrace_HookData(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DDTrace", "HookData", class_DDTrace_HookData_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, 0);

	zval property_data_default_value;
	ZVAL_UNDEF(&property_data_default_value);
	zend_string *property_data_name = zend_string_init("data", sizeof("data") - 1, 1);
	zend_declare_typed_property(class_entry, property_data_name, &property_data_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ANY));
	zend_string_release(property_data_name);

	zval property_id_default_value;
	ZVAL_UNDEF(&property_id_default_value);
	zend_string *property_id_name = zend_string_init("id", sizeof("id") - 1, 1);
	zend_declare_typed_property(class_entry, property_id_name, &property_id_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_id_name);

	zval property_args_default_value;
	ZVAL_UNDEF(&property_args_default_value);
	zend_declare_typed_property(class_entry, ZSTR_KNOWN(ZEND_STR_ARGS), &property_args_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));

	zval property_returned_default_value;
	ZVAL_UNDEF(&property_returned_default_value);
	zend_string *property_returned_name = zend_string_init("returned", sizeof("returned") - 1, 1);
	zend_declare_typed_property(class_entry, property_returned_name, &property_returned_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ANY));
	zend_string_release(property_returned_name);

	zval property_exception_default_value;
	ZVAL_UNDEF(&property_exception_default_value);
	zend_string *property_exception_name = zend_string_init("exception", sizeof("exception") - 1, 1);
	zend_string *property_exception_class_Throwable = zend_string_init("Throwable", sizeof("Throwable")-1, 1);
	zend_declare_typed_property(class_entry, property_exception_name, &property_exception_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_exception_class_Throwable, 0, MAY_BE_NULL));
	zend_string_release(property_exception_name);

	return class_entry;
}
