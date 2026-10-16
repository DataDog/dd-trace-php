use super::*;
use libdd_profiling::api::{Function, Label, Location};
use std::mem::{align_of, size_of};
use std::sync::mpsc::{sync_channel, TrySendError};

fn releases() -> (usize, usize) {
    implementation::RELEASES.with(|counts| counts.get())
}

fn profile() -> Profile {
    implementation::RELEASES.with(|counts| counts.set((0, 0)));
    Profile::try_new(&[SampleType::WallTime], None, SystemTime::now()).unwrap()
}

fn upload(profile: Profile, endpoint: AgentEndpoint) -> Upload {
    Upload {
        profile,
        end_time: SystemTime::now(),
        duration: Some(Duration::from_secs(1)),
        endpoint,
        library_name: "test",
        library_version: "test",
        tags: Vec::new(),
        internal_metadata: None,
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
fn abandoned_profile_is_destroyed_by_runtime_once() {
    drop(profile());
    assert_eq!(releases(), (1, 0));
}

#[test]
fn rejected_upload_releases_profile_once() {
    for disconnected in [false, true] {
        let profile = profile();
        let (sender, receiver) = sync_channel::<Upload>(0);
        let mut receiver = Some(receiver);
        if disconnected {
            drop(receiver.take());
        }
        let error = sender
            .try_send(upload(
                profile,
                AgentEndpoint::Uri("http://localhost:8126".into()),
            ))
            .err()
            .expect("upload should be rejected");
        assert!(matches!(error, TrySendError::Disconnected(_)) == disconnected);
        assert_eq!(releases(), (0, 0));
        drop(error);
        assert_eq!(releases(), (1, 0));
    }
}

#[test]
fn discarded_queued_upload_releases_profile_once() {
    let profile = profile();
    let (sender, receiver) = sync_channel(1);
    assert!(sender
        .send(upload(
            profile,
            AgentEndpoint::Uri("http://localhost:8126".into())
        ))
        .is_ok());
    assert_eq!(releases(), (0, 0));
    drop(receiver);
    assert_eq!(releases(), (1, 0));
}

#[test]
fn invalid_endpoint_drops_before_consuming_profile() {
    let result = serialize_and_upload(upload(profile(), AgentEndpoint::Uri("http://[".into())));
    assert!(result.is_err());
    assert_eq!(releases(), (1, 0));
}

#[test]
fn serialization_error_does_not_run_forwarding_drop_twice() {
    implementation::RELEASES.with(|counts| counts.set((0, 0)));
    // An unresolved custom sample type is a real libdatadog serialization error.
    let profile = Profile::try_new(&[SampleType::Custom1], None, SystemTime::now()).unwrap();
    assert!(serialize(profile).is_err());
    assert_eq!(releases(), (0, 1));
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
    assert_eq!(releases(), (0, 1));
}

#[test]
fn failed_mutation_leaves_profile_destructible_and_usable() {
    let mut profile = profile();
    assert!(profile
        .add_upscaling_rule(&[1], "", "", UpscalingInfo::Proportional { scale: 2.0 })
        .is_err());
    assert!(profile
        .try_add_sample(
            Sample {
                locations: vec![],
                values: &[],
                labels: vec![]
            },
            None
        )
        .is_err());
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
    assert_eq!(profile.only_for_testing_num_aggregated_samples(), 1);
    drop(profile);
    assert_eq!(releases(), (1, 0));
}
