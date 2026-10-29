// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Monitor state: the zend_error_cb wrapper that detects native memory errors
// as they are raised and reports them on the span.

#ifndef DD_P2S_MONITOR_H
#define DD_P2S_MONITOR_H

// Capture zend_error_cb and put our wrapper in it.
void dd_p2s_monitor_install(void);
// Restore zend_error_cb if it still holds our wrapper, and forget the captured
// handler.
void dd_p2s_monitor_uninstall(void);
// Log once per process if the slot was displaced.
void dd_p2s_monitor_check_error_cb(void);
// The MINFO hook row.
void dd_p2s_monitor_minfo_error_cb(void);

#endif // DD_P2S_MONITOR_H
