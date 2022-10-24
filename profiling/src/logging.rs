use env_logger::Target;
pub use log::Level;
pub use log::LevelFilter;
use std::fs::File;
use std::os::unix::io::FromRawFd;

pub fn log_init(level_filter: LevelFilter) {
    /* This duplicates the stderr file descriptor and writes to the dup. This
     * is done because on nearly all released PHP versions, the PHP CLI will
     * close the stderr stream after rshutdown. However, we still log things
     * after that. In connection with this, various other log implementations
     * were panicking when writing to the closed stderr.
     */

    // Safety: this is safe, it's just "unsafe" because it's a call into C.
    let fd = unsafe { libc::dup(libc::STDERR_FILENO) };
    if fd != -1 {
        // Safety: the fd is a valid and open file descriptor, and the File has sole ownership.
        let target = Box::new(unsafe { File::from_raw_fd(fd) });
        env_logger::builder()
            .filter_level(LevelFilter::Off)
            .filter_module("datadog_php_profiling", level_filter)
            .target(Target::Pipe(target))
            .format_timestamp_micros()
            .init();
    }
}
