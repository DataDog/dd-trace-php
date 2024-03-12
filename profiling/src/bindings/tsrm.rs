#[cfg(php_zts)]
mod raw {

    #[macro_export]
    macro_rules! tsrmg_fast_bulk {
        ($offset:expr, $typ:ty) => {
            ($crate::bindings::tsrm_get_ls_cache() as *mut ())
                .add($offset)
                .cast::<$typ>()
        };
    }

    #[macro_export]
    macro_rules! tsrmg_fast {
        ($id:expr, $typ:ty, $element:ident) => {{
            use core::ptr::addr_of_mut;
            #[allow(unused_unsafe)]
            let tmp = unsafe { addr_of_mut!((*$crate::tsrmg_fast_bulk!($id, $typ)).$element) };
            tmp
        }};
    }

    #[macro_export]
    macro_rules! eg {
        ($x:ident) => {
            $crate::tsrmg_fast!(
                crate::bindings::executor_globals_offset,
                crate::bindings::zend_executor_globals,
                $x
            )
        };
    }

    #[macro_export]
    macro_rules! sg {
        ($x:ident) => {
            $crate::tsrmg_fast!(
                crate::bindings::sapi_globals_offset,
                crate::bindings::sapi_globals_struct,
                $x
            )
        };
    }

    pub use eg;
    pub use sg;
    pub use tsrmg_fast;
    pub use tsrmg_fast_bulk;
}

#[cfg(not(php_zts))]
mod raw {

    #[macro_export]
    macro_rules! tsrmg_fast {
        ($global:expr, $x:ident) => {{
            use core::ptr::addr_of_mut;
            let ptr = addr_of_mut!($global);
            #[allow(unused_unsafe)]
            let tmp = unsafe { addr_of_mut!((*ptr).$x) };
            tmp
        }};
    }

    #[macro_export]
    macro_rules! eg {
        ($x:ident) => {
            $crate::tsrmg_fast!(crate::bindings::executor_globals, $x)
        };
    }

    #[macro_export]
    macro_rules! sg {
        ($x:ident) => {
            $crate::tsrmg_fast!(crate::bindings::sapi_globals, $x)
        };
    }

    pub use eg;
    pub use sg;
    pub use tsrmg_fast;
}

pub use raw::*;
