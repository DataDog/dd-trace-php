extern crate proc_macro;

use proc_macro::TokenStream;

#[proc_macro]
pub fn hermetic_answer(_input: TokenStream) -> TokenStream {
    "42usize".parse().expect("static token stream must parse")
}
