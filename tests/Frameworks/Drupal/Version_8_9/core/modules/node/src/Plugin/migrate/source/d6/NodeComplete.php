<?php

namespace Drupal\node\Plugin\migrate\source\d6;

/**
 * Gets all node revisions from the source, including translation revisions.
 *
 * @MigrateSource(
 *   id = "d6_node_complete",
 *   source_module = "node"
 * )
 */
class NodeComplete extends NodeRevision {

  /**
   * The join options between the node and the node_revisions_table.
   */
  const JOIN = 'n.nid = nr.nid';

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    $query->orderBy('nr.vid');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'nid' => [
        'type' => 'integer',
        'alias' => 'n',
      ],
      'vid' => [
        'type' => 'integer',
        'alias' => 'nr',
      ],
      'language' => [
        'type' => 'string',
        'alias' => 'n',
      ],
    ];
  }

}
