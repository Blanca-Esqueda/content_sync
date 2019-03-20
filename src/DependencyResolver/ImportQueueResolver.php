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
    $validate_dependecies = TRUE;
    foreach ($identifiers as $identifier) {
      $ids = explode('.', $identifier);
      list($entity_type_id, $bundle, $uuid) = $ids;
      // Check if entity was sent on the files to import.
      if (!empty($normalized_entities[$identifier])) {
        $entity = $normalized_entities[$identifier];
        // Check dependencies
        if (!empty($entity['_content_sync']['entity_dependencies'])) {
          foreach ($entity['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
            if(!is_null($references)){
              $dependency = $this->depthFirstSearch($visited, $references, $normalized_entities);
            if ($dependency !== TRUE && !is_null($dependency)){
               $validate_dependecies = FALSE;
               $visited['Missing'][$identifier][] = $dependency;
            }
            }
          }
        }
        if (!isset($visited[$identifier]) && $validate_dependecies) {
          $visited[$identifier] = [
            'entity_type_id' => $entity_type_id,
            'decoded_entity' => $entity,
          ];
          return TRUE;
        }
      }else{
        //Verify if dependency exist in the site
        $return = (bool) \Drupal::database()->queryRange('SELECT 1 FROM {cs_db_snapshot} WHERE name = :name', 0, 1, [
        ':name' => $identifier])->fetchField();

        if (!$return){
          // Look for the reference in CS folder
          $file_path = content_sync_get_content_directory(CONFIG_SYNC_DIRECTORY)."/entities/".$entity_type_id."/".$bundle."/".$identifier.".yml";
          $entity = file_get_contents($file_path);
          if($entity){
            $normalized_entities[$identifier] = Yaml::decode($entity);
            $this->depthFirstSearch($visited,[$identifier], $normalized_entities);
            return TRUE;
          }else{
            $return = $identifier;
          }
        }
        return $return;
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
