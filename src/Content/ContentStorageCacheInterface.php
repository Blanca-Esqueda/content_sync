<?php

namespace Drupal\content_sync\Content;

/**
 * Defines an interface for cached content storage.
 */
interface ContentStorageCacheInterface {

  /**
   * Reset the static cache of the listAll() cache.
   */
  public function resetListCache();

}
