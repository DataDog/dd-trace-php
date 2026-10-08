//! fork() smoke test (no test harness: the process has one thread when it
//! forks). The mapping is shared with the child; a participant handle
//! inherited by the child is refused, and the child can register its own.
//! Unix only: elsewhere the binary lists no tests.

#[cfg(unix)]
use shm_gen_cache::Config;
#[cfg(unix)]
use shm_gen_cache::ffi::{
    Status, ddog_sgc_cache_free, ddog_sgc_cache_new, ddog_sgc_insert, ddog_sgc_lookup,
    ddog_sgc_participant_register, ddog_sgc_participant_unregister,
};

fn main() {
    // Enough of the libtest protocol for cargo-nextest: `--list` names the
    // one test (none with `--ignored`, or without fork()), and `--exact
    // fork` runs it.
    let args: Vec<String> = std::env::args().skip(1).collect();
    if args.iter().any(|a| a == "--list") {
        if cfg!(unix) && !args.iter().any(|a| a == "--ignored") {
            println!("fork: test");
        }
    } else {
        #[cfg(unix)]
        {
            unsafe { run() }
            println!("fork: ok");
        }
    }
}

#[cfg(unix)]
unsafe fn lookup(
    p: *mut shm_gen_cache::ffi::FfiParticipant,
    key: &[u8],
    out: &mut [u64; 4],
) -> (Status, usize) {
    let mut len = 0;
    let status = unsafe {
        ddog_sgc_lookup(
            p,
            key.len() as u64,
            key.as_ptr(),
            key.len(),
            out.as_mut_ptr(),
            32,
            &mut len,
        )
    };
    (status, len)
}

#[cfg(unix)]
unsafe fn run() {
    unsafe {
        let config = Config {
            participant_capacity: 4,
            bucket_count: 64,
            max_key_size: 16,
            max_value_size: 32,
            ..Config::DEFAULT
        };
        let mut cache = std::ptr::null_mut();
        assert_eq!(ddog_sgc_cache_new(&config, &mut cache), Status::Ok);
        let mut parent = std::ptr::null_mut();
        assert_eq!(
            ddog_sgc_participant_register(cache, &mut parent),
            Status::Ok
        );
        assert_eq!(
            ddog_sgc_insert(
                parent,
                6,
                b"parent".as_ptr(),
                6,
                b"from the parent".as_ptr(),
                15
            ),
            Status::Ok
        );

        let pid = libc::fork();
        assert!(pid >= 0);
        if pid == 0 {
            let mut out = [0u64; 4];
            // The inherited participant's slot belongs to the parent thread.
            let inherited_lookup = lookup(parent, b"parent", &mut out).0 == Status::InvalidArgument;
            let inherited_insert = ddog_sgc_insert(parent, 1, b"x".as_ptr(), 1, b"y".as_ptr(), 1)
                == Status::InvalidArgument;
            ddog_sgc_participant_unregister(parent); // frees the handle only
            let mut child = std::ptr::null_mut();
            let registered = ddog_sgc_participant_register(cache, &mut child) == Status::Ok;
            let (status, len) = lookup(child, b"parent", &mut out);
            let sees_parent = status == Status::Ok
                && len == 15
                && std::slice::from_raw_parts(out.as_ptr().cast::<u8>(), 15) == b"from the parent";
            let inserted = ddog_sgc_insert(
                child,
                5,
                b"child".as_ptr(),
                5,
                b"from the child".as_ptr(),
                14,
            ) == Status::Ok;
            ddog_sgc_participant_unregister(child);
            ddog_sgc_cache_free(cache);
            let ok = inherited_lookup && inherited_insert && registered && sees_parent && inserted;
            libc::_exit(if ok { 0 } else { 1 });
        }
        let mut wstatus = 0;
        assert_eq!(libc::waitpid(pid, &mut wstatus, 0), pid);
        assert!(
            libc::WIFEXITED(wstatus) && libc::WEXITSTATUS(wstatus) == 0,
            "child failed: {wstatus}"
        );

        // The parent's participant still works and sees the child's insert.
        let mut out = [0u64; 4];
        let (status, len) = lookup(parent, b"child", &mut out);
        assert_eq!((status, len), (Status::Ok, 14));
        assert_eq!(
            &std::slice::from_raw_parts(out.as_ptr().cast::<u8>(), 14),
            b"from the child"
        );
        ddog_sgc_participant_unregister(parent);
        ddog_sgc_cache_free(cache);
    }
}
