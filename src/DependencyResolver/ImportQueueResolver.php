<?php

namespace Drupal\content_sync\DependencyResolver;

/**
 * Class ImportQueueResolver.
 *
 * @package Drupal\content_sync\DependencyResolver
 */
class ImportQueueResolver implements ContentSyncResolverInterface {

  /**
   * Builds a graph placing the deepest vertexes at the first place.
   *
   * @param array $visited
   *   Array of vertexes to return.
   * @param array $identifiers
   *   Array of entity identifiers to process.
   * @param array $normalized_entities
   *   Parsed entities to import.
   */
  protected function depthFirstSearch(array &$visited, array $identifiers, array $normalized_entities) {
    foreach ($identifiers as $identifier) {


    foreach ($normalized_entities as $identifier => $entity) {
      $ids = explode('.', $identifier);
      list($entity_type_id, $bundle, $uuid) = $ids;
      if (!empty($normalized_entities[$identifier])) {
        $entity = $normalized_entities[$identifier];
        if (!empty($entity['_content_sync']['entity_dependencies'])) {
          foreach ($entity['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
            $this->depthFirstSearch($visited, $references, $normalized_entities);
          }
        }
        if (!isset($visited[$identifier])) {
          $visited[$identifier] = [
            'entity_type_id' => $entity_type_id,
            'decoded_entity' => $entity,
          ];
        }
      }
    }
  }

  /**
   * Creates a queue.
   *
   * @param array $normalized_entities
   *   Parsed entities to import.
   *
   * @return array
   *   Queue to be processed within a batch process.
   */
  public function resolve(array $normalized_entities) {
    $visited = [];
    foreach ($normalized_entities as $identifier => $entity) {
      $this->depthFirstSearch($visited, [$identifier], $normalized_entities);
    }
    // Reverse the array to adjust it to an array_pop-driven iterator.
    return array_reverse($visited);
  }

}
