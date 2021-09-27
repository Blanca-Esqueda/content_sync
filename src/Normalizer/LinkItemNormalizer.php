<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\content_sync\ContentSyncManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\link\Plugin\Field\FieldType\LinkItem;
use Drupal\serialization\Normalizer\FieldItemNormalizer;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

/**
 * Converts Link fields to an array.
 */
class LinkItemNormalizer extends FieldItemNormalizer {

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = LinkItem::class;
  //protected $supportedInterfaceOrClass = 'Drupal\link\Plugin\Field\FieldType\LinkItem';

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a EntityReferenceFieldItemNormalizer object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeManagerInterface $entity_type_manager) {
    $this->entityRepository = $entity_repository;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($field_item, $format = NULL, array $context = []) {
    $values = parent::normalize($field_item, $format, $context);
    try {
      $url = $field_item->getUrl();
      $route_parameters = $url->getRouteParameters();
      if (count($route_parameters) == 1) {
        $entity_id = reset($route_parameters);
        $entity_type = key($route_parameters);
        $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
        // We are only interested in content entities.
        $reflection = new \ReflectionClass($entity);
        if ($reflection->implementsInterface(ContentEntityInterface::class)) {
          $target_type = $entity->getEntityTypeId();
          $target_uuid = $entity->uuid();
          $ids = [
            $target_type,
            $entity->bundle(),
            $target_uuid,
          ];
          $dependency = implode(ContentSyncManager::DELIMITER, $ids);
          // Add the target entity UUID and type to the normalized output values.
          $values['target_type'] = $target_type;
          $values['target_uuid'] = $target_uuid;
          // Include a dependency
          $values['dependencies'][$target_type][] = $dependency;
          // Remove target revision id as we are not syncing revisions.
          if (isset($values['target_revision_id'])){
            unset($values['target_revision_id']);
          }
          // Remove main property - we set target_uuid
          $key = $field_item->mainPropertyName();
          if (!empty($values[$key])) {
            unset($values[$key]);
          }
        }
      }
      return $values;
    }
    catch (\Exception $e) {
      // If link is linked to a non-content entity - just do nothing.
      // Note: External URLs do not have internal route parameters.
      return $values;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function constructValue($data, $context) {
    if (isset($data['target_uuid'])) {
      /** @var \Drupal\link\Plugin\Field\FieldType\LinkItem $field_item */
      $field_item = $context['target_instance'];
      if (empty($data['target_uuid'])) {
        throw new InvalidArgumentException(sprintf('If provided "target_uuid" cannot be empty for field "%s".', $field_item->getName()));
      }
      if (empty($data['target_type'])) {
        throw new UnexpectedValueException(sprintf('If provided "target_type" cannot be empty for field "%s".', $data['target_type'], $data['target_uuid'], $field_item->getName()));
      }
      if ($entity = $this->entityRepository->loadEntityByUuid($data['target_type'], $data['target_uuid'])) {
        unset($data['target_type']);
        unset($data['target_uuid']);
        $url = $entity->toUrl();
        // Convert entity URIs to the entity scheme, if the path matches a route
        // of the form "entity.$entity_type_id.canonical".
        // @see \Drupal\Core\Url::fromEntityUri()
        if ($url->isRouted()) {
          $route_name = $url->getRouteName();
          foreach (array_keys($this->entityTypeManager->getDefinitions()) as $entity_type_id) {
            if ($route_name == "entity.{$entity_type_id}.canonical"
              && isset($url->getRouteParameters()[$entity_type_id])) {
              $uri = "entity:{$entity_type_id}/" . $url->getRouteParameters()[$entity_type_id];
            }
          }
        }else{
          $uri = $url->getUri();
        }
        $key = $field_item->mainPropertyName();
        $data[$key] = $uri;
        if (is_a($entity, RevisionableInterface::class, TRUE)) {
          $data['target_revision_id'] = $entity->getRevisionId();
        }
        return $data;
      }
      else {
        // Unable to load entity by uuid.
        // TODO: change Error to Log/Warning - to avoid stoping the import of the rest of the entities.   ---> Same for throws above.
        //throw new InvalidArgumentException(sprintf('No "%s" entity found with UUID "%s" for field "%s".', $data['target_type'], $data['target_uuid'], $field_item->getName()));
        return[];
      }
    }
    return parent::constructValue($data, $context);
  }
}
