<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\content_sync\Plugin\SyncNormalizerDecoratorManager;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Component\Render\FormattableMarkup;

/**
 * Adds the file URI to embedded file entities.
 */
class FileEntityNormalizer extends ContentEntityNormalizer {

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = 'Drupal\file\FileInterface';

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * FileEntityNormalizer constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeRepositoryInterface $entity_type_repository
   *   The entity type repository.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   *
   * @param \Drupal\content_sync\Plugin\SyncNormalizerDecoratorManager $decorator_manager
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityTypeRepositoryInterface $entity_type_repository, EntityFieldManagerInterface $entity_field_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityRepositoryInterface $entity_repository, SyncNormalizerDecoratorManager $decorator_manager, FileSystemInterface $file_system) {
    parent::__construct($entity_type_manager, $entity_type_repository, $entity_field_manager, $entity_type_bundle_info, $entity_repository, $decorator_manager);
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $serializer_context = array()) {

    $file_data = '';

    // Check if the image is available as base64-encoded image.
    if (!empty($data['data'][0]['value'])) {
      $file_data = $data['data'][0]['value'];
      // Avoid 'data' being treated as a field.
      unset($data['data']);
    }

    // If a directory is set, we must to copy the file to the file system.
    if (!empty($serializer_context['content_sync_directory_files'])) {
      $scheme = \Drupal::service('stream_wrapper_manager')->getScheme($data['uri'][0]['value']);
      if (!empty($scheme)) {
        $source_path = realpath($serializer_context['content_sync_directory_files']) . '/' .$scheme . '/';
        $source      = str_replace($scheme . '://', $source_path, $data['uri'][0]['value']);
        if (file_exists($source)) {
          $file = $this->fileSystem->realpath($data['uri'][0]['value']);
          if (!file_exists($file) || (md5_file($file) !== md5_file($source))) {
            $dir = $this->fileSystem->dirname($data['uri'][0]['value']);
            $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
            $uri = $this->fileSystem->copy($source, $data['uri'][0]['value']);
            $data['uri'] = [
              [
                'value' => $uri,
                'url' => str_replace($GLOBALS['base_url'], '', file_create_url($uri))
              ]
            ];

            // We just need one method to create the image.
            $file_data = '';
          }
        }
      }
    }

    $entity = parent::denormalize($data, $class, $format, $serializer_context);

    // If the image was sent as base64 we must to create the physical file.
    if ($file_data) {
      // Decode and save to file.
      $file_contents = base64_decode($file_data);
      $dirname = $this->fileSystem->dirname($entity->getFileUri());
      $this->fileSystem->prepareDirectory($dirname, FileSystemInterface::CREATE_DIRECTORY);
      if ($uri = $this->fileSystem->saveData($file_contents, $entity->getFileUri())) {
        $entity->setFileUri($uri);
      }
      else {
        throw new \RuntimeException(new FormattableMarkup('Failed to write @filename.', ['@filename' => $entity->getFilename()]));
      }
    }

    // If the image was sent as URL we must to create the physical file.
    /*if ($file_data) {
      // Decode and save to file.
      $file_contents = base64_decode($file_data);
      $dirname = $this->fileSystem->dirname($entity->getFileUri());
      file_prepare_directory($dirname, FILE_CREATE_DIRECTORY);
      if ($uri = file_unmanaged_save_data($file_contents, $entity->getFileUri())) {
        $entity->setFileUri($uri);
      }
      else {
        throw new \RuntimeException(SafeMarkup::format('Failed to write @filename.', array('@filename' => $entity->getFilename())));
      }
    }*/

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $serializer_context = array()) {
    $data = parent::normalize($object, $format, $serializer_context);

    // The image will be saved in the export directory.
    if (!empty($serializer_context['content_sync_directory_files'])) {
      $uri = $object->getFileUri();
      $scheme = \Drupal::service('stream_wrapper_manager')->getScheme($uri);
      $destination = "{$serializer_context['content_sync_directory_files']}/{$scheme}/";
      $destination = str_replace($scheme . '://', $destination, $uri);
      $this->fileSystem->prepareDirectory($this->fileSystem->dirname($destination), FileSystemInterface::CREATE_DIRECTORY);
      $this->fileSystem->copy($uri, $destination, FileSystemInterface::EXISTS_REPLACE);
    }

    // Set base64-encoded file contents to the "data" property.
    if (!empty($serializer_context['content_sync_file_base_64'])) {
      $file_data = base64_encode(file_get_contents($object->getFileUri()));
      $data['data'] = [['value' => $file_data]];
    }

    return $data;
  }

}
