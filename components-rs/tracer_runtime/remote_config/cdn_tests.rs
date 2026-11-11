// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

//! Integration coverage for ordinary RC shared memory -> PHP's shared evaluator.
//! Only scheduling is controlled; managers, parsing and publication are real.

use super::*;
use crate::ffe;
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use datadog_ffe::cdn::{CdnConfig, CdnEndpoint};
use datadog_sidecar::shm_remote_config::RemoteConfigWriter;
use libdd_ipc::platform::{FileBackedHandle, NamedShmHandle};
use std::cell::RefCell;
use std::ffi::CString;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::mpsc;
use std::time::Duration;

thread_local! {
    static BEFORE_PUBLICATION: RefCell<Option<Box<dyn FnOnce()>>> = RefCell::new(None);
}

pub(super) fn before_publication() {
    let hook = BEFORE_PUBLICATION.with(|hook| hook.borrow_mut().take());
    if let Some(hook) = hook {
        hook();
    }
}

static NEXT_ID: AtomicUsize = AtomicUsize::new(0);

struct Fixture {
    registration: CdnRegistration,
    writer: RemoteConfigWriter,
    files: Vec<NamedShmHandle>,
}

impl Fixture {
    fn new() -> Self {
        ffe::use_agentless_config(true);
        ffe::clear_config();
        let registration = CdnRegistration {
            config: CdnConfig {
                endpoint: CdnEndpoint::custom("http://127.0.0.1:1/config").unwrap(),
                language: "php".into(),
                version: "test".into(),
                poll_interval: Duration::from_secs(1),
                request_timeout: Duration::from_secs(1),
            },
            agent_endpoint: Endpoint::default(),
            target: Arc::new(Target::new(
                format!(
                    "cdn-publication-{}-{}",
                    std::process::id(),
                    NEXT_ID.fetch_add(1, Ordering::Relaxed)
                ),
                "test".into(),
                "".into(),
                vec![],
                vec![],
            )),
        };
        let writer =
            RemoteConfigWriter::new(&registration.invariants().unwrap(), &registration.target)
                .unwrap();
        Self {
            registration,
            writer,
            files: vec![],
        }
    }

    fn publish(&mut self, value: Option<&str>) {
        let flags = value.map_or_else(|| serde_json::json!({}), |value| serde_json::json!({
            "flag": {
                "key": "flag", "enabled": true, "variationType": "STRING",
                "variations": {"v": {"key": "v", "value": value}},
                "allocations": [{"key": "static", "splits": [{"variationKey": "v", "shards": []}]}]
            }
        }));
        // Deliberately unchanged: createdAt is not a publication ordering token.
        let bytes = serde_json::to_vec(&serde_json::json!({
            "createdAt": "2026-10-21T00:00:00Z", "environment": {"name": "test"}, "flags": flags
        }))
        .unwrap();
        let name = format!(
            "/phffe-{}-{}",
            std::process::id(),
            NEXT_ID.fetch_add(1, Ordering::Relaxed)
        );
        let mut mapping = NamedShmHandle::create(CString::new(name.clone()).unwrap(), bytes.len())
            .unwrap()
            .map()
            .unwrap();
        mapping.as_slice_mut().copy_from_slice(&bytes);
        self.files.push(mapping.into());
        let path = URL_SAFE_NO_PAD.encode("datadog/0/FFE_FLAGS/cdn/config");
        assert!(self
            .writer
            .write(format!("runtime\n{name}:0:{path}\n").as_bytes()));
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        ffe::clear_config();
        ffe::use_agentless_config(false);
    }
}

fn state(registration: CdnRegistration) -> RemoteConfigState {
    let mut manager = RemoteConfigManager::new_with_registry(
        registration.invariants().unwrap(),
        Arc::new(default_registry().with::<UniversalFlagConfig>().unwrap()),
    );
    manager.track_target(&registration.target);
    RemoteConfigState {
        manager,
        cdn: Some(registration),
        cdn_last_check: None,
        live_debugger: Default::default(),
        dynamic_config: Default::default(),
    }
}

