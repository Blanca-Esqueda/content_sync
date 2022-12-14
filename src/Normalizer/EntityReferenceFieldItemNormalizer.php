<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\content_sync\ContentSyncManager;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\serialization\Normalizer\FieldItemNormalizer;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

/**
 * Adds the file URI to embedded file entities.
 */
class EntityReferenceFieldItemNormalizer extends FieldItemNormalizer {

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = EntityReferenceItem::class;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Constructs a EntityReferenceFieldItemNormalizer object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityRepositoryInterface $entity_repository) {
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($field_item, $format = NULL, array $context = []) {
    $values = parent::normalize($field_item, $format, $context);
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    if ($entity = $field_item->get('entity')->getValue()) {
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

        // TODO: Verify if the canonical url is necessary.
        //       Because anyway the url is deleted.
        /*// Add a 'url' value if there is a reference and a canonical URL. Hard
        // code 'canonical' here as config entities override the default $rel
        // parameter value to 'edit-form.
        if ($entity->hasLinkTemplate('canonical')) {
          $url = $entity->toUrl('canonical')->toString();
          $values['url'] = $url;
        }*/

        $key = $field_item->mainPropertyName();
        if (!empty($values[$key])) {
            unset($values[$key]);
        }
        if (!empty($values['url'])) {
          unset($values['url']);
        }
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function constructValue($data, $context) {
    if (isset($data['target_uuid'])) {
      /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
      $field_item = $context['target_instance'];
      if (empty($data['target_uuid'])) {
        throw new InvalidArgumentException(sprintf('If provided "target_uuid" cannot be empty for field "%s".', $field_item->getName()));
      }
      $target_type = $field_item->getFieldDefinition()->getSetting('target_type');
      if (!empty($data['target_type']) && $target_type !== $data['target_type']) {
        throw new UnexpectedValueException(sprintf('The field "%s" property "target_type" must be set to "%s" or omitted.', $field_item->getFieldDefinition()->getName(), $target_type));
      }
      if ($entity = $this->entityRepository->loadEntityByUuid($target_type, $data['target_uuid'])) {
        $key = $field_item->mainPropertyName();
        if (is_a($entity, RevisionableInterface::class, TRUE)) {
          return [$key => $entity->id(),
                  'target_revision_id' => $entity->getRevisionId()];
        }
        return [$key => $entity->id()];
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
