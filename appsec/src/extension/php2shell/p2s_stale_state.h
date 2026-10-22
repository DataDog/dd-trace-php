// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// CVE-2026-109619 sub-mitigations: (B) resetting the request-owned engine
// globals at post_deactivate and clearing the last-error state from the
// sapi_module.deactivate wrapper; (C) turning session upload progress off for
// an armed startup POST parse.

#ifndef DD_P2S_STALE_STATE_H
#define DD_P2S_STALE_STATE_H

#include <php.h>
#include <stdbool.h>

#include "../attributes.h"

// Capture the sapi_module.deactivate slot and put our wrapper in it.
void dd_p2s_stale_state_install(void);
// Restore the sapi_module.deactivate slot if it still holds our wrapper, and
// forget the captured handler.
void dd_p2s_stale_state_uninstall(void);
// Log once per process if the deactivate slot was displaced.
void dd_p2s_stale_state_check_deactivate(void);
// The sapi_module.deactivate MINFO hook row.
void dd_p2s_stale_state_minfo_deactivate(void);
// Sub-mitigation B's resets, unless the runtime is patched.
void dd_p2s_stale_state_post_deactivate(void);
// Turn session upload progress off; returns whether the caller must restore
// it with dd_p2s_stale_state_upload_progress_restore().
bool dd_p2s_stale_state_upload_progress_off(void);
void dd_p2s_stale_state_upload_progress_restore(void);
#ifdef TESTING
void dd_p2s_stale_state_register_testing(void);
// Add the "post_deactivate_resets" key to the status() testing array.
void dd_p2s_stale_state_testing_status(zval *nonnull status);
#endif

#endif // DD_P2S_STALE_STATE_H
