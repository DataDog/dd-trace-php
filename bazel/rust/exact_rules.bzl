"""rules_rust entry points with exact target and execution dependency edges."""

load(
    "@rules_rust//rust/private:rust.bzl",
    _rust_library = "rust_library",
    _rust_proc_macro = "rust_proc_macro",
    _rust_static_library = "rust_static_library",
    _rust_shared_library = "rust_cdylib_library",
)
load(":exact_dep_validation.bzl", "exact_dep_classification")

def _validated_kwargs(name, deps, proc_macro_deps, kwargs):
    validation_name = name + "_exact_dep_classification"
    exact_dep_classification(
        name = validation_name,
        proc_macro_deps = proc_macro_deps,
        target_deps = deps,
        target_compatible_with = kwargs.get("target_compatible_with", []),
    )
    result = dict(kwargs)
    result["compile_data"] = result.get("compile_data", []) + [":" + validation_name]
    return result

def rust_library(name, deps = [], proc_macro_deps = [], **kwargs):
    kwargs = _validated_kwargs(name, deps, proc_macro_deps, kwargs)
    _rust_library(
        name = name,
        deps = deps,
        proc_macro_deps = proc_macro_deps,
        # The public rules_rust macros duplicate every edge into both
        # configurations and let the rule implementation filter by provider.
        # Product resolution already classifies the Cargo edges, so keep the
        # target and execution configurations disjoint here.
        skip_deps_verification = True,
        **kwargs
    )

def rust_proc_macro(name, deps = [], proc_macro_deps = [], **kwargs):
    kwargs = _validated_kwargs(name, deps, proc_macro_deps, kwargs)
    _rust_proc_macro(
        name = name,
        deps = deps,
        proc_macro_deps = proc_macro_deps,
        skip_deps_verification = True,
        **kwargs
    )

def rust_static_library(name, deps = [], proc_macro_deps = [], **kwargs):
    kwargs = _validated_kwargs(name, deps, proc_macro_deps, kwargs)
    _rust_static_library(
        name = name,
        deps = deps,
        proc_macro_deps = proc_macro_deps,
        skip_deps_verification = True,
        **kwargs
    )

def rust_shared_library(name, deps = [], proc_macro_deps = [], **kwargs):
    kwargs = _validated_kwargs(name, deps, proc_macro_deps, kwargs)
    _rust_shared_library(
        name = name,
        deps = deps,
        proc_macro_deps = proc_macro_deps,
        skip_deps_verification = True,
        **kwargs
    )
