<?php

namespace Drupal\content_sync\DependencyResolver;

use Drupal\Core\Serialization\Yaml;

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

      // Get a decoded entity. FALSE means no need to import.
      try {
        $entity = $this->getEntity($identifier, $normalized_entities);
      } catch (\Exception $e) {
        $entity = FALSE;
        $visited['Missing'][$identifier][] = $e->getMessage();
      }

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

      if (!isset($visited[$identifier]) && $entity) {
        list($entity_type_id, $bundle, $uuid) = explode('.', $identifier);
        $visited[$identifier] = [
          'entity_type_id' => $entity_type_id,
          'decoded_entity' => $entity,
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
   * @return bool|mixed
   *   Decoded entity or FALSE if an entity already exists and doesn't require to be imported.
   *
   * @throws \Exception
   */
  protected function getEntity($identifier, $normalized_entities) {
    if (!empty($normalized_entities[$identifier])) {
      $entity = $normalized_entities[$identifier];
    }
    else {
      list($entity_type_id, $bundle, $uuid) = explode('.', $identifier);
      $file_path = content_sync_get_content_directory('sync') . "/entities/" . $entity_type_id . "/" . $bundle . "/" . $identifier . ".yml";
      $raw_entity = file_get_contents($file_path);

      // Problems to open the .yml file.
      if (!$raw_entity) throw new \Exception("Dependency {$identifier} is missing.");

      $entity = Yaml::decode($raw_entity);
    }
    return $entity;
  }

  /**
   * Checks if a dependency exists in the site.
   *
   * @param $identifier
   *   An entity identifier to process.
   *
   * @return bool
   */
  protected function entityExists($identifier) {
    return (bool) \Drupal::database()->queryRange('SELECT 1 FROM {cs_db_snapshot} WHERE name = :name', 0, 1, [
      ':name' => $identifier])->fetchField();
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
    $visited = [];
    foreach ($normalized_entities as $identifier => $entity) {
      $this->depthFirstSearch($visited, [$identifier], $normalized_entities);
    }
    // Reverse the array to adjust it to an array_pop-driven iterator.
    return array_reverse($visited);
  }

}
