<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\ContentEntityType;

/**
 * Defines the content import form.
 */
trait ContentImportTrait {

  /**
   * @param $content_to_sync
   *
   * @param $content_to_delete
   *
   * @param $serializer_context
   *
   * content_sync_directory:
   * path for the content sync directory.
   *
   * @return array
   */
  public function generateImportBatch($content_to_sync, $content_to_delete, $serializer_context = []) {
    $serializer_context['content_sync_directory_entities'] = content_sync_get_content_directory('sync') . "/entities";
    $serializer_context['content_sync_directory_files'] = content_sync_get_content_directory('sync') . "/files";
    $operations[] = [[$this, 'deleteContent'], [$content_to_delete, $serializer_context]];
    $operations[] = [[$this, 'syncContent'], [$content_to_sync, $serializer_context]];

    $batch = [
      'title' => $this->t('Synchronizing Content...'),
      'message' => $this->t('Synchronizing Content...'),
      'operations' => $operations,
      //'finished' => [$this, 'finishImportBatch'],
    ];
    return $batch;
  }

  /**
   * Processes the content import to be updated or created batch and persists the importer.
   *
   * @param $content_to_sync
   * @param string $serializer_context
   * @param array $context
   *   The batch context.
   */
  public function syncContent(array $content_to_sync, $serializer_context = [], &$context) {
    if (empty($context['sandbox'])) {
      $directory = $serializer_context['content_sync_directory_entities'];
      $queue = $this->contentSyncManager->generateImportQueue($content_to_sync, $directory);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['queue'] = $queue;
      $context['sandbox']['directory'] = $directory;
      $context['sandbox']['max'] = count($queue);
    }
    if (!empty($context['sandbox']['queue'])) {
      $error = FALSE;
      $item = array_pop($context['sandbox']['queue']);
      $decoded_entity = $item['decoded_entity'];
      $entity_type_id = $item['entity_type_id'];
      $entity = $this->contentSyncManager->getContentImporter()
                                         ->importEntity($decoded_entity, $serializer_context);
      if($entity) {
        $context['results'][] = TRUE;
        $context['message'] = $this->t('Imported content @label (@entity_type: @id).', [
          '@label' => $entity->label(),
          '@id' => $entity->id(),
          '@entity_type' => $entity->getEntityTypeId(),
        ]);
        // Invalidate the CS Cache of the entity.
        $bundle = $entity->bundle();
        $entity_id = $entity->getEntityTypeId();
        $name = $entity_id . "." .  $bundle . "." . $entity->uuid();
        $cache = \Drupal::cache('content')->invalidate($entity_id.".".$bundle.":".$name);
        unset($entity);
      }
      else {
        $error = TRUE;
      }
      if ($error) {
        $context['message'] = $this->t('Error importing content of type @entity_type.', [
          '@entity_type' => $entity_type_id,
        ]);
        if (!isset($context['results']['errors'])) {
          $context['results']['errors'] = [];
        }
        $context['results']['errors'][] = $context['message'];
      }
      if ($error) {
        \Drupal::messenger()->addError($context['message']);
      }
      // We need to count the progress anyway even if an error has occured.
      $context['sandbox']['progress']++;
    }
    $context['finished'] = $context['sandbox']['max'] > 0
                        && $context['sandbox']['progress'] < $context['sandbox']['max'] ?
                           $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
  }

  /**
   * Processes the content import to be deleted or created batch and persists the importer.
   *
   * @param $content_to_sync
   * @param string $serializer_context
   * @param array $context
   *   The batch context.
   */
  public function deleteContent(array $content_to_delete, $serializer_context = [], &$context) {
    if (empty($context['sandbox'])) {
      $directory = $serializer_context['content_sync_directory_entities'];
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['queue'] = $content_to_delete;
      $context['sandbox']['directory'] = $directory;
      $context['sandbox']['max'] = count($content_to_delete);
    }
    if (!empty($context['sandbox']['queue'])) {
      $error = TRUE;
      $item = array_pop($context['sandbox']['queue']);
      $ids = explode('.', $item);
      list($entity_type_id, $bundle, $uuid) = $ids;

      $entity = $this->contentSyncManager->getEntityTypeManager()->getStorage($entity_type_id)
                                         ->loadByProperties(['uuid' => $uuid]);
      $entity = array_shift($entity);
      if (!empty($entity)) {

        // Prevent Anonymous User and Super Admin from being deleted.
        if ($entity_type_id == 'user' && (
           (int) $entity->id() === 0 ||
           (int) $entity->id() === 1)) {

          $message = $this->t('@uuid - Anonymous user or super admin can not be removed.', [
          '@entity_type' => $entity_type_id,
          '@uuid' => $uuid,
          ]);

        }else{

          try {
            $message = $this->t('Deleted content @label (@entity_type: @id).', [
              '@label' => $entity->label(),
              '@id' => $entity->id(),
              '@entity_type' => $entity->getEntityTypeId(),
            ]);
            $entity->delete();
            $error = FALSE;
            // Invalidate the CS Cache of the entity.
            $bundle = $entity->bundle();
            $name = $entity_type_id . "." .  $bundle . "." . $entity->uuid();
            $cache = \Drupal::cache('content')->invalidate($entity_type_id.".".$bundle.":".$name);
          } catch (EntityStorageException $e) {
            $message = $e->getMessage();
            \Drupal::messenger()->addError($message);
          }
        }
      }
      else {
        $message = $this->t('@uuid of type @entity_type was not found.', [
          '@entity_type' => $entity_type_id,
          '@uuid' => $uuid,
        ]);
      }
    }
    $context['results'][] = TRUE;
    $context['sandbox']['progress']++;
    $context['message'] = $message;

    if ($error) {
      if (!isset($context['results']['errors'])) {
        $context['results']['errors'] = [];
      }
      $context['results']['errors'][] = $context['message'];
    }

    $context['finished'] = $context['sandbox']['max'] > 0
                        && $context['sandbox']['progress'] < $context['sandbox']['max'] ?
                           $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
  }

  /**
   * Finish batch.
   *
   * This function is a static function to avoid serializing the ContentSync
   * object unnecessarily.
   */
  public static function finishImportBatch($success, $results, $operations) {
    if ($success) {
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
          \Drupal::messenger()->addError($error);
          \Drupal::logger('config_sync')->error($error);
        }
        \Drupal::messenger()->addWarning(\Drupal::translation()
          ->translate('The content was imported with errors.'));
      }
      else {
        \Drupal::messenger()->addStatus(\Drupal::translation()
          ->translate('The content was imported successfully.'));
      }
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      $message = \Drupal::translation()
                        ->translate('An error occurred while processing %error_operation with arguments: @arguments', [
                          '%error_operation' => $error_operation[0],
                          '@arguments' => print_r($error_operation[1], TRUE),
                        ]);
      \Drupal::messenger()->addError($message, 'error');
    }
  }

}
