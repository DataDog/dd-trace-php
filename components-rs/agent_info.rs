// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

//! FFI wrappers that read from the agent /info SHM (`AgentInfoReader`) and propagate
//! the results to the concentrator and trace-filter subsystems.
//!
//! Both functions perform a single `reader.read()` call (advancing the internal SHM
//! position once) and use the returned `changed` flag to decide whether to rebuild
//! the concentrator and filter configuration.

use crate::stats::apply_concentrator_config;
use datadog_sidecar::service::agent_info::AgentInfoReader;
use libdd_common_ffi::slice::CharSlice;
use std::ffi::c_char;

/// Read all agent /info data in one SHM read and apply env, container-hash and concentrator
/// config atomically.
///
/// Fills `env_out` with the agent's `config.default_env` (zero-length slice if absent).
/// Fills `container_hash_out` with `container_tags_hash` (zero-length slice if absent).
/// Both slices borrow from the reader's cached info — valid until the next `reader.read()`.
///
/// Concentrator config (peer tags, span kinds, trace filters) is applied only when the
/// SHM has changed since the last read (`changed == true`).  Calling this once at RINIT
/// ensures the config is always applied before the first span is processed, so the
/// per-span `ddog_apply_agent_info_concentrator_config` can safely rely on `changed` alone.
///
/// # Safety
/// `reader` must be a valid pointer to an `AgentInfoReader`.
#[no_mangle]
pub unsafe extern "C" fn ddog_apply_agent_info(
    reader: &mut AgentInfoReader,
    env_out: &mut CharSlice<'static>,
    container_hash_out: &mut CharSlice<'static>,
) {
    let (changed, info) = reader.read();
    if let Some(info) = info {
        if let Some(s) = info
            .config
            .as_ref()
            .and_then(|c| c.default_env.as_deref())
            .filter(|s| !s.is_empty())
        {
            *env_out = CharSlice::from_raw_parts(s.as_ptr() as *const c_char, s.len());
        }
        if changed {
            if let Some(s) = info.container_tags_hash.as_deref().filter(|s| !s.is_empty()) {
                *container_hash_out = CharSlice::from_raw_parts(s.as_ptr() as *const c_char, s.len());
            } else {
                *container_hash_out = CharSlice::empty();
            }
            apply_concentrator_config(
                info.peer_tags.as_deref().unwrap_or(&[]).to_owned(),
                info.span_kinds_stats_computed.as_deref().unwrap_or(&[]).to_owned(),
                info.filter_tags.as_ref().and_then(|f| f.require.as_deref()).unwrap_or(&[]).to_owned(),
                info.filter_tags.as_ref().and_then(|f| f.reject.as_deref()).unwrap_or(&[]).to_owned(),
                info.filter_tags_regex.as_ref().and_then(|f| f.require.as_deref()).unwrap_or(&[]).to_owned(),
                info.filter_tags_regex.as_ref().and_then(|f| f.reject.as_deref()).unwrap_or(&[]).to_owned(),
                info.ignore_resources.as_deref().unwrap_or(&[]).to_owned(),
                info.client_drop_p0s.unwrap_or(false),
                info.version.as_deref(),
            );
        }
    }
}

/// Apply concentrator config changes from the agent /info SHM.
///
/// Cheap no-op when the SHM has not changed (`changed == false`).  Only applies when
/// new data has arrived mid-request — `ddog_apply_agent_info` at RINIT guarantees the
/// initial configuration is already in place, so `changed` alone is sufficient here.
///
/// # Safety
/// `reader` must be a valid pointer to an `AgentInfoReader`.
#[no_mangle]
pub unsafe extern "C" fn ddog_apply_agent_info_concentrator_config(
    reader: &mut AgentInfoReader,
) {
    let (changed, info) = reader.read();
    if !changed {
        return;
    }
    if let Some(info) = info {
        apply_concentrator_config(
            info.peer_tags.as_deref().unwrap_or(&[]).to_owned(),
            info.span_kinds_stats_computed.as_deref().unwrap_or(&[]).to_owned(),
            info.filter_tags.as_ref().and_then(|f| f.require.as_deref()).unwrap_or(&[]).to_owned(),
            info.filter_tags.as_ref().and_then(|f| f.reject.as_deref()).unwrap_or(&[]).to_owned(),
            info.filter_tags_regex.as_ref().and_then(|f| f.require.as_deref()).unwrap_or(&[]).to_owned(),
            info.filter_tags_regex.as_ref().and_then(|f| f.reject.as_deref()).unwrap_or(&[]).to_owned(),
            info.ignore_resources.as_deref().unwrap_or(&[]).to_owned(),
            info.client_drop_p0s.unwrap_or(false),
            info.version.as_deref(),
        );
    }
}
