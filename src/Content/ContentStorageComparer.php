<?php

namespace Drupal\content_sync\Content;

use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Defines a content storage comparer.
 */
class ContentStorageComparer implements ContentStorageComparerInterface {
  use DependencySerializationTrait;

  /**
   * The source storage used to discover content changes.
   *
   * @var \Drupal\content_sync\Content\ContentStorageInterface
   */
  protected $sourceStorage;

  /**
   * The source storages keyed by collection.
   *
   * @var \Drupal\content_sync\Content\ContentStorageInterface[]
   */
  protected $sourceStorages;

  /**
   * The target storage used to write content changes.
   *
   * @var \Drupal\content_sync\Content\ContentStorageInterface
   */
  protected $targetStorage;

  /**
   * The target storages keyed by collection.
   *
   * @var \Drupal\content_sync\Content\ContentStorageInterface[]
   */
  protected $targetStorages;

  /**
   * List of changes to between the source storage and the target storage.
   *
   * The list is keyed by storage collection name.
   *
   * @var array
   */
  protected $changelist;

  /**
   * Sorted list of all the content object names in the source storage.
   *
   * The list is keyed by storage collection name.
   *
   * @var array
   */
  protected $sourceNames = [];

  /**
   * Sorted list of all the content object names in the target storage.
   *
   * The list is keyed by storage collection name.
   *
   * @var array
   */
  protected $targetNames = [];

  /**
   * A memory cache backend to statically cache source content data.
   *
   * @var \Drupal\Core\Cache\MemoryBackend
   */
  protected $sourceCacheStorage;

  /**
   * A memory cache backend to statically cache target content data.
   *
   * @var \Drupal\Core\Cache\MemoryBackend
   */
  protected $targetCacheStorage;

