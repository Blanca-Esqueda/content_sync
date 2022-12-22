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
   * The normalized data.
   * @var array
   */
  protected $normalizedEntities;

  /**
   * Entities with references to ancestors.
   * @var array
   */
  protected $rebuild;

  /**
   * Queue variable.
   * @var array
   */
  protected $q;

  /**
   * Constructs an ImportQueueResolver object.
   *
   * @param array $normalized_entities
   *   The normalized data.
   */
  public function __construct(array $normalized_entities) {
    $this->normalizedEntities = $normalized_entities;
    $this->rebuild = [];
    $this->q = [];
  }

  /**
   * Creates a queue.
   *
   * @return array
   *   Queue to be processed within a batch process.
   */
  public function resolve() {
    $ancestors = [];
    foreach ($this->normalizedEntities as $identifier => $entity) {
      $this->processEntity($identifier, $entity, $ancestors);
    }
    return array_reverse(array_merge($this->q, $this->rebuild));
  }

  /**
   * Get entities dependencies - it considers redundancies.
   */
  public function processEntity(string $identifier, array $entity, array $ancestors) {
    $q = array_column($this->q, 'identifier');
    if (in_array($identifier, $q)) {
      return;
    }
    $ancestors[] = $identifier;
    $dependencies = $this->getDependencies($entity);
    foreach ($dependencies as $dependency) {
      $dependency_entity = $this->fetchEntity($dependency);
      if (in_array($dependency, $ancestors)) {
        $this->rebuild[] = [
          'identifier' => $identifier,
          'entity_type_id' => $entity['_content_sync']['entity_type'],
          'decoded_entity' => $entity,
        ];
      }
      else {
        $this->processEntity($dependency, $dependency_entity, $ancestors);
      }
    }
    $this->q[] = [
      'identifier' => $identifier,
      'entity_type_id' => $entity['_content_sync']['entity_type'],
      'decoded_entity' => $entity,
    ];
  }

  /**
   * Get dependencies of a given entity.
   *
   * @return array
   *   Dependencies of an entity.
   */
  public function getDependencies($entity) {
    $dependencies = [];
    if (!empty($entity['_content_sync']['entity_dependencies'])) {
      foreach ($entity['_content_sync']['entity_dependencies'] as $ref_entity_type_id => $references) {
        $dependencies = array_merge($dependencies, $references);
      }
    }
    return $dependencies;
  }

  /**
   * Fetch an entity - normalized data.
   *
   * @return array
   *   Entity normalized data.
   */
  public function fetchEntity($identifier) {
    try {
      $entity = $this->getEntity($identifier, $this->normalizedEntities);
    }
    catch (\Exception $e) {
      $entity = [];
      // TODO: notice/log of what entity is missing.
      // TODO: should the import of the parent entity abort?
    }
    return $entity;
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
      return $entity;
    }
    else {
      // Check the entity in the content directory.
      [$entity_type_id, $bundle, $uuid] = explode('.', $identifier);
      $file_path = content_sync_get_content_directory('sync') . "/entities/" . $entity_type_id . "/" . $bundle . "/" . $identifier . ".yml";
      $raw_entity = file_get_contents($file_path);

      // Problems to open the .yml file.
      if (!$raw_entity) {
        throw new \Exception("Dependency {$identifier} is missing.");
      }

      $entity = Yaml::decode($raw_entity);
    }

    // TODO: else if Check the entity exists in the snapshot

    // TODO: else if Check if the entity exist in the site.

    // TODO: better notice about missing dependency
    //       - should the parent import be aborted or not?

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
    return (bool) \Drupal::database()
      ->queryRange('SELECT 1 FROM {cs_db_snapshot} WHERE name = :name', 0, 1, [
        ':name' => $identifier])
      ->fetchField();
  }

}
