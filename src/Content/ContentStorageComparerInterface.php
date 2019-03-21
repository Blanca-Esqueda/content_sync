<?php

namespace Drupal\content_sync\Content;

/**
 * Defines an interface for comparison of content storage objects.
 */
interface ContentStorageComparerInterface {

  /**
   * Gets the content source storage.
   *
   * @param string $collection
   *   (optional) The content collection to use. Defaults to the
   *   default collection.
   *
   * @return Drupal\content_sync\Content\ContentStorageInterface
   *   Storage object used to read content.
   */
  public function getSourceStorage($collection = ContentStorageInterface::DEFAULT_COLLECTION);

  /**
   * Gets the content target storage.
   *
   * @param string $collection
   *   (optional) The storage collection to use. Defaults to the
   *   default collection.
   *
   * @return Drupal\content_sync\Content\ContentStorageInterface
   *   Storage object used to write content.
   */
  public function getTargetStorage($collection = ContentStorageInterface::DEFAULT_COLLECTION);

  /**
   * Gets an empty changelist.
   *
   * @return array
   *   An empty changelist array.
   */
  public function getEmptyChangelist();

  /**
   * Gets the list of differences.
   *
   * @param string $op
   *   (optional) A change operation. Either delete, create or update. If
   *   supplied the returned list will be limited to this operation.
   * @param string $collection
   *   (optional) The collection to get the changelist for. Defaults to the
   *   default collection.
   *
   * @return array
   *   An array of content changes.
   */
  public function getChangelist($op = NULL, $collection = ContentStorageInterface::DEFAULT_COLLECTION);

  /**
   * Recalculates the differences.
   *
   * @return Drupal\content_sync\Content\ContentStorageComparerInterface
   *   An object which implements the ContentStorageComparerInterface.
   */
  public function reset();

  /**
   * Checks if there are any operations with changes to process.
   * Until the changelist has been calculated this will always be FALSE.
   *
   * @return bool
   *   TRUE if there are changes to process and FALSE if not.
   *
   * @see Drupal\content_sync\Content\ContentStorageComparerInterface::createChangelist()
   */
  public function hasChanges();

  /**
   * Validates that the system.site::uuid in the source and target match.
   *
   * @return bool
   *   TRUE if identical, FALSE if not.
   */
  public function validateSiteUuid();

  /**
   * Gets the existing collections from both the target and source storage.
   *
   * @param bool $include_default
   *   (optional) Include the default collection. Defaults to TRUE.
   *
   * @return array
   *   An array of existing collection names.
   */
  public function getAllCollectionNames($include_default = TRUE);

}
