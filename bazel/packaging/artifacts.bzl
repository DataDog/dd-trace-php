"""Typed, fail-closed product artifact interface for packaging."""

ProductArtifactInfo = provider(
    doc = "One product's concrete files keyed by canonical manifest artifact role.",
    fields = {
        "artifacts": "Dictionary of canonical role name to exactly one File.",
        "product": "Canonical product name from the product matrix.",
        "roles": "Sorted tuple of canonical artifact roles.",
        "scope_key": "Manifest build scope key shared by equivalent ABI rows.",
        "validation": "Depset of validation artifacts required before packaging.",
    },
)

ProductArtifactProjectionInfo = provider(
    doc = "One projected product artifact and the validation files required to package it.",
    fields = {
        "file": "The single projected File.",
        "product": "Canonical product name.",
        "role": "Canonical artifact role.",
        "scope_key": "Manifest build scope key.",
        "validation": "Depset of validation artifacts required before packaging.",
    },
)

def product_artifact_providers(product, scope_key, artifacts, required_roles, validation = None):
    """Validates a product's role map and returns its public providers.

    Product rules use this helper in their implementation.  `required_roles`
    comes from the normalized product manifest contract for that product and
    scope; missing or extra roles fail during analysis.
    """
    if type(artifacts) != "dict":
        fail("%s/%s artifacts must be a role-to-File dictionary" % (product, scope_key))
    expected = sorted(required_roles)
    actual = sorted(artifacts.keys())
    if actual != expected:
        fail("%s/%s artifact roles mismatch: expected=%r actual=%r" % (
            product,
            scope_key,
            expected,
            actual,
        ))
    paths = {}
    for role in actual:
        artifact = artifacts[role]
        if type(artifact) != "File":
            fail("%s/%s role %s must contain exactly one File, got %r" % (
                product,
                scope_key,
                role,
                artifact,
            ))
        if artifact.path in paths:
            fail("%s/%s roles %s and %s resolve to the same file %s" % (
                product,
                scope_key,
                paths[artifact.path],
                role,
                artifact.path,
            ))
        paths[artifact.path] = role

    if validation == None:
        validation = depset()
    elif type(validation) == "list":
        validation = depset(validation)
    elif type(validation) != "depset":
        fail("%s/%s validation must be a list or depset of Files" % (product, scope_key))

    info = ProductArtifactInfo(
        artifacts = artifacts,
        product = product,
        roles = tuple(actual),
        scope_key = scope_key,
        validation = validation,
    )
    output_groups = {role: depset([artifacts[role]]) for role in actual}
    output_groups["_validation"] = validation
    return [
        DefaultInfo(
            files = depset(artifacts.values(), transitive = [validation]),
        ),
        info,
        OutputGroupInfo(**output_groups),
    ]

def _product_artifact_impl(ctx):
    info = ctx.attr.product_target[ProductArtifactInfo]
    role = ctx.attr.role
    if info.product != ctx.attr.product or info.scope_key != ctx.attr.scope_key:
        fail("%s product scope mismatch: expected %s/%s, got %s/%s" % (
            ctx.attr.product_target.label,
            ctx.attr.product,
            ctx.attr.scope_key,
            info.product,
            info.scope_key,
        ))
    if role not in info.artifacts:
        fail("%s does not expose required %s/%s role %s; available=%r" % (
            ctx.attr.product_target.label,
            info.product,
            info.scope_key,
            role,
            info.roles,
        ))
    artifact = info.artifacts[role]
    return [
        DefaultInfo(files = depset([artifact])),
        ProductArtifactProjectionInfo(
            file = artifact,
            product = info.product,
            role = role,
            scope_key = info.scope_key,
            validation = info.validation,
        ),
        OutputGroupInfo(_validation = info.validation),
    ]

product_artifact = rule(
    implementation = _product_artifact_impl,
    attrs = {
        "product": attr.string(mandatory = True),
        "product_target": attr.label(mandatory = True, providers = [ProductArtifactInfo]),
        "role": attr.string(mandatory = True),
        "scope_key": attr.string(mandatory = True),
    },
    doc = "Projects one validated product role to a single packaging input File.",
)
