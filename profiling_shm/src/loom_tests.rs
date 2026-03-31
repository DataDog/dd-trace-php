use crate::shm::ShmRegion;
use crate::InternError;

fn create() -> ShmRegion {
    unsafe { ShmRegion::create().expect("ShmRegion::create failed") }
}

/// Two threads interning the same string concurrently must get back the same
/// index (dedup), and `get_str` on that index must return the original string.
#[test]
fn intern_str_dedup() {
    loom::model(|| {
        let shm = create();
        let shm2 = shm.clone();

        let t = loom::thread::spawn(move || shm2.intern_str("hello"));

        let r0 = shm.intern_str("hello");
        let r1 = t.join().unwrap();

        match (r0, r1) {
            (Ok(i0), Ok(i1)) => {
                assert_eq!(i0, i1, "same string interned twice gave different indices");
                assert_eq!(shm.get_str(i0), Some("hello"));
            }
            (Err(InternError::WouldBlock), Ok(i)) | (Ok(i), Err(InternError::WouldBlock)) => {
                assert_eq!(shm.get_str(i), Some("hello"));
            }
            // The C11 model allows spurious failures on compare exchange
            // weak so this branch is reachable in loom.
            (Err(InternError::WouldBlock), Err(InternError::WouldBlock)) => {}
            (r0, r1) => panic!("unexpected results: {r0:?}, {r1:?}"),
        }
    });
}

/// Two threads interning different strings concurrently must both produce
/// readable indices.
#[test]
fn intern_str_different() {
    loom::model(|| {
        let shm = create();
        let shm2 = shm.clone();

        let t = loom::thread::spawn(move || shm2.intern_str("world"));

        let r0 = shm.intern_str("hello");
        let r1 = t.join().unwrap();

        if let Ok(i) = r0 {
            assert_eq!(shm.get_str(i), Some("hello"));
        }
        if let Ok(i) = r1 {
            assert_eq!(shm.get_str(i), Some("world"));
        }
    });
}

/// Concurrent intern + get_str: one thread interns while another reads.
/// The reader may see None (string not yet published) or Some with the correct
/// value, but must never see a corrupt value.
#[test]
fn concurrent_intern_and_get() {
    loom::model(|| {
        let shm = create();
        let shm2 = shm.clone();

        // Snapshot the count: the next intern will land at this index.
        let next = crate::StringIndex(shm.string_len());
        let t = loom::thread::spawn(move || shm2.get_str(next).map(|s| s == "concurrent"));

        let _ = shm.intern_str("concurrent");

        if let Some(matches) = t.join().unwrap() {
            assert!(matches, "get_str returned wrong value");
        }
    });
}

/// Two threads interning different functions concurrently must both produce
/// readable indices.
#[test]
fn intern_function_different() {
    loom::model(|| {
        let shm = create();

        // Use pre-interned string indices so we don't need concurrent string interning.
        let name_a = crate::STRING_THREAD_ID;
        let name_b = crate::STRING_THREAD_NAME;
        let file = crate::STRING_EMPTY;

        let shm2 = shm.clone();
        let t = loom::thread::spawn(move || shm2.intern_function(name_b, file));

        let r0 = shm.intern_function(name_a, file);
        let r1 = t.join().unwrap();

        if let Ok(fi) = r0 {
            assert_eq!(shm.get_function(fi), Some((name_a, file)));
        }
        if let Ok(fi) = r1 {
            assert_eq!(shm.get_function(fi), Some((name_b, file)));
        }
    });
}
