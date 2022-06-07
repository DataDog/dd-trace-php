use env_logger::Target;
pub use log::Level;
pub use log::LevelFilter;
use std::fs::File;
use std::os::unix::io::FromRawFd;

pub fn log_init(level_filter: LevelFilter) {
    let target = unsafe {
        let fd = libc::dup(libc::STDERR_FILENO);
        if fd == -1 {
            // logging isn't going to work, sorry!
            return;
        }
        Box::new(File::from_raw_fd(fd))
    };

    env_logger::builder()
        .filter_level(LevelFilter::Off)
        .filter_module("datadog_php_profiling", level_filter)
        .target(Target::Pipe(target))
        .format_timestamp_micros()
        .init();
}
