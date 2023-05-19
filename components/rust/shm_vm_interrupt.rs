use std::env;
use std::ffi::c_void;
use std::num::NonZeroUsize;
use nix::fcntl::OFlag;
use nix::libc::off_t;
use nix::sys::mman::{MapFlags, mmap, ProtFlags, shm_open};
use nix::sys::stat::Mode;
use nix::unistd::{ftruncate, getpid};
use ddtelemetry::ipc::interface::blocking::TelemetryTransport;
use ddtelemetry::ipc::interface::{blocking, InstanceId};
use ddtelemetry_ffi::{MaybeError, try_c};

#[no_mangle]
pub extern "C" fn ddtrace_share_shm_vm_interrupt(transport: &mut Box<TelemetryTransport>, instance_id: &InstanceId, ptr: *mut c_void) -> MaybeError {
    let path = format!("/libdatadog-shm-vm_interrupt-{}", getpid()).into();

    // TODO: For real usage, use Mode::empty(), unlink immediately and transfer the fd instead
    let fd = try_c!(shm_open(&path,  OFlag::O_CREAT | OFlag::O_RDWR, Mode::S_IWUSR | Mode::S_IRUSR | Mode::S_IRGRP | Mode::S_IWGRP | Mode::S_IROTH | Mode::S_IWOTH));
    const page_size: usize = 0x1000;
    try_c!(ftruncate(fd, page_size as off_t));
    let baseptr = ptr as usize & !(page_size - 1);
    let mut page = [0u8; page_size];
    unsafe {
        std::ptr::copy_nonoverlapping(baseptr as *mut u8, &mut page as *mut u8, page_size);
        mmap(NonZeroUsize::new(baseptr), NonZeroUsize::new(page_size).unwrap(), ProtFlags::PROT_READ | ProtFlags::PROT_WRITE, MapFlags::MAP_SHARED | MapFlags::MAP_FIXED, fd, 0).expect("mmap somehow failed");
        std::ptr::copy_nonoverlapping(&mut page as *mut u8, baseptr as *mut u8, page_size);
    }
    try_c!(blocking::register_profiling_interrupt_shared_mapping(transport, instance_id, path, ptr as usize - baseptr));

    MaybeError::None
}