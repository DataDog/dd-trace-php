// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <spdlog/spdlog.h>

// NOLINTNEXTLINE
#define DD_STDLOG(...)                                                         \
    SPDLOG_LOGGER_CALL(spdlog::default_logger_raw(), __VA_ARGS__)

/* Startup [ERROR] DDAS-0001-01
 * If the AppSec library could not start because of an unexpected error. */
// NOLINTNEXTLINE
#define DD_LOG_STARTUP_ERROR                                                   \
    spdlog::level::err,                                                        \
        "DDAS-0001-01: AppSec could not start because of an unexpected "       \
        "error. No security activities will be collected. Please contact "     \
        "support at https://docs.datadoghq.com/help/ for help"

/* Startup [ERROR] DDAS-0001-02
 * If the AppSec library could not start because of an unsupported environment.
 * Comment: if the environment is unsupported either we don't know about it
 * or the helper/extension shouldn't even be able to run. */

/* Startup [ERROR] DDAS-0001-03
 * Install time issue preventing AppSec from starting.
 * Comment: perhaps relevant to the extension if the helper can't be found,
 * otherwise not relevant. */

/* Startup [ERROR] DDAS-0003-01
 * Rules file not found */
// NOLINTNEXTLINE
#define DD_STDLOG_RULES_FILE_NOT_FOUND                                         \
    spdlog::level::err,                                                        \
        "DDAS-0003-01: AppSec could not read the rule file {}. Reason: rules " \
        "file not found. AppSec will not run any protections in this "         \
        "application"

/* Startup [ERROR] DDAS-0003-02
 * Rules file invalid */
// NOLINTNEXTLINE
#define DD_STDLOG_RULES_FILE_INVALID                                           \
    spdlog::level::err, "DDAS-0003-02: AppSec could not read the rule file "   \
                        "{}. Reason: invalid "                                 \
                        "file format. AppSec will not run any protections in " \
                        "this application"

/* Startup [ERROR] DDAS-0003-03
 * All rules are invalid
 * Comment: we can't know at this at this point, all we can know is that the
 * WAF failed to initialise. */

/* Request [ERROR] DDAS-0004-00
 * Failed to analyse request. */
// NOLINTNEXTLINE
#define DD_STDLOG_REQUEST_ANALYSIS_FAILED                                      \
    spdlog::level::err,                                                        \
        "DDAS-0004-00: AppSec failed to process request. Error details: {}"

/* Reload [WARNING] DDAS-0005-00
 * WAF initialization failed. */
// NOLINTNEXTLINE
#define DD_STDLOG_WAF_INIT_FAILED                                              \
    spdlog::level::warn, "DDAS-0005-00: WAF initialization failed. Some "      \
                         "rules are invalid in {}"

/* Startup [WARNING] DDAS-0006-00
 * Used framework is not supported by AppSec.
 * Comment: not relevant to our implementation of AppSec. */

/* Reload [DEBUG] DDAS-0007-00
 * Loading rules. */
// NOLINTNEXTLINE
#define DD_STDLOG_RULE_LOADED                                                  \
    spdlog::level::debug "DDAS-0007-00: Loaded rule: {} on addresses {}"

/* Reload [DEBUG] DDAS-0008-00
 * Address is pushed to the IG. */
// NOLINTNEXTLINE
#define DD_STDLOG_IG_DATA_PUSHED                                               \
    spdlog::level::debug,                                                      \
        "DDAS-0008-00: Pushing address {} to the Instrumentation Gateway"

/* Reload [DEBUG] DDAS-0009-00
 * Matching rules for an available set of addresses.
 * Comment: information not available. */

/* Request [DEBUG] DDAS-0010-00
 * Calling the WAF */
// NOLINTNEXTLINE
#define DD_STDLOG_CALLING_WAF                                                  \
    spdlog::level::debug,                                                      \
        "DDAS-0010-00: Executing AppSec In-App WAF with parameters: {}"

/* Request [DEBUG] DDAS-0011-00
 * After calling WAF */
// NOLINTNEXTLINE
#define DD_STDLOG_AFTER_WAF                                                    \
    spdlog::level::debug,                                                      \
        "DDAS-0011-00: AppSec In-App WAF returned: {}. Took {} ms"

/* Request [DEBUG] DDAS-0012-01
 * Attack detected.
 * Comment: Requires parsing... so this is a version without data*/
// NOLINTNEXTLINE
#define DD_STDLOG_ATTACK_DETECTED                                              \
    spdlog::level::debug, "DDAS-0012-01: Detected an attack"

/* Request [DEBUG] DDAS-0012-02
 * Attack detected and blocked.
 * Comment: Requires parsing... so this is a version without data*/
// NOLINTNEXTLINE
#define DD_STDLOG_ATTACK_BLOCKED                                               \
    spdlog::level::debug, "DDAS-0012-01: Blocked attack"

/* Request [DEBUG] DDAS-0013-00
 * Exceeded WAF limit*/
// NOLINTNEXTLINE
#define DD_STDLOG_EXCEEDED_WAF_LIMITS                                          \
    spdlog::level::debug, "DDAS-0013-00: Reached WAF limits. Request "         \
                          "partially analyzed because of "                     \
                          "maxElements/maxDepth was exceeded"

/* Startup [INFO] DDAS-0014-00
 * AppSec started */
// NOLINTNEXTLINE
#define DD_STDLOG_STARTUP                                                      \
    spdlog::level::info, "DDAS-0014-00: AppSec has started"

/* Reload [INFO] DDAS-0015-00
 * Loading rules
 * Comment: can't know how many rules were loaded yet. */
