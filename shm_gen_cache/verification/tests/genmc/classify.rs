//! The runner's pure logic (tests/genmc.rs), unit-tested by tests/classify.rs:
//! witness discovery, the LLVM version check, the panic-stub IR rewrite and
//! the fail-closed classification of GenMC's output.

use std::sync::LazyLock;

use regex::{Captures, Regex};

/// Each `witness!("NAME", ...)` of a test program.
static WITNESS_CALL: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r#"\bwitness!\(\s*"([A-Za-z0-9_]+)""#).unwrap());

/// Any invocation of `witness!`, however written.
static ANY_WITNESS_CALL: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"\bwitness!\s*[(\[{]").unwrap());

/// Precompiled core entry points that a reached panic would call. GenMC
/// cannot execute undefined functions; [`stub_panics`] gives them bodies
/// that report an assertion violation naming the function instead.
static PANIC_DECL: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(concat!(
        r"(?m)^declare (?P<ret>[^@\n]*?)@(?P<name>_ZN4core(?:9panicking|5slice5index|",
        r"6option|6result|4cell|3fmt)[A-Za-z0-9_$.]*)\((?P<args>[^\n]*)\)",
        r"(?P<rest>[^()\n]*)$",
    ))
    .unwrap()
});

static ASSERT_FAIL_DECL: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"(?m)^declare [^\n]*@__VERIFIER_assert_fail\(").unwrap());

/// The witness names a test program declares, in source order.
///
/// The source is the single source of truth, so the scan must not miss a
/// witness silently: every `witness!` invocation must be the literal form
/// `witness!("NAME", ...)` with an identifier-like name, and names must be
/// unique within the program.
pub fn witness_names(source: &str) -> Result<Vec<String>, String> {
    let names: Vec<String> = WITNESS_CALL
        .captures_iter(source)
        .map(|c| c[1].to_owned())
        .collect();
    let invocations = ANY_WITNESS_CALL.find_iter(source).count();
    if invocations != names.len() {
        return Err(format!(
            "{invocations} witness! invocations, but only {} of the form \
             witness!(\"NAME\", ..) with NAME in [A-Za-z0-9_]: {names:?}",
            names.len()
        ));
    }
    for (i, name) in names.iter().enumerate() {
        if names[..i].contains(name) {
            return Err(format!("witness!() name {name} is not unique: {names:?}"));
        }
    }
    Ok(names)
}

/// rustc's LLVM must not be newer than GenMC's (bitcode is not
/// forward-compatible); the same major version is required.
pub fn check_llvm_versions(rustc_version: &str, opt_version: &str) -> Result<(), String> {
    static OURS: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"(?m)^LLVM version: (\d+)\.").unwrap());
    static THEIRS: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"LLVM version (\d+)\.").unwrap());
    let ours = OURS.captures(rustc_version).map(|c| c[1].to_owned());
    let theirs = THEIRS.captures(opt_version).map(|c| c[1].to_owned());
    match (ours, theirs) {
        (Some(ours), Some(theirs)) if ours == theirs => Ok(()),
        (ours, theirs) => Err(format!(
            "rustc and GenMC use different LLVM major versions (rustc: {ours:?}, \
             GenMC image: {theirs:?}); run from the repository (rust-toolchain.toml) \
             with a matching SGC_GENMC_IMAGE or SGC_GENMC_REPOSITORY"
        )),
    }
}

