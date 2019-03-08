<?php

namespace Drupal\content_sync\Content;

use Drupal\Core\Config\DatabaseStorage as DatabaseStorage;
use Drupal\Core\Database\Database;

/**
 * Defines the Database storage.
 */
class ContentDatabaseStorage extends DatabaseStorage {

  /**
   * {@inheritdoc}
   */
  public function cs_write($name, array $data, $collection) {
    $data = $this->encode($data);
    try {
      return $this->cs_doWrite($name, $data, $collection);
    }
    catch (\Exception $e) {
      // If there was an exception, try to create the table.
      if ($this->ensureTableExists()) {
        return $this->cs_doWrite($name, $data, $collection);
      }
      // Some other failure that we can not recover from.
      throw $e;
    }
  }

  /**
   * Helper method so we can re-try a write.
   *
   * @param string $name
   *   The content name.
   * @param string $data
   *   The content data, already dumped to a string.
   * @param string $collection
   *   The content collection name, entity type + bundle.
   *
   * @return bool
   */
  protected function cs_doWrite($name, $data, $collection) {
    $options = ['return' => Database::RETURN_AFFECTED] + $this->options;
    return (bool) $this->connection->merge($this->table, $options)
      ->keys(['collection', 'name'], [$collection, $name])
      ->fields(['data' => $data])
      ->execute();
  }

}
