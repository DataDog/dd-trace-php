"""Bridges canonical PHP product-matrix roles to concrete package files."""

load(":artifacts.bzl", "product_artifact")
load("//bazel/php:product_matrix.bzl", "product_matrix_rows")

def _row(row_name):
    for row in product_matrix_rows():
        if row.name == row_name:
            return row
    fail("unknown product matrix row: %s" % row_name)

def product_matrix_artifact_binding(row_name, product, role):
    """Returns the one-file binding required to package a canonical role."""
    row = _row(row_name)
    if product not in row.build_artifact_roles:
        fail("%s is not a canonical product for row %s" % (product, row_name))
    if role not in row.build_artifact_roles[product]:
        fail("%s/%s has no canonical role %s" % (row_name, product, role))
    return struct(
        identity = row.build_artifact_roles[product][role],
        product = product,
        role = role,
        scope_key = row.product_build_keys[product],
        validation_destination = row.validation_bundle_artifact_roles[product][role],
    )

def product_matrix_artifact(name, row_name, product_target, product, role, **kwargs):
    """Projects one manifest-validated product File for `ssi_payload`.

    The selected target is a one-file `DefaultInfo`; its typed projection
    provider carries the producer validation markers that `ssi_payload`
    consumes independently.
    """
    binding = product_matrix_artifact_binding(row_name, product, role)
    product_artifact(
        name = name,
        product = binding.product,
        product_target = product_target,
        role = binding.role,
        scope_key = binding.scope_key,
        **kwargs
    )
