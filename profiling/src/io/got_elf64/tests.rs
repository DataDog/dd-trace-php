//! Exercise real loader mappings rather than mocking mprotect or GOT slots.
//! Requires a Linux ELF64 host, a C compiler (CC, default cc), and /proc/self/maps.
//! Each test runs its loader/mprotect operations in a child process so it cannot
//! change mappings used by other tests, including when an assertion fails.

use super::*;
use crate::profiling::io::GotSymbolOverwrite;
use std::ffi::CString;
use std::process::Command;

unsafe extern "C" fn replacement_getpid() -> libc::pid_t {
    -4242
}

struct Fixture {
    // Keep the library and its temporary directory alive until after restoration.
    handle: *mut c_void,
    path: CString,
    call: unsafe extern "C" fn() -> libc::pid_t,
    _directory: tempfile::TempDir,
}

impl Fixture {
    fn new(full_relro: bool) -> Self {
        let directory = tempfile::tempdir().unwrap();
        let source = directory.path().join("fixture.c");
        let library = directory.path().join("fixture.so");
        std::fs::write(
            &source,
            "#include <unistd.h>\npid_t fixture_getpid(void) { return getpid(); }\n",
        )
        .unwrap();
        let output = Command::new(std::env::var_os("CC").unwrap_or_else(|| "cc".into()))
            .args(["-shared", "-fPIC", "-fplt", "-Wl,-z,relro"])
            .arg(if full_relro {
                "-Wl,-z,now"
            } else {
                "-Wl,-z,lazy"
            })
            .arg(&source)
            .arg("-o")
            .arg(&library)
            .output()
            .expect("RELRO tests require a C compiler");
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );

        let path = CString::new(library.as_os_str().as_encoded_bytes()).unwrap();
        unsafe {
            let handle = libc::dlopen(path.as_ptr(), libc::RTLD_NOW | libc::RTLD_LOCAL);
            assert!(!handle.is_null(), "dlopen failed");
            let symbol = libc::dlsym(handle, c"fixture_getpid".as_ptr());
            assert!(!symbol.is_null(), "fixture symbol missing");
            Self {
                handle,
                path,
                call: std::mem::transmute::<*mut c_void, unsafe extern "C" fn() -> libc::pid_t>(
                    symbol,
                ),
                _directory: directory,
            }
        }
    }

    fn install(&self, restores: &mut Vec<GotSlotRestore>) {
        struct Install<'a> {
            fixture: &'a Fixture,
            state: GotHookState<'a>,
            found: bool,
            success: bool,
        }

        unsafe extern "C" fn visit(info: *mut dl_phdr_info, _: usize, data: *mut c_void) -> c_int {
            let install = &mut *(data as *mut Install<'_>);
            if !(*info).dlpi_name.is_null()
                && CStr::from_ptr((*info).dlpi_name) == install.fixture.path.as_c_str()
            {
                install.found = true;
                install.success =
                    override_got_entry(info, install.fixture.path.as_bytes(), &mut install.state);
                return 1;
            }
            0
        }

        let mut overwrites = [GotSymbolOverwrite {
            symbol_name: "getpid",
            new_func: replacement_getpid as *mut (),
        }];
        let mut install = Install {
            fixture: self,
            state: GotHookState {
                overwrites: &mut overwrites,
                restores,
            },
            found: false,
            success: false,
        };
        unsafe {
            libc::dl_iterate_phdr(Some(visit), &mut install as *mut _ as *mut c_void);
        }
        assert!(
            install.found && install.success,
            "fixture GOT installation failed"
        );
        assert_eq!(unsafe { (self.call)() }, -4242, "hook was not called");
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        unsafe {
            libc::dlclose(self.handle);
        }
    }
}

fn maps() -> String {
    std::fs::read_to_string("/proc/self/maps").unwrap()
}

fn permissions(maps: &str, address: usize) -> &str {
    for line in maps.lines() {
        let mut fields = line.split_whitespace();
        let (start, end) = fields.next().unwrap().split_once('-').unwrap();
        let start = usize::from_str_radix(start, 16).unwrap();
        let end = usize::from_str_radix(end, 16).unwrap();
        if (start..end).contains(&address) {
            return fields.next().unwrap();
        }
    }
    panic!("GOT address {address:#x} is not mapped");
}

fn check_permissions(full_relro: bool, reinstall: bool) {
    let fixture = Fixture::new(full_relro);
    let original_result = unsafe { (fixture.call)() };
    assert_eq!(original_result, unsafe { libc::getpid() });
    let before = maps();
    let mut restores = Vec::new();
    fixture.install(&mut restores);
    assert_eq!(restores.len(), 1, "expected one getpid GOT slot");
    let slot = restores[0].slot;
    let expected = if full_relro { "r--p" } else { "rw-p" };
    assert_eq!(
        permissions(&before, slot),
        expected,
        "fixture has unexpected protection"
    );
    assert_eq!(
        permissions(&maps(), slot),
        expected,
        "first install changed protection"
    );

    if reinstall {
        fixture.install(&mut restores);
        assert_eq!(restores.len(), 1, "duplicate restore entry");
        assert_eq!(
            permissions(&maps(), slot),
            expected,
            "reinstall changed GOT page protection"
        );
    }

    assert!(unsafe { restore_symbols(&mut restores) });
    assert!(restores.is_empty());
    assert_eq!(
        unsafe { (fixture.call)() },
        original_result,
        "original function was not restored"
    );
    assert_eq!(
        permissions(&maps(), slot),
        expected,
        "shutdown changed GOT page protection"
    );
}

#[test]
fn full_relro_reinstall_preserves_protection() {
    check_permissions_isolated(true, true);
}

#[test]
fn full_relro_shutdown_preserves_protection() {
    check_permissions_isolated(true, false);
}

#[test]
fn partial_relro_preserves_writable_plt() {
    check_permissions_isolated(false, true);
}

fn check_permissions_isolated(full_relro: bool, reinstall: bool) {
    const CHILD_TEST: &str = "DD_RELRO_TEST_CHILD";
    // libtest names the test thread after the fully qualified test function.
    // Re-execute only this test; the marker prevents recursively spawning it.
    let thread = std::thread::current();
    let name = thread.name().expect("RELRO test must run through libtest");
    if std::env::var(CHILD_TEST).as_deref() == Ok(name) {
        check_permissions(full_relro, reinstall);
        return;
    }

    let output = Command::new(std::env::current_exe().unwrap())
        .args(["--exact", name, "--nocapture"])
        .env(CHILD_TEST, name)
        .output()
        .expect("failed to start isolated RELRO test");
    assert!(
        output.status.success(),
        "isolated RELRO test {name} failed ({}):\n{}\n{}",
        output.status,
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr),
    );
}
