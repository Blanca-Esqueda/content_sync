<?php

namespace Drupal\content_sync\Content;

use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Core\Serialization\Yaml;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;


/**
 * Defines the file storage.
 */
class ContentFileStorage implements ContentStorageInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The database table name.
   *
   * @var string
   */
  protected $table;

  /**
   * The filesystem path for content objects.
   *
   * @var string
   */
  protected $directory = '';

  /**
   * Additional database connection options to use in queries.
   *
   * @var array
   */
  protected $options = [];

  /**
   * The storage collection.
   *
   * @var string
   */
  protected $collection;
  
  /**
   * The file cache object.
   *
   * @var \Drupal\Component\FileCache\FileCacheInterface
   */
  protected $fileCache;


  /**
   * Constructs a new FileStorage & DatabaseStorage.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   A Database connection to use for reading and writing content data.
   * @param string $table
   *   A database table name to store content data in.
   * @param string $directory
   *   A directory path to use for reading and writing of content files.
   * @param string $collection
   *   (optional) The collection to store content in. Defaults to the
   *   default collection.
   * @param array $options
   *   (optional) Any additional database connection options to use in queries.
   */
  public function __construct(Connection $connection, $table, $directory = '', $collection = ContentStorageInterface::DEFAULT_COLLECTION, array $options = []) {
    $this->connection = $connection;
    $this->table = $table;
    $this->directory = content_sync_get_content_directory(CONFIG_SYNC_DIRECTORY)."/entities";
    $this->collection = $collection;
    $this->options = $options;
    // $this->directory = $directory;
    // Use a NULL File Cache backend by default. This will ensure only the
    // internal static caching of FileCache is used and thus avoids blowing up
    // the APCu cache.
    $this->fileCache = FileCacheFactory::get('config', ['cache_backend_class' => NULL]);
 }


  /**
   * Delete the cs_files_table
   *
   * @throws PDOException
   *
   * @todo Ignore replica targets for data manipulation operations.
   */
  public function deleteFilesDB() {
    $options = ['return' => Database::RETURN_AFFECTED] + $this->options;
    return (bool) $this->connection->delete($this->table, $options)
      ->execute();
  }


  /**
   * Returns the path to the content file.
   *
   * @return string
   *   The path to the content file.
   */
  public function getFilePath($name) {
    return $this->getCollectionDirectory() . '/' . $name . '.' . static::getFileExtension();
  }

  /**
   * Returns the file extension used by the file storage for all content files.
   *
   * @return string
   *   The file extension.
   */
  public static function getFileExtension() {
    return 'yml';
  }

  /**
   * Check if the directory exists and create it if not.
   */
  protected function ensureStorage() {
    $dir = $this->getCollectionDirectory();
    $success = file_prepare_directory($dir, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);
    // Only create .htaccess file in root directory.
    if ($dir == $this->directory) {
      $success = $success && file_save_htaccess($this->directory, TRUE, TRUE);
    }
    if (!$success) {
      throw new StorageException('Failed to create content directory ' . $dir);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function exists($name) {
    return file_exists($this->getFilePath($name));
  }

  /**
   * Implements Drupal\content_sync\Content\ContentStorageInterface::read().
   *
   * @throws \Drupal\Core\Config\UnsupportedDataTypeConfigException
   */
  public function read($name) {
    if (!$this->exists($name)) {
      return FALSE;
    }

    $filepath = $this->getFilePath($name);
    if ($data = $this->fileCache->get($filepath)) {
      return $data;
    }

    $data_content = file_get_contents($filepath);
    try {
      $data = $this->decode($data_content);
    }
    catch (InvalidDataTypeException $e) {   //TODO
      throw new UnsupportedDataTypeConfigException('Invalid data type in content ' . $name . ', found in file' . $filepath . ' : ' . $e->getMessage());
    }
    
    $this->dbWrite($name, $data_content);
    try {
      $this->fileCache->set($filepath, $data);
    }
    catch (\Exception $e) {
      // If there was an exception, try to create the table.
      if ($this->ensureTableExists()) {
        $this->dbWrite($name, $data_content);
      }
      // Some other failure that we can not recover from.
      throw $e;
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function readMultiple(array $names) {
    $list = [];
    foreach ($names as $name) {
      if ($data = $this->read($name)) {
        $list[$name] = $data;
      }
    }
    return $list;
  }

  /**
   * Helper method so we can re-try a db write.
   *
   * @param string $name
   *   The content name.
   * @param string $data
   *   The content data, already dumped to a string.
   *
   * @return bool
   */
  protected function dbWrite($name, $data) {
    $options = ['return' => Database::RETURN_AFFECTED] + $this->options;
    return (bool) $this->connection->merge($this->table, $options)
      ->keys(['collection', 'name'], [$this->collection, $name])
      ->fields(['data' => $data])
      ->execute();
  }


  /**
   * {@inheritdoc}
   */
  public function write($name, array $data) {
    try {
      $encoded_data = $this->encode($data);
    }
    catch (InvalidDataTypeException $e) {
      throw new StorageException("Invalid data type in content $name: {$e->getMessage()}");
    }

    $target = $this->getFilePath($name);
    $status = @file_put_contents($target, $encoded_data);
    if ($status === FALSE) {
      // Try to make sure the directory exists and try writing again.
      $this->ensureStorage();
      $status = @file_put_contents($target, $encoded_data);
    }
    if ($status === FALSE) {
      throw new StorageException('Failed to write content file: ' . $this->getFilePath($name));
    }
    else {
      drupal_chmod($target);
    }

    $this->fileCache->set($target, $data);

    return TRUE;
  }


  /**
   * {@inheritdoc}
   */
  public function delete($name) {
    if (!$this->exists($name)) {
      $dir = $this->getCollectionDirectory();
      if (!file_exists($dir)) {
        throw new StorageException($dir . '/ not found.');
      }
      return FALSE;
    }
    $this->fileCache->delete($this->getFilePath($name));
    return drupal_unlink($this->getFilePath($name));
  }

  /**
   * {@inheritdoc}
   */
  public function rename($name, $new_name) {
    $status = @rename($this->getFilePath($name), $this->getFilePath($new_name));
    if ($status === FALSE) {
      throw new StorageException('Failed to rename content file from: ' . $this->getFilePath($name) . ' to: ' . $this->getFilePath($new_name));
    }
    $this->fileCache->delete($this->getFilePath($name));
    $this->fileCache->delete($this->getFilePath($new_name));
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function encode($data) {
    return Yaml::encode($data);
  }

  /**
   * {@inheritdoc}
   */
  public function decode($raw) {
    $data = Yaml::decode($raw);
    // A simple string is valid YAML for any reason.
    if (!is_array($data)) {
      return FALSE;
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function listAll($prefix = '') {
    $dir = $this->getCollectionDirectory();
    if (!is_dir($dir)) {
      return [];
    }
    $extension = '.' . static::getFileExtension();

    // glob() directly calls into libc glob(), which is not aware of PHP stream
    // wrappers. Same for \GlobIterator (which additionally requires an absolute
    // realpath() on Windows).
    // @see https://github.com/mikey179/vfsStream/issues/2
    $files = scandir($dir);

    $names = [];
    $pattern = '/^' . preg_quote($prefix, '/') . '.*' . preg_quote($extension, '/') . '$/';
    foreach ($files as $file) {
      if ($file[0] !== '.' && preg_match($pattern, $file)) {
        $names[] = basename($file, $extension);
      }
    }

    return $names;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll($prefix = '') {
    $success = TRUE;
    $files = $this->listAll($prefix);
    foreach ($files as $name) {
      if (!$this->delete($name) && $success) {
        $success = FALSE;
      }
    }
    if ($success && $this->collection != ContentStorageInterface::DEFAULT_COLLECTION) {
      // Remove empty directories.
      if (!(new \FilesystemIterator($this->getCollectionDirectory()))->valid()) {
        drupal_rmdir($this->getCollectionDirectory());
      }
    }
    return $success;
  }

  /**
   * {@inheritdoc}
   */
  public function createCollection($collection) {
    return new static(
      $this->connection,
      $this->table,
      $this->directory,
      $collection
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCollectionName() {
    return $this->collection;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllCollectionNames() {
    if (!is_dir($this->directory)) {
      return [];
    }
    $collections = $this->getAllCollectionNamesHelper($this->directory);
    sort($collections);
    return $collections;
  }

  /**
   * Helper function for getAllCollectionNames().
   *
   * If the file storage has the following subdirectory structure:
   *   ./another_collection/one
   *   ./another_collection/two
   *   ./collection/sub/one
   *   ./collection/sub/two
   * this function will return:
   * @code
   *   array(
   *     'another_collection.one',
   *     'another_collection.two',
   *     'collection.sub.one',
   *     'collection.sub.two',
   *   );
   * @endcode
   *
   * @param string $directory
   *   The directory to check for sub directories. This allows this
   *   function to be used recursively to discover all the collections in the
   *   storage. It is the responsibility of the caller to ensure the directory
   *   exists.
   *
   * @return array
   *   A list of collection names contained within the provided directory.
   */
  protected function getAllCollectionNamesHelper($directory) {
    $collections = [];
    $pattern = '/\.' . preg_quote($this->getFileExtension(), '/') . '$/';
    foreach (new \DirectoryIterator($directory) as $fileinfo) {
      if ($fileinfo->isDir() && !$fileinfo->isDot()) {
        $collection = $fileinfo->getFilename();
        // Recursively call getAllCollectionNamesHelper() to discover if there
        // are subdirectories. Subdirectories represent a dotted collection
        // name.
        $sub_collections = $this->getAllCollectionNamesHelper($directory . '/' . $collection);
        if (!empty($sub_collections)) {
          // Build up the collection name by concatenating the subdirectory
          // names with the current directory name.
          foreach ($sub_collections as $sub_collection) {
            $collections[] = $collection . '.' . $sub_collection;
          }
        }
        // Check that the collection is valid by searching it for configuration
        // objects. A directory without any configuration objects is not a valid
        // collection.
        // @see \Drupal\Core\Config\FileStorage::listAll()
        foreach (scandir($directory . '/' . $collection) as $file) {
          if ($file[0] !== '.' && preg_match($pattern, $file)) {
            $collections[] = $collection;
            break;
          }
        }
      }
    }
    return $collections;
  }

  /**
   * Gets the directory for the collection.
   *
   * @return string
   *   The directory for the collection.
   */
  protected function getCollectionDirectory() {
    if ($this->collection == ContentStorageInterface::DEFAULT_COLLECTION) {
      $dir = $this->directory;
    }
    else {
      $dir = $this->directory . '/' . str_replace('.', '/', $this->collection);
    }
    return $dir;
  }


  /**
   * Check if the files content table exists and create it if not.
   *
   * @return bool
   *   TRUE if the table was created, FALSE otherwise.
   *
   * @throws \Drupal\Core\Config\StorageException   //TODO
   *   If a database error occurs.
   */
  protected function ensureTableExists() {
    try {
      if (!$this->connection->schema()->tableExists($this->table)) {
        $this->connection->schema()->createTable($this->table, static::schemaDefinition());
        return TRUE;
      }
    }
    // If another process has already created the content table, attempting to
    // recreate it will throw an exception. In this case just catch the
    // exception and do nothing.
    catch (SchemaObjectExistsException $e) {
      return TRUE;
    }
    catch (\Exception $e) {
      throw new StorageException($e->getMessage(), NULL, $e);
    }
    return FALSE;
  }

  /**
   * Defines the schema for the files content table.
   * Helps ContentComparer to avoid memory limit errors for sites with thousands of content entities.
   *
   * @internal
   */
  protected static function schemaDefinition() {
    $schema = [
      'description' => 'The base table for content data.',
      'fields' => [
        'collection' => [
          'description' => 'Primary Key: Content object collection.',
          'type' => 'varchar_ascii',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
        'name' => [
          'description' => 'Primary Key: Content object name.',
          'type' => 'varchar_ascii',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
        'data' => [
          'description' => 'A serialized content object data.',
          'type' => 'blob',
          'not null' => FALSE,
          'size' => 'big',
        ],
      ],
      'primary key' => ['collection', 'name'],
    ];
    return $schema;
  }

}
