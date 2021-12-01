// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <spdlog/spdlog.h>

// NOLINTNEXTLINE
#define DD_STDLOG(...)                                                        \
    SPDLOG_LOGGER_CALL(spdlog::default_logger_raw(), __VA_ARGS__)

/* Startup 1 [CRITICAL]
 * A fatal failure upon startup that's not covered by C2 */
// NOLINTNEXTLINE
#define STDLOG_STARTUP_FATAL_FAILURE_OTHER                                     \
    dd_log_fatal,                                                              \
        "AppSec could not start because of an unexpected error. Please "       \
        "contact support at https://docs.datadoghq.com/help/ for help. Error " \
        "details: %s"

/* Startup 2 [CRITICAL]
 * A fatal failure caused by incompatibility of libddwaf with the runtime.
 * Comment: This cannot happen because the liddwaf (in the helper) is provided
 * for the same environments as the extension itself */

/* Startup 3 [INFO]
 * AppSec library starting.
 * Comment: libddwaf version is included in the spec, but it is not knowable
 * before connecting to the helper.
 */
// NOLINTNEXTLINE
#define DD_STDLOG_STARTUP_BEGAN                                                \
    spdlog::level::info, "AppSec starting with the following configuration: "  \
                         "{{ rules_file_path: {} }}"

/* Rules Loading 1 [CRITICAL]
 * Rules file not found */
// NOLINTNEXTLINE
#define DD_STDLOG_RULES_FILE_NOT_FOUND                                         \
    spdlog::level::critical,                                                   \
        "AppSec could not find the rules file in path {}. AppSec will not "    \
        "run any protections in this application."

/* Rules Loading 2 [CRITICAL]
 * Rules file was found but was invalid. Second placeholder is the reason */
// NOLINTNEXTLINE
#define DD_STDLOG_RULES_FILE_INVALID                                           \
    spdlog::level::critical,                                                   \
        "AppSec could not read the rule file {} as it was invalid: {}. "       \
        "AppSec will not run any protections in this application."

/* Rules Loading 3 [ERROR]
 * Some rules in the file are invalid and at least one is valid.
 * First placeholder is the file path, second placeholder has the form
 * <rule name 1>: <reason 1>[, <rule name 2>:...]
 * Comment: WAF does not provide this information */

/* Rules Loading 4 [INFO]
 * Describes how many rules were loaded.
 * Comment: presumably this refers to the successful rules.
 *          WAF doesn't provide this information */

/* Rules Loading 5 [DEBUG]
 * Describes the rules that were loaded and their addresses.
 * Comment: WAF doesn't provide this information */

/* Instrumentation Gateway 1 [DEBUG]
 * Address is pushed to the IG.
 * Comment: The concept of IG is a bit fuzzy here in PHP, but we issue this
 * every time we provide a value for an address
 */
// NOLINTNEXTLINE
#define DD_STDLOG_IG_DATA_PUSHED                                               \
    spdlog::level::debug, "Pushing address {} to the Instrumentation Gateway."

/* Instrumentation Gateway 2 [DEBUG]
 * A set of addresses triggers the execution of a rule.
 * Comment: IG does not provide this information */

/* WAF 1 [INFO → DEBUG]
 * Comment: Wilful spec violation: moved to debug */
// NOLINTNEXTLINE
#define DD_STDLOG_BEFORE_WAF spdlog::level::debug, "Executing AppSec In-App WAF"

/* WAF 2 [DEBUG]
 * Calling the WAF */
// NOLINTNEXTLINE
#define DD_STDLOG_CALLING_WAF                                                  \
    spdlog::level::debug, "Executing AppSec In-App WAF with parameters: {}"

/* WAF 3 [DEBUG]
 * After calling WAF */
// NOLINTNEXTLINE
#define DD_STDLOG_AFTER_WAF                                                    \
    spdlog::level::debug, "Executing AppSec In-App WAF finished. Took {} ms."

/* WAF 4 [DEBUG]
 * Result of calling the WAF */
// NOLINTNEXTLINE
#define DD_STDLOG_RESULT_WAF                                                   \
    spdlog::level::debug, "AppSec In-App WAF returned: {}"

/* Rule outcome and blocking
 * Comment: not implemented because it would require parsing the WAF result */

/* Exception 1 [DEBUG]
 * Exception in rule execution.
 * Comment: requires rule name, which is not available */

/* Exception 2 [DEBUG]
 * Exception outside rule execution.
 * Comment: more specific error messages exist */

/* Data reporting messages: not relevant with span in-band reporting */

// NOLINTEND
