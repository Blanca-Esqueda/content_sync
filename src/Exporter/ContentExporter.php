<?php

namespace Drupal\content_sync\Exporter;

use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\Serializer\Serializer;

class ContentExporter implements ContentExporterInterface {

  protected $format = 'yaml';

  protected $serializer;

  protected $context = [];

  /**
   * ContentExporter constructor.
   */
  public function __construct(Serializer $serializer) {
    $this->serializer = $serializer;
  }


  /**
   * @inheritdoc
   */
  public function exportEntity(ContentEntityInterface $entity, array $context = []) {
    $context = $this->context + $context;
    $context += [
      'content_sync' => TRUE,
    ];
    // Allows to know to normalizers that this is a content sync generated entity.
    $entity->is_content_sync = TRUE;
    $normalized_entity = $this->serializer->serialize($entity, $this->format, $context);
//    $return = [
//      'entity_type_id' => $entity->getEntityTypeId(),
//      'entity' => $this->serializer->encode($normalized_entity, $this->format, $context),
//      'original_entity' => $entity,
//    ];

    return $normalized_entity;
  }

  /**
   * @return string
   */
  public function getFormat() {
    return $this->format;
  }

  /**
   * @return \Symfony\Component\Serializer\Serializer
   */
  public function getSerializer() {
    return $this->serializer;
  }

}