  /**
   * Constructs the Content storage comparer.
   *
   * @param \Drupal\content_sync\Content\ContentStorageInterface $source_storage
   *   Storage object used to read content.
   * @param \Drupal\content_sync\Content\ContentStorageInterface $target_storage
   *   Storage object used to write content.
   */
  public function __construct(ContentStorageInterface $source_storage, ContentStorageInterface $target_storage) {
    // Wrap the storages in a static cache so that multiple reads of the same
    // raw content object are not costly.
    $this->sourceCacheStorage = new MemoryBackend();
    $this->sourceStorage = new ContentCachedStorage(
      $source_storage,
      $this->sourceCacheStorage
    );
    $this->targetCacheStorage = new MemoryBackend();
    $this->targetStorage = new ContentCachedStorage(
      $target_storage,
      $this->targetCacheStorage
    );
    $this->changelist[ContentStorageInterface::DEFAULT_COLLECTION] = $this->getEmptyChangelist();
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceStorage($collection = ContentStorageInterface::DEFAULT_COLLECTION) {
    if (!isset($this->sourceStorages[$collection])) {
      if ($collection == ContentStorageInterface::DEFAULT_COLLECTION) {
        $this->sourceStorages[$collection] = $this->sourceStorage;
      }
      else {
        $this->sourceStorages[$collection] = $this->sourceStorage->createCollection($collection);
      }
    }
    return $this->sourceStorages[$collection];
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetStorage($collection = ContentStorageInterface::DEFAULT_COLLECTION) {
    if (!isset($this->targetStorages[$collection])) {
      if ($collection == ContentStorageInterface::DEFAULT_COLLECTION) {
        $this->targetStorages[$collection] = $this->targetStorage;
      }
      else {
        $this->targetStorages[$collection] = $this->targetStorage->createCollection($collection);
      }
    }
    return $this->targetStorages[$collection];
  }

  /**
   * {@inheritdoc}
   */
  public function getEmptyChangelist() {
    return [
      'create' => [],
      'update' => [],
      'delete' => [],
      'rename' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getChangelist($op = NULL, $collection = ContentStorageInterface::DEFAULT_COLLECTION) {
    if ($op) {
      return $this->changelist[$collection][$op];
    }
    return $this->changelist[$collection];
  }

  /**
   * Adds changes to the changelist.
   *
   * @param string $collection
   *   The storage collection to add changes for.
   * @param string $op
   *   The change operation performed. Either delete, create, rename, or update.
   * @param array $changes
   *   Array of changes to add to the changelist.
   * @param array $sort_order
   *   Array to sort that can be used to sort the changelist. This array must
   *   contain all the items that are in the change list.
   */
  protected function addChangeList($collection, $op, array $changes, array $sort_order = NULL) {
    // Only add changes that aren't already listed.
    $changes = array_diff($changes, $this->changelist[$collection][$op]);
    $this->changelist[$collection][$op] = array_merge($this->changelist[$collection][$op], $changes);
    if (isset($sort_order)) {
      $count = count($this->changelist[$collection][$op]);
      // Sort the changelist in the same order as the $sort_order array and
      // ensure the array is keyed from 0.
      $this->changelist[$collection][$op] = array_values(array_intersect($sort_order, $this->changelist[$collection][$op]));
      if ($count != count($this->changelist[$collection][$op])) {
        throw new \InvalidArgumentException("Sorting the $op changelist should not change its length.");
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createChangelist() {
    foreach ($this->getAllCollectionNames() as $collection) {
      $this->changelist[$collection] = $this->getEmptyChangelist();
      $this->getContentData($collection);
      $this->addChangelistCreate($collection);
      $this->addChangelistUpdate($collection);
      $this->addChangelistDelete($collection);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function createChangelistbyCollection($collection) {
    $this->changelist[$collection] = $this->getEmptyChangelist();
    $this->getContentData($collection);
    $this->addChangelistCreate($collection);
    $this->addChangelistUpdate($collection);
    $this->addChangelistDelete($collection);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function createChangelistbyCollectionAndNames($collection, $names) {
    $this->changelist[$collection] = $this->getEmptyChangelist();
    if ($this->getContentDataByCollectionAndNames($collection, $names)){
      $this->addChangelistCreate($collection);
      $this->addChangelistUpdate($collection);
      $this->addChangelistDelete($collection);
    }
    return $this;
  }

  /**
   * Creates the delete changelist.
   *
   * @param string $collection
   *   The storage collection to operate on.
   */
  protected function addChangelistDelete($collection) {
    $deletes = array_diff(array_reverse($this->targetNames[$collection]), $this->sourceNames[$collection]);
    $this->addChangeList($collection, 'delete', $deletes);
  }

  /**
   * Creates the create changelist.
   *
   * @param string $collection
   *   The storage collection to operate on.
   */
  protected function addChangelistCreate($collection) {
    $creates = array_diff($this->sourceNames[$collection], $this->targetNames[$collection]);
    $this->addChangeList($collection, 'create', $creates);
  }

  /**
   * Creates the update changelist.
   *
   * @param string $collection
   *   The storage collection to operate on.
   */
  protected function addChangelistUpdate($collection) {
    $recreates = [];
    foreach (array_intersect($this->sourceNames[$collection], $this->targetNames[$collection]) as $name) {
      $source_data = $this->getSourceStorage($collection)->read($name);
      $target_data = $this->getTargetStorage($collection)->read($name);
      if ($source_data !== $target_data) {
        if (isset($source_data['uuid']) && $source_data['uuid'] !== $target_data['uuid']) {
          // The entity has the same file as an existing entity but the UUIDs do
          // not match. This means that the entity has been recreated so content
          // synchronization should do the same.
          $recreates[] = $name;
        }
        else {
          $this->addChangeList($collection, 'update', [$name]);
        }
      }
    }

    if (!empty($recreates)) {
      // Recreates should become deletes and creates. Deletes should be ordered
      // so that dependencies are deleted first.
      $this->addChangeList($collection, 'create', $recreates, $this->sourceNames[$collection]);
      $this->addChangeList($collection, 'delete', $recreates, array_reverse($this->targetNames[$collection]));

    }
  }


  /**
   * {@inheritdoc}
   */
  public function reset() {
    $this->changelist = [ContentStorageInterface::DEFAULT_COLLECTION => $this->getEmptyChangelist()];
    $this->sourceNames = $this->targetNames = [];
    // Reset the static content data caches.
    $this->sourceCacheStorage->deleteAll();
    $this->targetCacheStorage->deleteAll();
    return $this->createChangelist();
  }

  /**
   * {@inheritdoc}
   */
  public function hasChanges() {
    foreach ($this->getAllCollectionNames() as $collection) {
      foreach (['delete', 'create', 'update', 'rename'] as $op) {
        if (!empty($this->changelist[$collection][$op])) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateSiteUuid() {
    $source = $this->sourceStorage->read('system.site');
    $target = $this->targetStorage->read('system.site');
    return $source['uuid'] === $target['uuid'];
  }

  /**
   * Gets content data from the source and target storages.
   */
  protected function getContentData($collection) {
    $source_storage = $this->getSourceStorage($collection);
    $target_storage = $this->getTargetStorage($collection);
    $target_names = $target_storage->listAll();
    $source_names = $source_storage->listAll();
    // Prime the static caches by reading all the conentent in the source
    // and target storages.
    $target_data = $target_storage->readMultiple($target_names);
    $source_data = $source_storage->readMultiple($source_names);

    $this->targetNames[$collection] = $target_names;
    $this->sourceNames[$collection] = $source_names;
  }

  
  /**
  * Gets content data from the source and target storages.
  */
  protected function getContentDataByCollectionAndNames($collection, $names) {
    $names = explode(',', $names);
    $target_names = [];
    $source_names = [];
    foreach($names as $key => $name){
      $name = $collection.'.'.$name;
      $source_storage = $this->getSourceStorage($collection);
      $target_storage = $this->getTargetStorage($collection);
      if($source_storage->exists($name) ||
        $target_storage->exists($name) ){
        $target_names = array_merge($target_names, $target_storage->listAll($name));
        $source_names = array_merge($source_names, $source_storage->listAll($name));
      }
    }
    $target_names = array_filter($target_names);
    $source_names = array_filter($source_names);
    if(!empty($target_names) || !empty($source_names)){
      // Prime the static caches by reading all the content in the source
      // and target storages.
      $target_data = $target_storage->readMultiple($target_names);
      $source_data = $source_storage->readMultiple($source_names);
      $this->targetNames[$collection] = $target_names;
      $this->sourceNames[$collection] = $source_names;
      return true;
    }
    return false;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllCollectionNames($include_default = TRUE) {
    $collections = array_unique(array_merge($this->sourceStorage->getAllCollectionNames(), $this->targetStorage->getAllCollectionNames()));
    if ($include_default) {
      array_unshift($collections, ContentStorageInterface::DEFAULT_COLLECTION);
    }
    return $collections;
  }

}
