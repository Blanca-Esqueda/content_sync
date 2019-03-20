<?php

namespace Drupal\content_sync\Content;

/**
 * Defines an interface for content storage.
 *
 * Classes implementing this interface allow reading and writing content
 * data from and to the storage.
 */
interface ContentStorageInterface {

  /**
   * The default collection name.
   */
  const DEFAULT_COLLECTION = '';

  /**
   * Returns whether a content object exists.
   *
   * @param string $name
   *   The name of a content object to test.
   *
   * @return bool
   *   TRUE if the content object exists, FALSE otherwise.
   */
  public function exists($name);

  /**
   * Reads content data from the storage.
   *
   * @param string $name
   *   The name of a content object to load.
   *
   * @return array|bool
   *   The content data stored for the content object name. If no
   *   content data exists for the given name, FALSE is returned.
   */
  public function read($name);

  /**
   * Reads content data from the storage.
   *
   * @param array $names
   *   List of names of the content objects to load.
   *
   * @return array
   *   A list of the content data stored for the content object name
   *   that could be loaded for the passed list of names.
   */
  public function readMultiple(array $names);

  /**
   * Writes content data to the storage.
   *
   * @param string $name
   *   The name of a content object to save.
   * @param array $data
   *   The content data to write.
   *
   * @return bool
   *   TRUE on success, FALSE in case of an error.
   *
   * @throws \Drupal\Core\Config\StorageException   //TODO
   *   If the back-end storage does not exist and cannot be created.
   */
  public function write($name, array $data);

  /**
   * Deletes a content object from the storage.
   *
   * @param string $name
   *   The name of a content object to delete.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   */
  public function delete($name);

  /**
   * Renames a content object in the storage.
   *
   * @param string $name
   *   The name of a content object to rename.
   * @param string $new_name
   *   The new name of a content object.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   */
  public function rename($name, $new_name);

  /**
   * Encodes content data into the storage-specific format.
   *
   * This is a publicly accessible static method to allow for alternative
   * usages in data conversion scripts and also tests.
   *
   * @param array $data
   *   The content data to encode.
   *
   * @return string
   *   The encoded content data.
   */
  public function encode($data);

  /**
   * Decodes content data from the storage-specific format.
   *
   * This is a publicly accessible static method to allow for alternative
   * usages in data conversion scripts and also tests.
   *
   * @param string $raw
   *   The raw content data string to decode.
   *
   * @return array
   *   The decoded content data as an associative array.
   */
  public function decode($raw);

  /**
   * Gets content object names starting with a given prefix.
   *
   * Given the following content objects:
   * - node.type.article
   * - node.type.page
   *
   * Passing the prefix 'node.type.' will return an array containing the above
   * names.
   *
   * @param string $prefix
   *   (optional) The prefix to search for. If omitted, all content object
   *   names that exist are returned.
   *
   * @return array
   *   An array containing matching content object names.
   */
  public function listAll($prefix = '');

  /**
   * Deletes content objects whose names start with a given prefix.
   *
   * Given the following conetnt object names:
   * - node.type.article
   * - node.type.page
   *
   * Passing the prefix 'node.type.' will delete the above content
   * objects.
   *
   * @param string $prefix
   *   (optional) The prefix to search for. If omitted, all content
   *   objects that exist will be deleted.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   */
  public function deleteAll($prefix = '');

  /**
   * Creates a collection on the storage.
   *
   * A content storage can contain multiple sets of content objects
   * in partitioned collections. The collection name identifies the current
   * collection used.
   *
   * Implementations of this method must provide a new instance to avoid side
   * effects caused by the fact that content objects have their storage injected.
   *
   * @param string $collection
   *   The collection name. Valid collection names conform to the following
   *   regex [a-zA-Z_.]. A storage does not need to have a collection set.
   *   However, if a collection is set, then storage should use it to store
   *   content in a way that allows retrieval of content for a
   *   particular collection.
   *
   * @return \Drupal\content_sync\Content\ContentStorageInterface
   *   A new instance of the storage backend with the collection set.
   */
  public function createCollection($collection);

  /**
   * Gets the existing collections.
   *
   * A content storage can contain multiple sets of content objects
   * in partitioned collections. The collection key name identifies the current
   * collection used.
   *
   * @return array
   *   An array of existing collection names.
   */
  public function getAllCollectionNames();

  /**
   * Gets the name of the current collection the storage is using.
   *
   * @return string
   *   The current collection name.
   */
  public function getCollectionName();

}
