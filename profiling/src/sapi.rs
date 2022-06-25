use once_cell::sync::OnceCell;
use std::collections::HashMap;

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
            ("fpm-fcgi", Sapi::FpmFcgi),
            ("litespeed", Sapi::Litespeed),
            ("phpdbg", Sapi::PhpDbg),
            ("tea", Sapi::Tea),
        ];

        assert!(recognized_sapis
            .into_iter()
            .all(|(name, expected_sapi)| { Sapi::from_name(name) == expected_sapi }));

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
