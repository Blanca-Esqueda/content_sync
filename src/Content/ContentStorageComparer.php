<?php

namespace Drupal\content_sync\Content;

use Drupal\Core\Config\StorageComparer as StorageComparer;
use Drupal\Core\Config\StorageInterface;


/**
 * Extends config storage comparer.
 */
class ContentStorageComparer extends StorageComparer {

  /**
   * {@inheritdoc}
   */
  public function createChangelistbyCollection($collection) {
    $this->changelist[$collection] = $this->getEmptyChangelist();
    $this->getAndSortConfigData($collection);
    $this->addChangelistCreate($collection);
    $this->addChangelistUpdate($collection);
    $this->addChangelistDelete($collection);
    // Only collections that support configuration entities can have renames.
    if ($collection == StorageInterface::DEFAULT_COLLECTION) {
      $this->addChangelistRename($collection);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function createChangelistbyCollectionAndNames($collection, $names) {
    $this->changelist[$collection] = $this->getEmptyChangelist();
    if ($this->getAndSortContentDataByCollectionAndNames($collection, $names)){
      $this->addChangelistCreate($collection);
      $this->addChangelistUpdate($collection);
      $this->addChangelistDelete($collection);
      // Only collections that support configuration entities can have renames.
      if ($collection == StorageInterface::DEFAULT_COLLECTION) {
        $this->addChangelistRename($collection);
      }
    }
    return $this;
  }

  /**
   * Gets and sorts configuration data from the source and target storages.
   */
  protected function getAndSortContentDataByCollectionAndNames($collection, $names) {
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
      // Prime the static caches by reading all the configuration in the source
      // and target storages.
      $target_data = $target_storage->readMultiple($target_names);
      $source_data = $source_storage->readMultiple($source_names);
      $this->targetNames[$collection] = $target_names;
      $this->sourceNames[$collection] = $source_names;
      return true;
    }
    return false;
  }
}
