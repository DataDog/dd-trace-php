<?php

namespace Drupal\taxonomy\Plugin\Validation\Constraint;

use Drupal\Core\Entity\Plugin\Validation\Constraint\CompositeConstraintBase;

/**
 * Validation constraint for changing the term hierarchy in pending revisions.
 *
 * @Constraint(
 *   id = "TaxonomyHierarchy",
 *   label = @Translation("Taxonomy term hierarchy.", context = "Validation"),
 * )
 */
class TaxonomyTermHierarchyConstraint extends CompositeConstraintBase {

  /**
   * The default violation message.
   *
   * @var string
   */
  public $message = 'You can only change the hierarchy for the <em>published</em> version of this term.';

  /**
   * {@inheritdoc}
   */
  public function coversFields() {
    return ['parent', 'weight'];
  }

}