/// Replaces declarations of core panic entry points by definitions that
/// fail with an assertion naming the entry point.
pub fn stub_panics(ir: &str) -> String {
    let mut messages: Vec<String> = Vec::new();
    let define = |m: &Captures| -> String {
        let index = messages.len();
        let name = &m["name"];
        messages.push(format!(
            "@.genmc_panic.{index} = private unnamed_addr constant [{} x i8] c\"{name}\\00\"",
            name.len() + 1
        ));
        // Keep only the parameter types: attributes may contain parentheses.
        let mut depth = 0i32;
        let mut current = String::new();
        let mut params = Vec::new();
        for ch in m["args"].chars() {
            depth += i32::from(ch == '(');
            depth -= i32::from(ch == ')');
            if ch == ',' && depth == 0 {
                params.push(std::mem::take(&mut current));
            } else {
                current.push(ch);
            }
        }
        if !current.trim().is_empty() {
            params.push(current);
        }
        let types: Vec<&str> = params
            .iter()
            .map(|p| {
                p.split_whitespace()
                    .next()
                    .unwrap_or_else(|| panic!("empty parameter in declaration of {name}"))
            })
            .collect();
        format!(
            "define internal {} @{name}({}) {{\n  call void @__VERIFIER_assert_fail(\
             ptr @.genmc_panic.{index}, ptr @.genmc_panic.{index}, i32 0)\n  unreachable\n}}",
            m["ret"].trim(),
            types.join(", ")
        )
    };
    let mut ir = PANIC_DECL.replace_all(ir, define).into_owned();
    if !messages.is_empty() && !ASSERT_FAIL_DECL.is_match(&ir) {
        ir.push_str("\ndeclare void @__VERIFIER_assert_fail(ptr, ptr, i32)\n");
    }
    ir.push('\n');
    ir.push_str(&messages.join("\n"));
    ir.push('\n');
    ir
}

/// Never mistakes a compiler/checker failure or an unrelated assertion for
/// a witness, nor an incomplete exploration for a proof. `status` is the
/// exit status (negative: killed by that signal); `witness` is `None` for
/// the safety run. Returns a description of the result if it is the
/// expected one.
pub fn classify(status: i32, log: &str, witness: Option<&str>) -> Option<String> {
    if !log.contains("*** Transformation complete.") {
        return None;
    }
    if log.contains("INTERNAL FAILURE")
        || log.contains("LLVM ERROR")
        || log.starts_with("ERROR:")
        || log.contains("\nERROR:")
    {
        return None;
    }
    let Some(witness) = witness else {
        let completed = first_number_after(log, "Number of complete executions explored: ");
        return match completed {
            Some(n)
                if status == 0
                    && log.contains("No errors were detected.")
                    && n.bytes().any(|b| b != b'0') =>
            {
                Some(format!("{n} complete executions"))
            }
            _ => None,
        };
    };
    // With -nthreads several workers may each report a violation before the
    // checker stops: every reported one must be this witness. Their reports
    // can run together ("...: GENMC_WITNESS_V1Error: Safety violation!"), so
    // a message ends at a newline or at the next report. Rust assertion
    // locations are those of the harness, so match the message instead of a
    // source line (the assertion messages are unique per witness).
    let violations = reports(log, "Error: ");
    let messages = reports(log, "Assertion violation: ");
    let expected = format!("GENMC_WITNESS_{witness}");
    if status == 42
        && !violations.is_empty()
        && violations.iter().all(|v| *v == "Safety violation!")
        && messages.len() == violations.len()
        && messages.iter().all(|m| *m == expected)
    {
        return Some(format!("{expected} reachable"));
    }
    None
}

/// GenMC's complete and blocked execution counts, if reported.
pub fn execution_counts(log: &str) -> (Option<&str>, Option<&str>) {
    (
        first_number_after(log, "Number of complete executions explored: "),
        first_number_after(log, "Number of blocked executions seen: "),
    )
}

/// The digits after the first occurrence of `prefix` followed by at least
/// one digit (regex `prefix([0-9]+)`).
fn first_number_after<'a>(log: &'a str, prefix: &str) -> Option<&'a str> {
    log.match_indices(prefix).find_map(|(i, _)| {
        let rest = &log[i + prefix.len()..];
        let len = rest.bytes().take_while(u8::is_ascii_digit).count();
        (len > 0).then(|| &rest[..len])
    })
}

/// Every message following `prefix`, each ending at a newline, at the next
/// `Error: ` report or at the end of the log (regex
/// `prefix(.*?)(?=Error: |\n|\Z)`, matches not overlapping).
fn reports<'a>(log: &'a str, prefix: &str) -> Vec<&'a str> {
    let mut found = Vec::new();
    let mut pos = 0;
    while let Some(i) = log[pos..].find(prefix) {
        let start = pos + i + prefix.len();
        let rest = &log[start..];
        let end = [rest.find("Error: "), rest.find('\n')]
            .into_iter()
            .flatten()
            .min()
            .unwrap_or(rest.len());
        found.push(&rest[..end]);
        pos = start + end;
    }
    found
}
