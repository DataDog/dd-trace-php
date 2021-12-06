#include "stack-collector.h"

#include <Zend/zend_compile.h>

typedef datadog_php_string_view string_view_t;

void datadog_php_stack_collect(zend_execute_data *execute_data,
                               datadog_php_stack_sample *sample) {
  datadog_php_stack_sample_ctor(sample);

  for (uint16_t depth = 0;
       depth < datadog_php_stack_sample_max_depth && execute_data;
       ++depth, execute_data = execute_data->prev_execute_data) {
  loop_begin:;
    zend_function *func = execute_data->func;

    datadog_php_stack_sample_frame frame = {
        .function = {0, NULL},
        .file = {0, NULL},
        .lineno = 0,
    };

    if (func) {
      /* User functions do not have a module; if we can ever extract info
       * from composer packages then we could perhaps use that.
       */
      string_view_t module = {0, NULL};
      if (func->type == ZEND_INTERNAL_FUNCTION &&
          func->internal_function.module &&
          func->internal_function.module->name) {
        const char *name = func->internal_function.module->name;
        module = (string_view_t){strlen(name), name};
      }

      zend_string *objname =
          func->common.scope ? func->common.scope->name : NULL;

      string_view_t Class = {objname ? objname->len : 0,
                             objname ? objname->val : ""};

      zend_string *fname = func->common.function_name;

      string_view_t Func = {fname ? fname->len : 0, fname ? fname->val : ""};
      char buffer[256u]; // 256 bytes should be enough for anyone... right?

      // uggggggggggggggggggghhhh... format strings
      const char fmt[] = "%.*s%s%.*s%s%.*s";
      /*                  │   │ │   │ └ function or method name
       *                  │   │ │   └ :: or empty if function
       *                  │   │ └ class name or empty if function
       *                  │   └ vertical bar or empty if no package
       *                  └ package name or empty
       */

      int result =
          snprintf(buffer, sizeof buffer, fmt, (int)module.len, module.ptr,
                   module.len ? "|" : "", (int)Class.len, Class.ptr,
                   Class.len ? "::" : "", (int)Func.len, Func.ptr);

      if (result < 0 || ((size_t)result) >= sizeof buffer) {
        if (execute_data->prev_execute_data) {
          execute_data = execute_data->prev_execute_data;
          goto loop_begin;
        }
        break;
      }

      frame.function = (string_view_t){result, buffer};
      if (func->type == ZEND_USER_FUNCTION) {
        zend_string *file = func->op_array.filename;
        if (file) {
          frame.file = (string_view_t){file->len, file->val};
        }
        if (execute_data->opline) {
          frame.lineno = execute_data->opline->lineno;
        }
      }

      if (!frame.function.len && !frame.file.len) {
        // No file nor function -> skip the frame (do not increase depth)
        if (execute_data->prev_execute_data) {
          execute_data = execute_data->prev_execute_data;
          goto loop_begin;
        }
        break;
      }

      if (frame.file.len && !frame.function.len) {
        // we'll use a fake name when there isn't one.
        frame.function = (string_view_t){sizeof("<php>") - 1, "<php>"};
      }

      if (!datadog_php_stack_sample_try_add(sample, frame)) {
        // todo: is the top sample valid? (probably not)
        break;
      }

    } else if (execute_data->prev_execute_data) {
      /* This may be a dummy frame. Dummy frames are often used by
       * require/include but as far as I know there aren't any flags on the
       * execute_data to let you know that's where it came from.
       * So we skip this frame, intentionally _not_ increasing depth. I do not
       * like this goto, but if this is the top frame then doing a --depth would
       * cause a logic issue.
       */
      execute_data = execute_data->prev_execute_data;
      goto loop_begin;
    }
  }
}
