# Security & Safety

Secrets and sensitive configuration, CI and supply chain, PII, and safety
inside customer processes.

**When planning:** take the rules below into account when designing the
change.

**When reviewing:** also inspect commit messages and the PR description.
Report a finding when the diff matches a rule's `Flag:` line, with the leak
path (source to sink).

## 1. Customer-process safety

### SEC-1.1: Add struct fields before the embedded `zend_object`
- Flag: new fields after `zend_object std` (directly, or after an embedded
  struct that ends in one, such as `ddtrace_span_data`); declared properties
  of userland subclasses overwrite them.
- ❌ `struct { ddtrace_span_data span; uint64_t new_field; }`
- ✅ `struct { uint64_t new_field; ddtrace_span_data span; }`

### SEC-1.2: Bound recursion over user-controlled data
- Flag: recursive walks of user arrays/objects without a recursion guard
  (see COR-4.3); self-references crash the customer process.

### SEC-1.3: Never install files with the executable bit
- Flag: installer logic (`datadog-setup.php`) that sets exec permissions;
  only shared objects and data files are installed.

## 2. CI and supply chain

### SEC-2.1: Scope automated dependency updates to the intended path
- Flag: a dependabot (or similar) entry that applies to all submodules or
  directories when only one was meant.

### SEC-2.2: Put CI egress rules in the central gitlab-runner egress ACL
- Flag: network egress allow rules added as pod annotations in
  `.gitlab-ci.yml` instead of the dd-source `gitlab-runner-egress-acl`
  project config.
