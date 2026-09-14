# LLM Validation — `dd-apm-sdk-review`

This folder is how we test the review skill. It is **not** a PHPUnit run.
The cases live here; the runner lives in [`ddoghq/llm-validation-platform`](https://github.com/ddoghq/llm-validation-platform).

Same gate as the other tracer repos. One starter case on purpose.

## Add a rule

1. Extend [`.agents/dd-apm-sdk-review-overrides/reviewers/`](../.agents/dd-apm-sdk-review-overrides/reviewers/).
2. Add the path to `instruction_files` in [`config.yaml`](./config.yaml).
3. Copy the starter case in [`suites/dd-apm-sdk-review.yaml`](./suites/dd-apm-sdk-review.yaml).
4. List the case id under `presets.gate.cases` if you want CI to run it.

Never edit `.agents/skills/dd-apm-sdk-review/` in this repo.
