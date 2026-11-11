//! Native-link integration test: this executable has its own Rust allocator
//! and runtime, and links only the localized parser archive, never its rlib.
#[allow(dead_code)]
#[path = "../api.rs"]
mod api;
use api::{AppsecBodyAllocator, AppsecBodyMessagePack, AppsecBodyStatus};
use std::alloc::{GlobalAlloc, Layout, System};
use std::cell::Cell;
use std::ffi::c_void;
use std::sync::atomic::{AtomicUsize, Ordering::Relaxed};

extern "C" {
    fn ddog_appsec_body_private_json(
        ptr: *const u8,
        len: usize,
        depth: i32,
        allocator: AppsecBodyAllocator,
    ) -> AppsecBodyMessagePack;
    fn ddog_appsec_body_private_xml(
        ptr: *const u8,
        len: usize,
        depth: i32,
        allocator: AppsecBodyAllocator,
    ) -> AppsecBodyMessagePack;
    fn ddog_appsec_body_private_drop(body: AppsecBodyMessagePack);
    fn malloc(size: usize) -> *mut u8;
    fn free(ptr: *mut u8);
}

struct HostAllocator;
#[global_allocator]
static HOST: HostAllocator = HostAllocator;
static HOST_PANICS: AtomicUsize = AtomicUsize::new(0);
thread_local! {
    static TRACK: Cell<bool> = const { Cell::new(false) };
    static HOST_ALLOCATIONS: Cell<usize> = const { Cell::new(0) };
    static LIVE: Cell<usize> = const { Cell::new(0) };
}
unsafe impl GlobalAlloc for HostAllocator {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        if TRACK.get() {
            HOST_ALLOCATIONS.set(HOST_ALLOCATIONS.get() + 1);
        }
        System.alloc(layout)
    }
    unsafe fn dealloc(&self, ptr: *mut u8, layout: Layout) {
        System.dealloc(ptr, layout);
    }
}

struct Context {
    calls: usize,
    fail_after: usize,
    exercise_host: bool,
}

fn main() {
    std::panic::set_hook(Box::new(|info| {
        HOST_PANICS.fetch_add(1, Relaxed);
        if info.payload().downcast_ref::<&str>() != Some(&"host panic") {
            eprintln!("{info}");
        }
    }));
    let cases = [
        (false, br#"{"escaped":"\u0041\n\t\"","arr":[1,2,{"key":true}],"bad":"\u00"# as &[u8]),
        (false, br#"{"escaped":"\u0041\n","arr":[1,2,{"key":true}]}"#),
        (true, br#"<!DOCTYPE r [<!ENTITY e "<c a='yes'>hello</c>"><!ATTLIST r d CDATA 'default'>]><r x='a &amp; b'>&e; text<![CDATA[&raw]]></r>"#),
        (true, b"<root><child a='b'>text\r\nmore &amp; data</child><broken"),
    ];
    let mut points = 0;
    for (xml, input) in cases {
        let baseline = parse(xml, input, usize::MAX, false);
        for fail_after in 0..baseline {
            parse(xml, input, fail_after, false);
            // A failure must not poison the allocator or the panic runtime.
            assert_eq!(parse(xml, input, usize::MAX, false), baseline);
            points += 1;
        }
        parse(xml, input, usize::MAX, true);
    }

    // Each new thread initializes its own private runtime TLS. Repeated OOM
    // must leave no request allocations behind when that thread terminates.
    let threads: Vec<_> = (0..4)
        .map(|_| {
            std::thread::spawn(|| {
                for _ in 0..20 {
                    parse(true, b"<r a='value'>some text</r>", 4, false);
                    parse(false, br#"{"s":"escaped\nstring"}"#, usize::MAX, false);
                }
            })
        })
        .collect();
    for thread in threads {
        thread.join().unwrap();
    }
    assert_eq!(
        HOST_PANICS.load(Relaxed),
        0,
        "parser changed the host panic hook"
    );
    #[cfg(panic = "unwind")]
    {
        assert!(std::panic::catch_unwind(|| panic!("host panic")).is_err());
        assert_eq!(HOST_PANICS.load(Relaxed), 1);
    }
    println!(
        "allocator isolation OK; {points} persistent OOM points; recovery and concurrent calls OK"
    );
}

fn parse(xml: bool, input: &[u8], fail_after: usize, exercise_host: bool) -> usize {
    assert_eq!(LIVE.get(), 0);
    let mut context = Context {
        calls: 0,
        fail_after,
        exercise_host,
    };
    let allocator = AppsecBodyAllocator {
        context: (&mut context as *mut Context).cast(),
        allocate,
        free: release,
    };
    HOST_ALLOCATIONS.set(0);
    TRACK.set(true);
    let body = unsafe {
        if xml {
            ddog_appsec_body_private_xml(input.as_ptr(), input.len(), 30, allocator)
        } else {
            ddog_appsec_body_private_json(input.as_ptr(), input.len(), 30, allocator)
        }
    };
    TRACK.set(false);
    if !exercise_host {
        assert_eq!(HOST_ALLOCATIONS.get(), 0, "parser used host allocator");
    } else {
        assert!(
            HOST_ALLOCATIONS.get() > 0,
            "host allocation used parser allocator"
        );
    }
    if fail_after < context.calls {
        assert_eq!(
            body.status,
            AppsecBodyStatus::OutOfMemory,
            "allocation {fail_after}"
        );
        assert!(body.ptr.is_null());
        assert_eq!(body.len, 0);
        assert_eq!(LIVE.get(), 0, "leak after failing allocation {fail_after}");
        assert_eq!(context.calls, fail_after + 1, "allocated again after OOM");
    } else {
        assert!(matches!(
            body.status,
            AppsecBodyStatus::Complete | AppsecBodyStatus::Partial
        ));
        assert!(body.len > 0);
        assert_eq!(LIVE.get(), 1, "scratch allocation escaped parse call");
    }
    // No live reference to the callback context is needed for deallocation.
    let calls = context.calls;
    drop(context);
    unsafe {
        ddog_appsec_body_private_drop(body);
    }
    assert_eq!(LIVE.get(), 0, "output allocation leaked");
    calls
}

unsafe extern "C" fn allocate(context: *mut c_void, size: usize) -> *mut u8 {
    let context = &mut *context.cast::<Context>();
    let call = context.calls;
    context.calls += 1;
    if context.exercise_host {
        // This code uses the host's std even while inside a parser callback.
        std::hint::black_box(std::env::var_os("PATH"));
    }
    if call >= context.fail_after {
        return std::ptr::null_mut();
    }
    let ptr = malloc(size);
    if !ptr.is_null() {
        LIVE.set(LIVE.get() + 1);
    }
    ptr
}

unsafe extern "C" fn release(ptr: *mut u8) {
    LIVE.set(LIVE.get() - 1);
    free(ptr);
}
