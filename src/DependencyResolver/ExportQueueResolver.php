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
   * Add dependecies to the quew\ue.
   *
   * @param array $identifiers
   *   Array of entity identifiers to process.
   * @param array $normalized_entities
   *   Parsed entities to import.
   */
  protected function depthFirstSearch( $identifier, array $normalized_entity) {
    foreach ($identifiers as $identifier) {
      $ids = explode('.', $identifier);
      list($entity_type_id, $bundle, $uuid) = $ids;
      // Check if entity is included in the queue.
      if (!empty($normalized_entities[$identifier])) {
        $entity = $normalized_entities[$identifier];
        // Check dependencies
        if (!empty($entity['_content_sync']['entity_dependencies'])) {
          foreach ($entity['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
            if(!is_null($references)){
              $dependency = $this->depthFirstSearch($visited, $references, $normalized_entities);
            
              //if ($dependency !== TRUE && !is_null($dependency)){
              //   $validate_dependecies = FALSE;
              //   $visited['Missing'][$identifier][] = $dependency;
              //}
            }
          }
        }
        if (!isset($visited[$identifier]) && $validate_dependecies) {
          //$visited[$identifier]['entity_type'] = $entity_type_id;
          //$visited[$identifier]['entity_uuid'] = $uuid;
          //return TRUE;



        }
      }else{
        //Verify if dependency exist in the site and include it.
        $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
        $entity = $activeStorage->cs_read($identifier);
        if($entity){
          $normalized_entities[$identifier] = $entity;
          $this->depthFirstSearch($visited,[$identifier], $normalized_entities);
        }else{
          $return = $identifier;
        }
      }
    }
  }

  /**
   * Add dependencies of the entity to export to the queue.
   *
   * @param array $normalized_entity
   *   Parsed entity to export.
   *
   */
  public function resolve(array $normalized_entity) {
    $identifier = key($normalized_entity);
    //$this->depthFirstSearch($identifier, $normalized_entity);






    foreach ($identifiers as $identifier) {
      $ids = explode('.', $identifier);
      list($entity_type_id, $bundle, $uuid) = $ids;
      // Check if entity is included in the queue.
      if (!empty($normalized_entities[$identifier])) {
        $entity = $normalized_entities[$identifier];
        // Check dependencies
        if (!empty($entity['_content_sync']['entity_dependencies'])) {
          foreach ($entity['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
            if(!is_null($references)){
              $dependency = $this->depthFirstSearch($visited, $references, $normalized_entities);
            
              //if ($dependency !== TRUE && !is_null($dependency)){
              //   $validate_dependecies = FALSE;
              //   $visited['Missing'][$identifier][] = $dependency;
              //}
            }
          }
        }
        if (!isset($visited[$identifier]) && $validate_dependecies) {
          //$visited[$identifier]['entity_type'] = $entity_type_id;
          //$visited[$identifier]['entity_uuid'] = $uuid;
          //return TRUE;



        }
      }else{
        //Verify if dependency exist in the site and include it.
        $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
        $entity = $activeStorage->cs_read($identifier);
        if($entity){
          $normalized_entities[$identifier] = $entity;
          $this->depthFirstSearch($visited,[$identifier], $normalized_entities);
        }else{
          $return = $identifier;
        }
      }
    }







  }
}
