use crate::zend::sapi_request_info;
use log::warn;
use once_cell::sync::OnceCell;
use std::borrow::Cow;
use std::collections::HashMap;
use std::ffi::{CStr, OsStr};
use std::fmt::{Display, Formatter};
use std::os::unix::ffi::OsStrExt;
use std::path::Path;

// todo: unify with ../component/sapi
#[derive(Copy, Clone, Eq, PartialEq)]
#[repr(C)]
pub enum Sapi {
    Unknown = 0,
    Apache2Handler,
    CgiFcgi,
    Cli,
    CliServer,
    Embed,
    FrankenPHP,
    FpmFcgi,
    Litespeed,
    PhpDbg,
    Tea,
}

impl Sapi {
    pub fn from_name(name: &str) -> Sapi {
        static SAPIS: OnceCell<HashMap<&str, Sapi>> = OnceCell::new();
        let sapis = SAPIS.get_or_init(|| {
            HashMap::from_iter([
                ("apache2handler", Sapi::Apache2Handler),
                ("cgi-fcgi", Sapi::CgiFcgi),
                ("cli", Sapi::Cli),
                ("cli-server", Sapi::CliServer),
                ("embed", Sapi::Embed),
                ("frankenphp", Sapi::FrankenPHP),
                ("fpm-fcgi", Sapi::FpmFcgi),
                ("litespeed", Sapi::Litespeed),
                ("phpdbg", Sapi::PhpDbg),
                ("tea", Sapi::Tea),
            ])
        });

        match sapis.get(name) {
            None => Sapi::Unknown,
            Some(sapi) => *sapi,
        }
    }

    pub fn request_script_name<'a>(
        &self,
        sapi_request_info: sapi_request_info,
    ) -> Option<Cow<'a, str>> {
        match self {
            /* Right now all we need is CLI support, but theoretically it can
             * be obtained for web requests too if we care.
             */
            Sapi::Cli => {
                if sapi_request_info.argc > 0 && !sapi_request_info.argv.is_null() {
                    // Safety: It's not null; the VM should do the rest.
                    let cstr = unsafe { CStr::from_ptr(*sapi_request_info.argv) };
                    let bytes = cstr.to_bytes();
                    if !bytes.is_empty() {
                        let osstr = OsStr::from_bytes(bytes);
                        Path::new(osstr)
                            .file_name()
                            .map(|file| {
                                let bytes = file.as_bytes();
                                match std::str::from_utf8(bytes) {
                                    Ok(str) => Cow::Borrowed(str),
                                    Err(_) => {
                                        let value = String::from_utf8_lossy(bytes);
                                        warn!(
                                            "sapi_globals.request_info.argv[0] contained non-utf8 data: {}",
                                            value
                                        );
                                        value
                                    }
                                }
                            })
                    } else {
                        None
                    }
                } else {
                    None
                }
            }
            _ => None,
        }
    }
}

impl AsRef<str> for Sapi {
    fn as_ref(&self) -> &str {
        match self {
            Sapi::Unknown => "unknown",
            Sapi::Apache2Handler => "apache2handler",
            Sapi::CgiFcgi => "cgi-fcgi",
            Sapi::Cli => "cli",
            Sapi::CliServer => "cli-server",
            Sapi::Embed => "embed",
            Sapi::FrankenPHP => "frankenphp",
            Sapi::FpmFcgi => "fpm-fcgi",
            Sapi::Litespeed => "litespeed",
            Sapi::PhpDbg => "phpdbg",
            Sapi::Tea => "tea",
        }
    }
}

impl Display for Sapi {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.as_ref())
    }
}

#[cfg(test)]
mod tests {
    use crate::Sapi;

    #[test]
    fn test_is_recognized_sapi() {
        let recognized_sapis = [
            ("apache2handler", Sapi::Apache2Handler),
            ("cgi-fcgi", Sapi::CgiFcgi),
            ("cli", Sapi::Cli),
            ("cli-server", Sapi::CliServer),
            ("embed", Sapi::Embed),
            ("frankenphp", Sapi::FrankenPHP),
            ("fpm-fcgi", Sapi::FpmFcgi),
            ("litespeed", Sapi::Litespeed),
            ("phpdbg", Sapi::PhpDbg),
            ("tea", Sapi::Tea),
        ];

        assert!(recognized_sapis.iter().all(|(name, expected_sapi)| {
            Sapi::from_name(name) == *expected_sapi && expected_sapi.to_string() == *name
        }));

        /* These used to be SAPIs, but have since been removed. I think
         * that makes them good testing candidates for unknown SAPIs.
         */
        let unrecognized_sapis = [
            "aolserver",
            "caudium",
            "Continuity",
            "isapi",
            "nsapi",
            "pi3web",
            "roxen",
            "webjames",
            // Also, the empty string is not a SAPI.
            "",
        ];

        assert!(unrecognized_sapis
            .into_iter()
            .all(|name| Sapi::from_name(name) == Sapi::Unknown));
    }
}
