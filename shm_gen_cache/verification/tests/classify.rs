//! Fail-closed classification tests for the GenMC runner (tests/genmc.rs).

#[path = "genmc/classify.rs"]
mod classify;

use classify::{check_llvm_versions, classify, execution_counts, stub_panics, witness_names};

const TRANSFORMED: &str = "*** Compilation complete.\n*** Transformation complete.\n";

fn success() -> String {
    format!("{TRANSFORMED}No errors were detected.\nNumber of complete executions explored: 7\n")
}

fn witness() -> String {
    format!(
        "{TRANSFORMED}Error: Safety violation!\nEvent (0, 260) in graph:\n\
         \t(0, 1): MALLOC x\n\nAssertion violation: GENMC_WITNESS_V1\n"
    )
}

/// `witness()` without the compilation preamble: another worker's report.
fn witness_report() -> String {
    witness().strip_prefix(TRANSFORMED).unwrap().to_owned()
}

#[test]
fn completed_safety() {
    assert!(classify(0, &success(), None).is_some());
}

#[test]
fn zero_executions_are_not_a_proof() {
    assert!(classify(0, &success().replace(": 7", ": 0"), None).is_none());
    assert!(classify(0, &success().replace(": 7", ": 00"), None).is_none());
}

#[test]
fn exact_witness_message() {
    assert!(classify(42, &witness(), Some("V1")).is_some());
    assert!(classify(42, &witness(), Some("V2")).is_none());
    assert!(classify(42, &witness().replace("_V1", "_V10"), Some("V1")).is_none());
}

#[test]
fn parallel_witness_reports() {
    // Workers' reports may run together without a newline.
    let joined = format!("{}{}", witness().trim_end_matches('\n'), witness_report());
    assert!(classify(42, &joined, Some("V1")).is_some());
    let other = witness_report().replace("_V1", "_V2");
    let joined_other = format!("{}{other}", witness().trim_end_matches('\n'));
    assert!(classify(42, &joined_other, Some("V1")).is_none());
    assert!(classify(42, &format!("{}{other}", witness()), Some("V1")).is_none());
}

#[test]
fn unrelated_violations_are_not_witnesses() {
    let check = witness().replace("GENMC_WITNESS_V1", "tests/t.rs:9: check failed: x");
    assert!(classify(42, &check, Some("V1")).is_none());
    let check_report = check.strip_prefix(TRANSFORMED).unwrap();
    assert!(classify(42, &format!("{}{check_report}", witness()), Some("V1")).is_none());
    let race = witness().replace("Safety violation!", "Non-atomic race!");
    assert!(classify(42, &race, Some("V1")).is_none());
    let bare = format!("{TRANSFORMED}Error: Safety violation!\n");
    assert!(classify(42, &bare, Some("V1")).is_none());
    let panic = witness().replace(
        "GENMC_WITNESS_V1",
        "_ZN4core9panicking18panic_bounds_check17hE",
    );
    assert!(classify(42, &panic, Some("V1")).is_none());
}

#[test]
fn witness_must_fail_and_safety_must_pass() {
    assert!(classify(0, &witness(), Some("V1")).is_none());
    assert!(classify(42, &witness(), None).is_none());
    assert!(classify(0, &success(), Some("V1")).is_none());
}

#[test]
fn tool_failures_never_count() {
    for status in [-11, 1, 17, 133, 134] {
        assert!(classify(status, &witness(), Some("V1")).is_none());
    }
    for diagnostic in [
        "INTERNAL FAILURE",
        "LLVM ERROR",
        "\nERROR: Tried to execute an unknown external function",
    ] {
        let failed_witness = format!("{}{diagnostic}", witness());
        assert!(classify(42, &failed_witness, Some("V1")).is_none());
        let failed_safety = format!("{}{diagnostic}", success());
        assert!(classify(0, &failed_safety, None).is_none());
    }
}

#[test]
fn compilation_is_not_verification() {
    let log = witness().replace("*** Transformation complete.\n", "");
    assert!(classify(42, &log, Some("V1")).is_none());
}

#[test]
fn counts_are_reported() {
    let log = format!("{}Number of blocked executions seen: 3\n", success());
    assert_eq!(execution_counts(&log), (Some("7"), Some("3")));
    assert_eq!(execution_counts(TRANSFORMED), (None, None));
}

#[test]
fn core_panics_become_assertion_failures() {
    let ir = "declare void @_ZN4core9panicking18panic_bounds_check17h01E\
              (i64 noundef, i64 noundef, ptr noalias noundef readonly align 8 \
              dereferenceable(24)) unnamed_addr #3\n\
              declare void @other(i64)\n";
    let out = stub_panics(ir);
    assert!(out.contains(
        "define internal void @_ZN4core9panicking18panic_bounds_check17h01E(i64, i64, ptr) {"
    ));
    assert!(out.contains("call void @__VERIFIER_assert_fail("));
    assert!(out.contains("declare void @other(i64)"));
    assert!(out.contains("declare void @__VERIFIER_assert_fail(ptr, ptr, i32)"));
}

#[test]
fn witnesses_are_discovered_in_source_order() {
    let source = r#"
        witness!("V2", a);
        witness!(
            "MISS",
            b,
        );
        check!(c);
    "#;
    assert_eq!(witness_names(source).unwrap(), ["V2", "MISS"]);
    assert_eq!(witness_names("check!(x);").unwrap(), Vec::<String>::new());
}

#[test]
fn witness_discovery_fails_closed() {
    assert!(witness_names(r#"witness!("V1", a); witness!("V1", b);"#).is_err());
    assert!(witness_names(r#"witness!("V1", a); witness!(NAME, b);"#).is_err());
    assert!(witness_names(r#"witness!["V1", a];"#).is_err());
    assert!(witness_names(r#"witness! ("V1", a);"#).is_err());
    assert!(witness_names(r#"witness!("lower-case", a);"#).is_err());
}

#[test]
fn llvm_major_versions_must_match() {
    let rustc = "rustc 1.91.1\nhost: x86_64-unknown-linux-gnu\nLLVM version: 21.1.2\n";
    assert!(check_llvm_versions(rustc, "Ubuntu LLVM version 21.1.8\n").is_ok());
    assert!(check_llvm_versions(rustc, "Ubuntu LLVM version 20.1.8\n").is_err());
    assert!(check_llvm_versions(rustc, "no version").is_err());
    assert!(check_llvm_versions("rustc 1.91.1\n", "LLVM version 21.1.8").is_err());
}
