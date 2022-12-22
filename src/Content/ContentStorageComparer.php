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
  public function createChangelistbyCollection($collection, $use_dates = FALSE) {
    $this->changelist[$collection] = $this->getEmptyChangelist();
    $this->getAndSortConfigData($collection);
    $this->addChangelistCreate($collection);
    $use_dates ? $this->addChangelistUpdateByDate($collection) : $this->addChangelistUpdate($collection);
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

    /**
     * Creates the update changelist with revision date comparison.
     *
     * The list of updates is sorted so that dependencies are created before
     * configuration entities that depend on them. For example, field storages
     * should be updated before fields.
     *
     * @param string $collection
     *   The storage collection to operate on.
     */
    protected function addChangelistUpdateByDate($collection)
    {
        $recreates = [];
        foreach (array_intersect($this->sourceNames[$collection], $this->targetNames[$collection]) as $name) {
            $source_data = $this->getSourceStorage($collection)->read($name);
            $target_data = $this->getTargetStorage($collection)->read($name);
            if ($source_data !== $target_data) {
                if (isset($source_data['uuid']) && $source_data['uuid'] !== $target_data['uuid']) {
                    // The entity has the same file as an existing entity but the UUIDs do
                    // not match. This means that the entity has been recreated so config
                    // synchronization should do the same.
                    $recreates[] = $name;
                } elseif (!array_key_exists('changed', $source_data) || !array_key_exists('changed', $target_data)) {
                  $this->addChangeList($collection, 'update', [$name]);
                } else {
                    if (strtotime($source_data['changed'][count($source_data['changed']) - 1]['value']) >
                        strtotime($target_data['changed'][count($target_data['changed']) - 1]['value']))
                    {
                        $this->addChangeList($collection, 'update', [$name]);
                    }
                }
            }
        }
    }
}
