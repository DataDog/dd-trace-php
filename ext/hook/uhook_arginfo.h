/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 9d121c2053e69043217e9fe88d197e9dfcea77a4 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_install_hook, 0, 3, IS_LONG, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, target, Closure|Generator, MAY_BE_STRING, NULL)
	ZEND_ARG_OBJ_INFO(0, begin, Closure, 1)
	ZEND_ARG_OBJ_INFO(0, end, Closure, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_DDTrace_remove_hook, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
ZEND_END_ARG_INFO()


ZEND_FUNCTION(DDTrace_install_hook);
ZEND_FUNCTION(DDTrace_remove_hook);


static const zend_function_entry ext_functions[] = {
	ZEND_NS_FALIAS("DDTrace", install_hook, DDTrace_install_hook, arginfo_DDTrace_install_hook)
	ZEND_NS_FALIAS("DDTrace", remove_hook, DDTrace_remove_hook, arginfo_DDTrace_remove_hook)
	ZEND_FE_END
};


static const zend_function_entry class_DDTrace_HookData_methods[] = {
	ZEND_FE_END
};

static zend_class_entry *register_class_DDTrace_HookData(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "DDTrace", "HookData", class_DDTrace_HookData_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);

	zval property_id_default_value;
	ZVAL_UNDEF(&property_id_default_value);
	zend_string *property_id_name = zend_string_init("id", sizeof("id") - 1, 1);
	zend_declare_typed_property(class_entry, property_id_name, &property_id_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_id_name);

	zval property_args_default_value;
	ZVAL_UNDEF(&property_args_default_value);
	zend_string *property_args_name = zend_string_init("args", sizeof("args") - 1, 1);
	zend_declare_typed_property(class_entry, property_args_name, &property_args_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release(property_args_name);

	zval property_returned_default_value;
	ZVAL_UNDEF(&property_returned_default_value);
	zend_string *property_returned_name = zend_string_init("returned", sizeof("returned") - 1, 1);
	zend_declare_typed_property(class_entry, property_returned_name, &property_returned_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ANY));
	zend_string_release(property_returned_name);

	zend_string *property_exception_class_Throwable = zend_string_init("Throwable", sizeof("Throwable")-1, 1);
	zval property_exception_default_value;
	ZVAL_UNDEF(&property_exception_default_value);
	zend_string *property_exception_name = zend_string_init("exception", sizeof("exception") - 1, 1);
	zend_declare_typed_property(class_entry, property_exception_name, &property_exception_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_exception_class_Throwable, 0, MAY_BE_NULL));
	zend_string_release(property_exception_name);

	zval property_data_default_value;
	ZVAL_UNDEF(&property_data_default_value);
	zend_string *property_data_name = zend_string_init("data", sizeof("data") - 1, 1);
	zend_declare_typed_property(class_entry, property_data_name, &property_data_default_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ANY));
	zend_string_release(property_data_name);

	return class_entry;
}
