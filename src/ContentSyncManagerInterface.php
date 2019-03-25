<?php

namespace Drupal\content_sync;


/**
 * Interface ContentSyncManagerInterface.
 *
 * @package Drupal\content_sync
 */
interface ContentSyncManagerInterface {

  /**
   * @return \Drupal\content_sync\Importer\ContentImporterInterface
   */
  public function getContentImporter();

  /**
   * @return \Drupal\content_sync\Exporter\ContentExporterInterface
   */
  public function getContentExporter();

  /**
   * @return \Symfony\Component\Serializer\Serializer
   */
  public function getSerializer();

  /**
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public function getEntityTypeManager();


  /**
   * Creates a Diff object using the content data from the two storages.
   *
   * @param \Drupal\content_sync\Content\ContentStorageInterface $source_storage
   *   The storage to diff content from.
   * @param \Drupal\content_sync\Content\ContentStorageInterface $target_storage
   *   The storage to diff content to.
   * @param string $source_name
   *   The name of the content object in the source storage to diff.
   * @param string $target_name
   *   (optional) The name of the content object in the target storage.
   *   If omitted, the source name is used.
   * @param string $collection
   *   (optional) The ccontent collection name. Defaults to the default
   *   collection.
   *
   * @return \Drupal\Component\Diff\Diff
   *   A Diff object using the config data from the two storages.
   *
   * @todo Make renderer injectable
   *
   * @see \Drupal\Core\Diff\DiffFormatter
   */
  public function diff(ContentStorageInterface $source_storage, ContentStorageInterface $target_storage, $source_name, $target_name = NULL, $collection = ContentStorageInterface::DEFAULT_COLLECTION);

}
