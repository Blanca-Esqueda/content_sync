<?php

namespace Drupal\content_sync\DependencyResolver;

use Drupal\Core\Serialization\Yaml;
use Drupal\content_sync\Content\ContentDatabaseStorage;

/**
 * Class ExportQueueResolver.
 *
 * @package Drupal\content_sync\DependencyResolver
 */
class ExportQueueResolver implements ContentSyncResolverInterface {
  /**
   * Add dependencies of the entity to export to the queue.
   *
   * @param array $entity
   *   Parsed entity to export.
   *
   */
  public function resolve(array $entity) {
    // Check dependencies
    if (!empty($entity['_content_sync']['entity_dependencies'])) {
      foreach ($entity['_content_sync']['entity_dependencies'] as $dependency_type => $dependencies) {
        foreach ($dependencies as $dependency) {
          if(!is_null($dependency)){
            $ids = explode('.', $dependency);
            list($entity_type_id, $bundle, $uuid) = $ids;
            $query = \Drupal::database()->merge('cs_queue')
                                      ->key(['identifier' =>  $uuid, 'entity_type' => $entity_type_id])
                                      ->fields([
                                          'identifier' =>  $uuid,
                                          'entity_type' => $entity_type_id,
                                          'id_type' => 'entity_uuid',
                                      ])
                                      ->execute();
          }
        }
      }
    }
  }
}
