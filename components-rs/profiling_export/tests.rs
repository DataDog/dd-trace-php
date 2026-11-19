use super::*;
use libdd_profiling::api::{Function, Label, Location};
use std::mem::{align_of, size_of};
use std::sync::mpsc::{TrySendError, sync_channel};

fn profile() -> Profile {
    Profile::try_new(&[SampleType::WallTime], None, SystemTime::now()).unwrap()
}

fn no_metadata() -> Option<serde_json::Value> {
    None
}

fn upload<'a>(
    profile: Profile,
    endpoint: AgentEndpoint,
    internal_metadata: &'a mut dyn FnMut() -> Option<serde_json::Value>,
) -> Upload<'a> {
    Upload {
        profile,
        end_time: SystemTime::now(),
        duration: Some(Duration::from_secs(1)),
        endpoint,
        library_name: "test",
        library_version: "test",
        tags: Vec::new(),
        internal_metadata,
        info: None,
        process_tags: None,
    }
}

#[test]
fn owner_preserves_layout_and_can_be_sent_to_uploader() {
    fn assert_send<T: Send>() {}
    assert_send::<Profile>();
    assert_eq!(size_of::<Profile>(), size_of::<InternalProfile>());
    assert_eq!(align_of::<Profile>(), align_of::<InternalProfile>());
    assert!(std::mem::needs_drop::<Profile>());
    assert!(!std::mem::needs_drop::<ManuallyDrop<InternalProfile>>());
}

#[test]
fn abandoned_profile_drops() {
    drop(profile());
}

#[test]
fn rejected_upload_drops_profile() {
    for disconnected in [false, true] {
        let mut metadata = no_metadata;
        let (sender, receiver) = sync_channel::<Upload>(0);
        let mut receiver = Some(receiver);
        if disconnected {
            drop(receiver.take());
        }
        let error = sender
            .try_send(upload(
                profile(),
                AgentEndpoint::Uri("http://localhost:8126".into()),
                &mut metadata,
            ))
            .err()
            .expect("upload should be rejected");
        assert!(matches!(error, TrySendError::Disconnected(_)) == disconnected);
        drop(error);
    }
}

#[test]
fn discarded_queued_upload_drops_profile() {
    let mut metadata = no_metadata;
    let (sender, receiver) = sync_channel(1);
    assert!(
        sender
            .send(upload(
                profile(),
                AgentEndpoint::Uri("http://localhost:8126".into()),
                &mut metadata,
            ))
            .is_ok()
    );
    drop(receiver);
}

#[test]
fn invalid_endpoint_is_an_error() {
    let mut metadata = no_metadata;
    let result = serialize_and_upload(upload(
        profile(),
        AgentEndpoint::Uri("http://[".into()),
        &mut metadata,
    ));
    assert!(result.is_err());
}

#[test]
fn failed_upload_serialization_skips_internal_metadata() {
    // The uploader's metadata callback snapshots CPU time. A profile that is
    // never encoded must not consume the counters it would have reported.
    let profile = Profile::try_new(&[SampleType::Custom1], None, SystemTime::now()).unwrap();
    let mut calls = 0;
    let mut metadata = || {
        calls += 1;
        None
    };
    let result = serialize_and_upload(upload(
        profile,
        AgentEndpoint::Uri("http://localhost:8126".into()),
        &mut metadata,
    ));
    assert!(result.is_err());
    assert_eq!(calls, 0);
}

#[test]
fn internal_metadata_is_built_after_serialization_and_sent() {
    use std::io::{Read, Write};
    use std::net::TcpListener;

    const MARKER: &str = "metadata-built-after-serialization";
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let address = listener.local_addr().unwrap();
    let agent = std::thread::spawn(move || {
        let (mut stream, _) = listener.accept().unwrap();
        stream
            .set_read_timeout(Some(Duration::from_secs(10)))
            .unwrap();
        let mut request = Vec::new();
        let mut buffer = [0; 4096];
        while !String::from_utf8_lossy(&request).contains(MARKER) {
            match stream.read(&mut buffer) {
                Ok(0) | Err(_) => break,
                Ok(read) => request.extend_from_slice(&buffer[..read]),
            }
        }
        stream
            .write_all(b"HTTP/1.1 202 Accepted\r\ncontent-length: 0\r\nconnection: close\r\n\r\n")
            .unwrap();
        String::from_utf8_lossy(&request).contains(MARKER)
    });

    let mut calls = 0;
    let mut metadata = || {
        calls += 1;
        Some(serde_json::json!({ "marker": MARKER }))
    };
    let status = serialize_and_upload(upload(
        profile(),
        AgentEndpoint::Uri(format!("http://{address}")),
        &mut metadata,
    ))
    .unwrap();
    assert_eq!(status, 202);
    assert_eq!(calls, 1);
    assert!(agent.join().unwrap(), "agent did not receive the metadata");
}

#[test]
fn serialization_error_is_reported() {
    // An unresolved custom sample type is a real libdatadog serialization error.
    let profile = Profile::try_new(&[SampleType::Custom1], None, SystemTime::now()).unwrap();
    assert!(serialize(profile).is_err());
}

#[test]
fn timestamped_samples_outlive_borrowed_inputs_and_serialize() {
    let mut profile = profile();
    {
        let function = String::from("leaf");
        let file = String::from("workload.php");
        let endpoint = String::from("GET /test");
        let label_value = String::from("borrowed");
        let values = [42];
        profile
            .try_add_sample(
                Sample {
                    locations: vec![Location {
                        function: Function {
                            name: &function,
                            filename: &file,
                            ..Function::default()
                        },
                        line: 12,
                        ..Location::default()
                    }],
                    values: &values,
                    labels: vec![
                        Label {
                            key: "test",
                            str: &label_value,
                            ..Label::default()
                        },
                        Label {
                            key: "local root span id",
                            num: 7,
                            ..Label::default()
                        },
                    ],
                },
                NonZeroI64::new(123),
            )
            .unwrap();
        profile.add_endpoint(7, &endpoint).unwrap();
        profile.add_endpoint_count(&endpoint, 1).unwrap();
        profile
            .add_upscaling_rule(
                &[0],
                "test",
                &label_value,
                UpscalingInfo::Proportional { scale: 2.0 },
            )
            .unwrap();
    }
    let buffer = serialize(profile).unwrap();
    assert!(!buffer.is_empty());
}

#[test]
fn failed_mutation_leaves_profile_usable() {
    let mut profile = profile();
    assert!(
        profile
            .add_upscaling_rule(&[1], "", "", UpscalingInfo::Proportional { scale: 2.0 })
            .is_err()
    );
    assert!(
        profile
            .try_add_sample(
                Sample {
                    locations: vec![],
                    values: &[],
                    labels: vec![]
                },
                None
            )
            .is_err()
    );
    profile
        .try_add_sample(
            Sample {
                locations: vec![],
                values: &[1],
                labels: vec![],
            },
            None,
        )
        .unwrap();
    assert!(!serialize(profile).unwrap().is_empty());
}
