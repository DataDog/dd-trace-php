extern crate proc_macro;

use proc_macro::TokenStream;

#[proc_macro]
pub fn hermetic_answer(_input: TokenStream) -> TokenStream {
    // Exercise unwinding inside a loaded proc macro without invoking the
    // compiler's panic hook. The execution EH runtime must remain available.
    assert!(std::panic::catch_unwind(|| std::panic::resume_unwind(Box::new(42usize))).is_err());
    "42usize".parse().expect("static token stream must parse")
}
