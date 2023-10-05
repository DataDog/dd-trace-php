<?php

/**
 * @file
 * Post update functions for Custom Block.
 */

/**
 * Implements hook_removed_post_updates().
 */
function block_content_removed_post_updates() {
  return [
    'block_content_post_update_add_views_reusable_filter' => '9.0.0',
  ];
}

/**
 * Clear the entity type cache.
 */
function block_content_post_update_entity_changed_constraint() {
  // Empty post_update hook.
}