fn value() -> Option<String> {
    use datadog_ffe::rules_based::{
        get_assignment, AssignmentValue, EvaluationContext, ExpectedFlagType,
    };
    let config = ffe::current_config();
    get_assignment(
        config.as_deref(),
        "flag",
        &EvaluationContext::new(None, Arc::new(HashMap::new())),
        ExpectedFlagType::String,
        datadog_ffe::rules_based::now(),
    )
    .ok()
    .map(|assignment| match assignment.value {
        AssignmentValue::String(value) => value.as_str().to_owned(),
        other => panic!("unexpected assignment {other:?}"),
    })
}

#[test]
fn delayed_cdn_reader_cannot_overwrite_a_newer_publication() {
    let _test = ffe::tests::FFE_TEST_LOCK.lock().unwrap();
    let mut fixture = Fixture::new();
    fixture.publish(Some("old"));
    let (staged_tx, staged_rx) = mpsc::channel();
    let (release_tx, release_rx) = mpsc::channel();
    let registration = fixture.registration.clone();
    let delayed = std::thread::spawn(move || {
        let mut reader = state(registration);
        BEFORE_PUBLICATION.with(|hook| {
            *hook.borrow_mut() = Some(Box::new(move || {
                staged_tx.send(()).unwrap();
                release_rx.recv_timeout(Duration::from_secs(5)).unwrap();
            }))
        });
        assert!(ddog_process_remote_configs(&mut reader));
        // This PHP thread exits without another read to repair an obsolete write.
    });
    staged_rx.recv_timeout(Duration::from_secs(5)).unwrap();
    fixture.publish(Some("new"));
    let (done_tx, done_rx) = mpsc::channel();
    let (repoll_tx, repoll_rx) = mpsc::channel();
    let registration = fixture.registration.clone();
    let current = std::thread::spawn(move || {
        let mut reader = state(registration);
        assert!(ddog_process_remote_configs(&mut reader));
        done_tx.send(()).unwrap();
        repoll_rx.recv_timeout(Duration::from_secs(5)).unwrap();
        assert!(!ddog_process_remote_configs(&mut reader));
        value()
    });
    // Without coordination, the newer reader completes before we release the
    // older one. With coordination, it waits and then reads the newest snapshot.
    let _ = done_rx.recv_timeout(Duration::from_millis(100));
    release_tx.send(()).unwrap();
    delayed.join().unwrap();
    repoll_tx.send(()).unwrap();
    assert_eq!(current.join().unwrap().as_deref(), Some("new"));
    assert_eq!(value().as_deref(), Some("new"));
}

#[test]
fn cdn_readers_deduplicate_and_recover_across_directory_replacement() {
    let _test = ffe::tests::FFE_TEST_LOCK.lock().unwrap();
    let mut fixture = Fixture::new();
    fixture.publish(Some("blue"));
    let mut reader = state(fixture.registration.clone());
    assert!(ddog_process_remote_configs(&mut reader));
    let version = ffe::ddog_ffe_config_version();
    let registration = fixture.registration.clone();
    std::thread::spawn(move || {
        let mut reader = state(registration);
        assert!(ddog_process_remote_configs(&mut reader));
        assert_eq!(ffe::ddog_ffe_config_version(), version);
    })
    .join()
    .unwrap();

    reader.reconnect();
    assert_eq!(ffe::delivery_state(), ffe::DeliveryState::Stale);
    fixture.writer = RemoteConfigWriter::new(
        &fixture.registration.invariants().unwrap(),
        &fixture.registration.target,
    )
    .unwrap();
    fixture.publish(Some("blue"));
    assert!(ddog_process_remote_configs(&mut reader));
    assert_eq!(ffe::delivery_state(), ffe::DeliveryState::Ready);
    assert_eq!(ffe::ddog_ffe_config_version(), version);

    fixture.publish(Some("green"));
    assert!(ddog_process_remote_configs(&mut reader));
    assert_eq!(value().as_deref(), Some("green"));
    assert_eq!(ffe::ddog_ffe_config_version(), version + 1);
    fixture.publish(None);
    assert!(ddog_process_remote_configs(&mut reader));
    assert!(ffe::ddog_ffe_has_config());
    assert_eq!(value(), None);
    assert_eq!(ffe::ddog_ffe_config_version(), version + 2);
}
