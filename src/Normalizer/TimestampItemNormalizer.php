<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\Core\Field\Plugin\Field\FieldType\TimestampItem;
use Drupal\Core\TypedData\Plugin\DataType\Timestamp;

use Drupal\serialization\Normalizer\TimestampItemNormalizer as BaseTimestampItemNormalizer;

/**
 * Converts values for TimestampItem to and from common formats.
 *
 * Overrides FieldItemNormalizer and
 * \Drupal\serialization\Normalizer\TimestampNormalizer
 * to use
 * \Drupal\content_sync\Normalizer\TimestampNormalizer
 *
 * Overrides FieldItemNormalizer to
 * - during denormalization consider more than one value
 * ie. for custom modules as smart_date
 */
class TimestampItemNormalizer extends BaseTimestampItemNormalizer {

  /**
   * {@inheritdoc}
   */
  protected function constructValue($data, $context) {
    if (!empty($data['format'])) {
      $context['datetime_allowed_formats'] = [$data['format']];
    }
    $field_item = $context['target_instance'];
    $denormalized_data = [];
    foreach( $field_item->getProperties() as $item_key => $item){
      if(isset($data[$item_key])){
        $item_class = $item->getDataDefinition()->getClass();
        if ($this->serializer->supportsDenormalization($data[$item_key], $item_class, NULL, $context)) {
          $denormalized_data[$item_key] = $this->serializer->denormalize($data[$item_key],$item_class, NULL, $context );
        }else{
          $denormalized_data[$item_key] = $data[$item_key];
        }
      }
    }
    return $denormalized_data;
  }
}