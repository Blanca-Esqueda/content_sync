<?php

namespace Drupal\content_sync\DependencyResolver;

use Drupal\Core\Serialization\Yaml;
use Drupal\content_sync\Content\ContentDatabaseStorage;

/**
 * Class ImportQueueResolver.
 *
 * @package Drupal\content_sync\DependencyResolver
 */
class ExportQueueResolver implements ContentSyncResolverInterface {

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

      // Get a decoded entity.
      $entity = $entity = $this->getEntity($identifier, $normalized_entities);

      // Process dependencies first.
      if (!empty($entity['_content_sync']['entity_dependencies'])) {
        foreach ($entity['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
          $this->depthFirstSearch($visited, $references, $normalized_entities);
        }
      }

      // Process translations' dependencies if any.
      if (!empty($entity["_translations"])) {
        foreach ($entity["_translations"] as $translation) {
          if (!empty($translation['_content_sync']['entity_dependencies'])) {
            foreach ($translation['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
              $this->depthFirstSearch($visited, $references, $normalized_entities);
            }
          }
        }
      }

      if (!isset($visited[$identifier])) {
        list($entity_type_id, $bundle, $uuid) = explode('.', $identifier);
        $visited[$identifier] = [
          'entity_type' => $entity_type_id,
          'entity_uuid' => $uuid,
        ];
      }

    }
  }

  /**
   * Gets an entity.
   *
   * @param $identifier
   *   An entity identifier to process.
   * @param $normalized_entities
   *   An array of entity identifiers to process.
   *
   * @return bool|array
   *   Array of entity data to export or FALSE if no entity found (db error).
   */
  protected function getEntity($identifier, $normalized_entities) {
    if (!empty($normalized_entities[$identifier])) {
      $entity = $normalized_entities[$identifier];
    }
    else {
      $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
      $entity = $activeStorage->cs_read($identifier);
    }
    return $entity;
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
  public function resolve(array $normalized_entities, $visited = []) {
    foreach ($normalized_entities as $identifier => $entity) {
      $this->depthFirstSearch($visited, [$identifier], $normalized_entities);
    }

    // Reverse the array to adjust it to an array_pop-driven iterator.
    return array_reverse($visited);
  }

}
