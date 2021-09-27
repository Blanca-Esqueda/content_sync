<?php

namespace Drupal\content_sync\Plugin\SyncNormalizerDecorator;

use Drupal\content_sync\Plugin\SyncNormalizerDecoratorBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a decorator for managing entities ids/target ids.
 *
 * @SyncNormalizerDecorator(
 *   id = "id_cleaner",
 *   name = @Translation("IDs Cleaner"),
 * )
 *
 * https://chromatichq.com/insights/dependency-injection-drupal-8-plugins
 *
 */
class IdsCleaner extends SyncNormalizerDecoratorBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *
   */
  public function __construct(array $configuration,
            $plugin_id,
            $plugin_definition,
            EntityFieldManagerInterface $entityFieldManager,
            EntityTypeBundleInfoInterface $entityTypeBundleInfo
          ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityFieldManager = $entityFieldManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
  }

  /**
   * {@inheritdoc}
   */
  public function decorateDenormalization(array &$normalized_entity, $type, $format, array $context = []) {
    $this->fixReferences($normalized_entity, $type, $context['bundle']);
  }

  /**
   * @param array $normalized_entity
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param $format
   * @param array $context
   */
  public function decorateNormalization(array &$normalized_entity, ContentEntityInterface $entity, $format, array $context = []) {
    $this->cleanIds($normalized_entity, $entity);
  }

  /**
   * @param $normalized_entity
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return mixed
   */
  protected function cleanIds(&$normalized_entity, ContentEntityInterface $entity) {
    $keys = $entity->getEntityType()->getKeys();
    if (isset($normalized_entity[$keys['id']])) {
      unset($normalized_entity[$keys['id']]);
    }
    if (isset($keys['revision']) && isset($normalized_entity[$keys['revision']])) {
      unset($normalized_entity[$keys['revision']]);
    }
    // Path Alias are now entities and they have their own yml.
    // So,remove the path from the entity to avoid duplicated alias.
    if ($entity->hasLinkTemplate('canonical')) {
      if(isset($normalized_entity['path'])){
        unset($normalized_entity['path']);
      }
    }
    // Move dependencies together.
    $dependencies = [];
    foreach ($normalized_entity as $field_name => $field_items) {
      foreach ($field_items as $key => $item) {
        if (!empty($item['dependencies'])){
          $dependencies = array_merge_recursive($dependencies, $item['dependencies']);
          unset($normalized_entity[$field_name][$key]['dependencies']);
        }
      }
    }
    if (!empty($dependencies)){
      array_walk($dependencies, function(&$v) {
        $v = array_unique($v);
      });
      $normalized_entity['_content_sync']['entity_dependencies'] = $dependencies;
    }
    return $normalized_entity;
  }

  /**
   * @param array $data
   * @param $entity_type_id
   *
   * @return array
   */
  protected function fixReferences(&$data, $entity_type_id, $bundle = FALSE) {
    if ($bundle) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    }
    else {
      $bundles = array_keys($this->entityTypeBundleInfo->getBundleInfo($entity_type_id));
      $field_definitions = [];
      foreach ($bundles as $bundle) {
        $field_definitions_bundle = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
        if (is_array($field_definitions_bundle)) {
          $field_definitions += $field_definitions_bundle;
        }
      }
    }
    // Clean up data, remove invalid fields.
    $field_names = array_keys($field_definitions);
    foreach ($data as $field_name => $field_data) {
      if (!in_array($field_name, $field_names)) {
        unset($data[$field_name]);
      }
    }
    return $data;
  }

}
