// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

pub mod headers {
    use libdd_common::regex_engine::{Regex, RegexBuilder};
    use std::collections::HashSet;
    use std::fs::{File, OpenOptions};
    use std::io::{self, BufReader, BufWriter, Read, Seek, Write};
    use std::sync::LazyLock;

    #[derive(Debug, PartialEq, Eq, Hash)]
    struct Span<'a> {
        start: usize,
        end: usize,
        str: &'a str,
    }

    static ITEM_DEFINITION_HEAD: LazyLock<Regex> = LazyLock::new(|| {
        RegexBuilder::new(
            r"^(?:/\*\*(?:[^*]|\*+[^*/])*\*+/\n)?(?:# *(define [a-zA-Z_0-9]+ [^\n]+)|(typedef))",
        )
        .multi_line(true)
        .dot_matches_new_line(true)
        .build()
        .unwrap()
    });

    /// Gather all top level typedef and #define definitions from a C header file
    fn collect_definitions(header: &str) -> Vec<Span<'_>> {
        let mut items = Vec::new();
        let mut start = 0;

        while let Some(head) = ITEM_DEFINITION_HEAD.captures_at(header, start) {
            start = head.get(0).unwrap().start();
            let end: usize;
            if let Some(capture) = head.get(2) {
                let mut depth: i32 = 0;
                let mut typedef_end = None;
                for (pos, c) in header.bytes().enumerate().skip(capture.end()) {
                    match c {
                        b';' if depth == 0 => {
                            typedef_end = Some(pos + 1);
                            break;
                        }
                        b'{' => {
                            depth += 1;
                        }
                        b'}' => {
                            depth = depth
                                .checked_sub(1)
                                .expect("Unmatched closing brace in typedef");
                        }
                        _ => {}
                    }
                }
                let typedef_end = typedef_end.expect("No closing semicolon found for typedef");
                end = typedef_end
                    + header[typedef_end..]
                        .bytes()
                        .take_while(|c| matches!(c, b'\n' | b'\r' | b' '))
                        .count();
            } else if let Some(capture) = head.get(1) {
                let define_end = capture.end();
                end = define_end
                    + header[define_end..]
                        .bytes()
                        .take_while(|c| matches!(c, b'\n' | b'\r' | b' '))
                        .count();
            } else {
                unreachable!(
                    "the regex should only capture typedef and #define, got {:?}",
                    head
                );
            }

            items.push(Span {
                start,
                end,
                str: &header[start..end],
            });
            start = end;
        }
        items
    }

    fn read(f: &mut BufReader<&File>) -> String {
        let mut s = Vec::new();
        f.read_to_end(&mut s).unwrap();
        String::from_utf8(s).unwrap()
    }

    fn write_parts(writer: &mut BufWriter<&File>, parts: &[&str]) -> io::Result<()> {
        writer.get_ref().set_len(0)?;
        writer.rewind()?;
        for part in parts {
            writer.write_all(part.as_bytes())?;
        }
        Ok(())
    }

    fn content_without_defs<'a>(content: &'a str, defs: &[Span]) -> Vec<&'a str> {
        let mut new_content_parts = Vec::new();
        let mut pos = 0;
        for d in defs {
            new_content_parts.push(&content[pos..d.start]);
            pos = d.end;
        }
        new_content_parts.push(&content[pos..]);
        new_content_parts
    }

    /// Strip an optional leading block comment (`/** ... */`) and surrounding
    /// whitespace from a definition span, returning just the statement text
    /// with trailing whitespace removed.
    ///
    /// This lets two definitions that only differ by their doc comment be
    /// compared by their statement alone.
    fn statement_text(def: &str) -> &str {
        let s = def.trim_start();
        let s = if s.starts_with("/*") {
            s.find("*/")
                .map(|end| s[end + 2..].trim_start())
                .unwrap_or(s)
        } else {
            s
        };
        s.trim_end()
    }

    fn is_ident(s: &str) -> bool {
        let mut b = s.bytes();
        b.next()
            .is_some_and(|c| c.is_ascii_alphabetic() || c == b'_')
            && b.all(|c| c.is_ascii_alphanumeric() || c == b'_')
    }

    /// The identifier a typedef introduces, i.e. the last identifier before the
    /// terminating semicolon. Works for `typedef ... NAME;`, pointer typedefs
    /// (`typedef struct X *NAME;`) and full-body typedefs (`... } NAME;`).
    fn typedef_name(stmt: &str) -> Option<&str> {
        let s = stmt.trim_end().strip_suffix(';')?.trim_end();
        let start = s
            .rfind(|c: char| !(c.is_ascii_alphanumeric() || c == '_'))
            .map(|i| i + 1)
            .unwrap_or(0);
        let name = &s[start..];
        is_ident(name).then_some(name)
    }

    /// If the statement is a full-body struct/union/enum typedef
    /// (`typedef struct X { ... } X;`), return the name it defines.
    fn bodied_typedef_name(stmt: &str) -> Option<&str> {
        if !stmt.contains('{') {
            return None;
        }
        if !(stmt.starts_with("typedef struct")
            || stmt.starts_with("typedef union")
            || stmt.starts_with("typedef enum"))
        {
            return None;
        }
        typedef_name(stmt)
    }

    /// If the statement is a bare forward declaration of the form
    /// `typedef struct X X;` (the two identifiers being equal, no body),
    /// return the name `X`.
    fn forward_decl_name(stmt: &str) -> Option<&str> {
        if stmt.contains('{') {
            return None;
        }
        let rest = stmt.strip_prefix("typedef")?.trim_start();
        let rest = ["struct ", "union ", "enum "]
            .iter()
            .find_map(|kw| rest.strip_prefix(kw))?
            .trim_start();
        let body = rest.strip_suffix(';')?;
        let mut tokens = body.split_whitespace();
        let first = tokens.next()?;
        let second = tokens.next()?;
        if tokens.next().is_some() {
            return None;
        }
        (first == second && is_ident(first)).then_some(first)
    }

    /// Remove intra-file duplicate typedefs left behind in the base header.
    ///
    /// cbindgen can emit the same type from multiple crate boundaries, and the
    /// child-vs-base deduplication above only collapses byte-identical
    /// definitions. Two cases slip through and break consumers compiling with
    /// `-Werror -Wtypedef-redefinition`:
    ///
    /// 1. A bare forward declaration `typedef struct X X;` that coexists with the full-body
    ///    definition `typedef struct X { ... } X;`. The forward declaration is redundant and is
    ///    dropped.
    /// 2. Two identical typedef statements whose doc comments differ (so they are not
    ///    byte-identical). The later occurrence is dropped.
    fn dedup_base_typedefs(content: &str) -> String {
        let defs = collect_definitions(content);

        let bodied_names: HashSet<&str> = defs
            .iter()
            .filter_map(|d| bodied_typedef_name(statement_text(d.str)))
            .collect();

        let mut kept_typedefs: HashSet<&str> = HashSet::new();
        let mut result = String::with_capacity(content.len());
        let mut pos = 0;
        for d in &defs {
            let stmt = statement_text(d.str);
            let is_typedef = stmt.starts_with("typedef");
            let drop = is_typedef
                && (forward_decl_name(stmt).is_some_and(|name| bodied_names.contains(name))
                    || !kept_typedefs.insert(stmt));
            if drop {
                result.push_str(&content[pos..d.start]);
                pos = d.end;
            }
        }
        result.push_str(&content[pos..]);
        result
    }

    pub fn dedup_headers(base: &str, headers: &[&str]) {
        let mut unique_child_defs: Vec<String> = Vec::new();
        let mut present = HashSet::new();

        for child_def in headers.iter().flat_map(|p| {
            let child_header = OpenOptions::new().read(true).write(true).open(p).unwrap();

            let child_header_content = read(&mut BufReader::new(&child_header));
            let child_defs = collect_definitions(&child_header_content);
            let new_content_parts = content_without_defs(&child_header_content, &child_defs);

            write_parts(&mut BufWriter::new(&child_header), &new_content_parts).unwrap();

            child_defs
                .into_iter()
                .map(|m| m.str.to_owned())
                .collect::<Vec<_>>()
        }) {
            if present.contains(&child_def) {
                continue;
            }
            unique_child_defs.push(child_def.clone());
            present.insert(child_def);
        }

        let base_header = OpenOptions::new()
            .read(true)
            .write(true)
            .open(base)
            .unwrap();

        let base_header_content = read(&mut BufReader::new(&base_header));
        let base_defs = collect_definitions(&base_header_content);
        let base_defs_set: HashSet<_> = base_defs.iter().map(|s| s.str).collect();

        let mut base_new_parts = vec![&base_header_content[..base_defs.last().unwrap().end]];
        for child_def in &unique_child_defs {
            if base_defs_set.contains(child_def.as_str()) {
                continue;
            }
            base_new_parts.push(child_def);
        }
        base_new_parts.push(&base_header_content[base_defs.last().unwrap().end..]);

        // Definitions moved in from child headers (and any already present in
        // the base) can introduce intra-file duplicate typedefs that the
        // child-vs-base pass above does not catch. Collapse them so the base
        // header compiles under `-Werror -Wtypedef-redefinition`.
        let merged_base: String = base_new_parts.concat();
        let deduped_base = dedup_base_typedefs(&merged_base);
        write_parts(&mut BufWriter::new(&base_header), &[&deduped_base]).unwrap();
    }

    #[cfg(test)]
    mod tests {
        use super::*;

        #[track_caller]
        fn test_regex_match(input: &str, expected: Vec<&str>) {
            let matches = collect_definitions(input);
            assert_eq!(
                matches.len(),
                expected.len(),
                "Expected:\n{expected:#?}\nActual:\n{matches:#?}",
            );
            for (i, m) in matches.iter().enumerate() {
                assert_eq!(m.str, expected[i]);
            }
        }

        #[test]
        fn collect_typedef() {
            let input = "typedef void *Foo;\n";
            let expected = vec!["typedef void *Foo;\n"];
            test_regex_match(input, expected);
        }

        #[test]
        fn collect_typedef_comment() {
            let input = r"
/**
 * This is a typedef for a pointer to Foo.
 */
typedef void *Foo;
";
            let expected = vec![
                r"/**
 * This is a typedef for a pointer to Foo.
 */
typedef void *Foo;
",
            ];
            test_regex_match(input, expected);
        }

        #[test]
        fn collect_struct_typedef() {
            let input = r"/**
 * This is a typedef for a pointer to a struct.
 */
typedef struct ddog_Vec_U8 {
    const uint8_t *ptr;
    uintptr_t len;
    uintptr_t capacity;
} ddog_Vec_U8;
";
            let expected = vec![input];
            test_regex_match(input, expected);
        }

        #[test]
        fn collect_union_typedef() {
            let input = r"/**
 * This is a typedef for a pointer to a union.
 */
typedef union my_union {
    int a;
    float b;
} my_union;
";
            let expected = vec![input];
            test_regex_match(input, expected);
        }

        #[test]
        fn collect_union_nested() {
            let input = r"typedef union ddog_Union_U8 {
    struct inner1 {
        const uint8_t *ptr;
        uintptr_t len;
        uintptr_t capacity;
    } inner;
    struct inner2 {
        const uint8_t *ptr;
        uintptr_t len;
        uintptr_t capacity;
    } inner2;
} ddog_Union_U8;
";
            let expected = vec![input];
            test_regex_match(input, expected);
        }

        #[test]
        fn collect_define() {
            let input = r#"#define FOO __attribute__((unused))
"#;
            let expected = vec![input];
            test_regex_match(input, expected);
        }

        #[test]
        fn collect_multiple_definitions() {
            let input = r"
/**
 * `QueueId` is a struct that represents a unique identifier for a queue.
 * It contains a single field, `inner`, which is a 64-bit unsigned integer.
 */
typedef uint64_t ddog_QueueId;

void foo() {
}
  
/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 **/
typedef struct ddog_Vec_U8 {
    const uint8_t *ptr;
    uintptr_t len;
    uintptr_t capacity;
} ddog_Vec_U8;
            ";

            let expected = vec![
                r"/**
 * `QueueId` is a struct that represents a unique identifier for a queue.
 * It contains a single field, `inner`, which is a 64-bit unsigned integer.
 */
typedef uint64_t ddog_QueueId;

",
                r"/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 **/
typedef struct ddog_Vec_U8 {
    const uint8_t *ptr;
    uintptr_t len;
    uintptr_t capacity;
} ddog_Vec_U8;
            ",
            ];
            test_regex_match(input, expected);
        }

        #[test]
        fn collect_definitions_comments() {
            let header = r"/** foo */
typedef struct ddog_Vec_U8 {
    const uint8_t *ptr;
} ddog_Vec_U8;
";
            let matches = collect_definitions(header);

            assert_eq!(matches.len(), 1);
            assert_eq!(
                matches[0].str,
                r"/** foo */
typedef struct ddog_Vec_U8 {
    const uint8_t *ptr;
} ddog_Vec_U8;
"
            );

            let header = r"/** foo **/ */
typedef struct ddog_Vec_U8 {
    const uint8_t *ptr;
} ddog_Vec_U8;
";
            let matches = collect_definitions(header);

            assert_eq!(matches.len(), 1);
            assert_eq!(
                matches[0].str,
                r"typedef struct ddog_Vec_U8 {
    const uint8_t *ptr;
} ddog_Vec_U8;
"
            );
        }

        #[test]
        fn forward_decl_is_dropped_when_full_body_exists() {
            // The forward declaration may appear either before or after the
            // full-body definition; both must be collapsed to the body.
            let before = r"typedef struct ddog_prof_EncodedProfile ddog_prof_EncodedProfile;

typedef struct ddog_prof_EncodedProfile {
  struct ddog_prof_EncodedProfile *inner;
} ddog_prof_EncodedProfile;
";
            assert_eq!(
                dedup_base_typedefs(before),
                r"typedef struct ddog_prof_EncodedProfile {
  struct ddog_prof_EncodedProfile *inner;
} ddog_prof_EncodedProfile;
"
            );

            let after = r"typedef struct OpaqueStringId {
  uint32_t offset;
} OpaqueStringId;

