<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\content_sync\ContentSyncManager;
use Drupal\content_sync\Plugin\SyncNormalizerDecoratorManager;
use Drupal\content_sync\Plugin\SyncNormalizerDecoratorTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\serialization\Normalizer\ContentEntityNormalizer as BaseContentEntityNormalizer;

/**
 * Adds the file URI to embedded file entities.
 */
class ContentEntityNormalizer extends BaseContentEntityNormalizer {

  use SyncNormalizerDecoratorTrait;

  /**
   * @var SyncNormalizerDecoratorManager
   */
  protected $decoratorManager;

  /**
   * Constructs an EntityNormalizer object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeRepositoryInterface $entity_type_repository
   *   The entity type repository.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param SyncNormalizerDecoratorManager $decorator_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityTypeRepositoryInterface $entity_type_repository, EntityFieldManagerInterface $entity_field_manager, SyncNormalizerDecoratorManager $decorator_manager) {
    parent::__construct($entity_type_manager, $entity_type_repository, $entity_field_manager);
    $this->decoratorManager = $decorator_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    if (is_null($data)) {
      return NULL;
    }
    $original_data = $data;

    // Get the entity type ID while letting context override the $class param.
    $entity_type_id = !empty($context['entity_type']) ? $context['entity_type'] : $this->entityTypeRepository->getEntityTypeFromClass($class);

    $bundle = FALSE;
    // Get the entity type definition.
    $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($entity_type_definition->hasKey('bundle')) {
      $bundle_key = $entity_type_definition->getKey('bundle');
      // Get the base field definitions for this entity type.
      $base_field_definitions = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);

      // Get the ID key from the base field definition for the bundle key or
      // default to 'value'.
      $key_id = isset($base_field_definitions[$bundle_key]) ? $base_field_definitions[$bundle_key]->getFieldStorageDefinition()
        ->getMainPropertyName() : 'value';

      // Normalize the bundle if it is not explicitly set.
      $bundle = isset($data[$bundle_key][0][$key_id]) ? $data[$bundle_key][0][$key_id] : (isset($data[$bundle_key]) ? $data[$bundle_key] : NULL);
    }

    $context['bundle'] = $bundle;
    // Decorate data before denormalizing it.
    $this->decorateDenormalization($data, $entity_type_id, $format, $context);

    // Data to Entity
    $entity = parent::denormalize($data, $class, $format, $context);

    // Decorate denormalized entity before retuning it.
    $this->decorateDenormalizedEntity($entity, $original_data, $format, $context);

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []) {
    /* @var ContentEntityInterface $object */
    $normalized_data = parent::normalize($object, $format, $context);
    $normalized_data['_content_sync'] = $this->getContentSyncMetadata($object, $context);

    // Decorate normalized entity before retuning it.
    if (is_a($object, ContentEntityInterface::class, TRUE)) {
      $this->decorateNormalization($normalized_data, $object, $format, $context);
    }
    return $normalized_data;
  }

  /**
   * @inheritdoc
   */
  public function supportsNormalization($data, $format = NULL) {
    return parent::supportsNormalization($data, $format) && !empty($data->is_content_sync);
  }

  /**
   * @inheritdoc
   */
  public function supportsDenormalization($data, $type, $format = NULL) {
    return parent::supportsDenormalization($data, $type, $format);
  }

  /**
   * @param $object
   * @param array $context
   *
   * @return array
   */
  protected function getContentSyncMetadata($object, $context = []) {
    $metadata = [
      'entity_type' => $object->getEntityTypeId(),
    ];
    return $metadata;
  }

  /**
   * @inheritdoc
   */
  protected function getDecoratorManager() {
    return $this->decoratorManager;
  }
}