typedef struct OpaqueStringId OpaqueStringId;
";
            // The blank line that separated the two definitions is retained.
            assert_eq!(
                dedup_base_typedefs(after),
                "typedef struct OpaqueStringId {\n  uint32_t offset;\n} OpaqueStringId;\n\n"
            );
        }

        #[test]
        fn opaque_forward_decl_is_kept_without_body() {
            // An opaque type only has a forward declaration; it must be kept.
            let input =
                "typedef struct ddog_OpaqueCancellationToken ddog_OpaqueCancellationToken;\n";
            assert_eq!(dedup_base_typedefs(input), input);
        }

        #[test]
        fn distinct_alias_typedef_is_kept() {
            // `typedef struct A B;` with A != B is a real alias, not a
            // redundant forward declaration, even if `A` has a body.
            let input = r"typedef struct ddog_OpaqueCancellationToken {
  uint32_t _0;
} ddog_OpaqueCancellationToken;

typedef struct ddog_OpaqueCancellationToken ddog_prof_TokioCancellationToken;
";
            assert_eq!(dedup_base_typedefs(input), input);
        }

        #[test]
        fn exact_duplicate_typedef_with_differing_comment_is_dropped() {
            // Same pointer typedef emitted twice, once bare and once with a doc
            // comment. The first occurrence (and its comment, if any) is kept,
            // the later duplicate is removed.
            let input = r"typedef struct ddog_prof_Function2 *ddog_prof_FunctionId2;

/**
 * A handle to a function.
 */
typedef struct ddog_prof_Function2 *ddog_prof_FunctionId2;
";
            assert_eq!(
                dedup_base_typedefs(input),
                "typedef struct ddog_prof_Function2 *ddog_prof_FunctionId2;\n\n"
            );
        }

        #[test]
        fn unrelated_typedefs_are_untouched() {
            let input = r"typedef uint64_t ddog_QueueId;

typedef struct ddog_Vec_U8 {
  const uint8_t *ptr;
} ddog_Vec_U8;

typedef struct ddog_Slice_U8 *ddog_Slice_U8Handle;
";
            assert_eq!(dedup_base_typedefs(input), input);
        }

        #[test]
        fn is_ident_rejects_leading_digit() {
            // A valid identifier starts with a letter or underscore and may
            // contain digits thereafter.
            assert!(is_ident("ddog_prof_StringId"));
            assert!(is_ident("_hidden"));
            assert!(is_ident("Vec_U8"));

            // A leading digit is not a valid identifier, even though every byte
            // is alphanumeric.
            assert!(!is_ident("0usize"));
            assert!(!is_ident("16"));

            // The empty string is not an identifier.
            assert!(!is_ident(""));
        }
    }
} /* Headers */

fn main() {
    let args: Vec<_> = std::env::args_os().skip(1).collect();
    assert_eq!(args.len(), 14, "expected seven inputs followed by seven outputs");
    for (input, output) in args[..7].iter().zip(&args[7..]) {
        std::fs::copy(input, output).expect("cannot materialize generated header");
        let mut permissions = std::fs::metadata(output)
            .expect("cannot inspect generated header")
            .permissions();
        permissions.set_readonly(false);
        std::fs::set_permissions(output, permissions)
            .expect("cannot make generated header writable");
    }
    let base = args[7].to_str().expect("base output path is not UTF-8");
    let children: Vec<_> = args[8..]
        .iter()
        .map(|path| path.to_str().expect("child output path is not UTF-8"))
        .collect();
    headers::dedup_headers(base, &children);
}
