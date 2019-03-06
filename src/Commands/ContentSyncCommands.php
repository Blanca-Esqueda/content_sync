<?php

namespace Drupal\content_sync\Commands;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\content_sync\Form\ContentExportTrait;
use Drupal\config\StorageReplaceDataWrapper;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Drush\Utils\FsUtils;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Webmozart\PathUtil\Path;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class ContentSyncCommands extends DrushCommands {

  use ContentExportTrait;
  use DependencySerializationTrait;
  use StringTranslationTrait;

  protected $configManager;

  protected $contentStorage;

  protected $contentStorageSync;

  protected $contentSyncManager;

  protected $entityManager;

  protected $entityTypeManager;

  protected $contentExporter;

  protected $eventDispatcher;

  protected $lock;

  protected $configTyped;

  protected $moduleInstaller;

  protected $themeHandler;

  protected $stringTranslation;

  protected $moduleHandler;

  /**
   * Gets the configManager.
   *
   * @return \Drupal\Core\Config\ConfigManagerInterface
   *   The configManager.
   */
  public function getConfigManager() {
    return $this->configManager;
  }

  /**
   * Gets the contentStorage.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The contentStorage.
   */
  public function getContentStorage() {
    return $this->contentStorage;
  }

  /**
   * Gets the contentStorageSync.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The contentStorageSync.
   */
  public function getContentStorageSync() {
    return $this->contentStorageSync;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getContentExporter() {
    return $this->contentExporter;
  }

  /**
   * {@inheritdoc}
   */
  protected function getLogger() {
    return $this->logger('content_sync');
  }

  /**
   * ContentSyncCommands constructor.
   *
   * @param \Drupal\Core\Config\ConfigManagerInterface $configManager
   *   The configManager.
   * @param \Drupal\Core\Config\StorageInterface $contentStorage
   *   The contentStorage.
   * @param \Drupal\Core\Config\StorageInterface $contentStorageSync
   *   The contentStorageSync.
   * @param \Drupal\content_sync\ContentSyncManagerInterface $contentSyncManager
   *   The contentSyncManager.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entityManager
   *   The entityManager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entityTypeManager.
   * @param \Drupal\content_sync\Exporter\ContentExporterInterface $content_exporter
   *   The contentExporter.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The moduleHandler.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The eventDispatcher.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $configTyped
   *   The configTyped.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $moduleInstaller
   *   The moduleInstaller.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The themeHandler.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The stringTranslation.
   */
  public function __construct(ConfigManagerInterface $configManager, StorageInterface $contentStorage, StorageInterface $contentStorageSync, ContentSyncManagerInterface $contentSyncManager, EntityManagerInterface $entityManager, EntityTypeManagerInterface $entity_type_manager, ContentExporterInterface $content_exporter, ModuleHandlerInterface $moduleHandler, EventDispatcherInterface $eventDispatcher, LockBackendInterface $lock, TypedConfigManagerInterface $configTyped, ModuleInstallerInterface $moduleInstaller, ThemeHandlerInterface $themeHandler, TranslationInterface $stringTranslation) {
    parent::__construct();
    $this->configManager = $configManager;
    $this->contentStorage = $contentStorage;
    $this->contentStorageSync = $contentStorageSync;
    $this->contentSyncManager = $contentSyncManager;
    $this->entityManager = $entityManager;
    $this->entityTypeManager = $entity_type_manager;
    $this->contentExporter = $content_exporter;
    $this->moduleHandler = $moduleHandler;
    $this->eventDispatcher = $eventDispatcher;
    $this->lock = $lock;
    $this->configTyped = $configTyped;
    $this->moduleInstaller = $moduleInstaller;
    $this->themeHandler = $themeHandler;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Import content from a content directory.
   *
   * @param string|null $label
   *   A content directory label (i.e. a key in \$content_directories array in
   *   settings.php).
   * @param array $options
   *   The command options.
   *
   * @command content-sync:import
   * @interact-config-label
   * @option diff Show preview as a diff.
   * @option preview Deprecated. Format for displaying proposed changes. Recognized values: list, diff.
   * @option source An arbitrary directory that holds the content files. An alternative to label argument
   * @option partial Allows for partial content imports from the source directory. Only updates and new contents will be processed with this flag (missing contents will not be deleted).
   * @aliases csi,content-sync-import
   */
  public function import($label = NULL, array $options = [
    'preview' => 'list',
    'source' => FALSE,
    'partial' => FALSE,
    'diff' => FALSE,
  ]) {
    // Determine source directory.
    if ($target = $options['source']) {
      $source_storage = new FileStorage($target);
    }
    else {
      $source_storage = $this->getContentStorageSync();
    }
    $directory = self::getDirectory($label, $options['source']);

    // Determine $source_storage in partial case.
    $active_storage = $this->getContentStorage();
    if ($options['partial']) {
      $replacement_storage = new StorageReplaceDataWrapper($active_storage);
      foreach ($source_storage->listAll() as $name) {
        $data = $source_storage->read($name);
        $replacement_storage->replaceData($name, $data);
      }
      $source_storage = $replacement_storage;
    }

    $config_manager = $this->getConfigManager();
    $storage_comparer = new StorageComparer($source_storage, $active_storage, $config_manager);

    if (!$storage_comparer->createChangelist()->hasChanges()) {
      $this->getLogger()->notice(('There are no changes to import.'));
      return;
    }

    if ($options['preview'] == 'list' && !$options['diff']) {
      $change_list = [];
      foreach ($storage_comparer->getAllCollectionNames() as $collection) {
        $change_list[$collection] = $storage_comparer->getChangelist(NULL, $collection);
      }
      $table = self::contentChangesTable($change_list, $this->output());
      $table->render();
    }
    else {
      $output = self::getDiff($active_storage, $source_storage, $this->output());

      $this->output()->writeln(implode("\n", $output));
    }

    if ($this->io()->confirm(dt('Import the listed content changes?'))) {
      return drush_op([$this, 'doImport'], $change_list, $directory);
    }
    else {
      throw new UserAbortException();
    }
  }

  /**
   * Copied from submitForm() at src/Form/ContentSync.php.
   */
  public function doImport($change_list, $directory) {

    // Set Batch to process the files from the content directory.
    // Get the files to be processed.
    $content_to_sync = $content_to_delete = [];
    foreach ($change_list as $actions) {
      if (!empty($actions['create'])) {
        $content_to_sync = array_merge($content_to_sync, $actions['create']);
      }
      if (!empty($actions['update'])) {
        $content_to_sync = array_merge($content_to_sync, $actions['update']);
      }
      if (!empty($actions['delete'])) {
        $content_to_delete = $actions['delete'];
      }
    }
    $batch = [
      'title' => $this->t('Synchronizing Content...'),
      'message' => $this->t('Synchronizing Content...'),
      'operations' => [
        [[$this, 'syncContent'], [$content_to_sync, $directory]],
        [[$this, 'deleteContent'], [$content_to_delete, $directory]],
      ],
      'finished' => [$this, 'finishBatch'],
    ];

    batch_set($batch);
    drush_backend_batch_process();
  }

  /**
   * Processes the content import batch.
   *
   * @param array $content_to_sync
   *   The content to import.
   * @param string $directory
   *   The source directory.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function syncContent(array $content_to_sync, $directory, &$context) {
    if (empty($context['sandbox'])) {
      $queue = $this->contentSyncManager->generateImportQueue($content_to_sync, $directory);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['queue'] = $queue;
      $context['sandbox']['directory'] = $directory;
      $context['sandbox']['max'] = count($queue);
    }
    if (!empty($context['sandbox']['queue'])) {
      $error = FALSE;
      $item = array_pop($context['sandbox']['queue']);
      $decoded_entity = $item['decoded_entity'];
      $entity_type_id = $item['entity_type_id'];
      $cs_context = [
        'content_sync_directory' => $context['sandbox']['directory'],
      ];
      $entity = $this->contentSyncManager->getContentImporter()
        ->importEntity($decoded_entity, $cs_context);
      if ($entity) {
        $context['results'][] = TRUE;
        $context['message'] = $this->t('Imported content @label (@entity_type: @id).', [
          '@label' => $entity->label(),
          '@id' => $entity->id(),
          '@entity_type' => $entity->getEntityTypeId(),
        ]);
        unset($entity);
      }
      else {
        $error = TRUE;
      }
      if ($error) {
        $context['message'] = $this->t('Error importing content of type @entity_type.', [
          '@entity_type' => $entity_type_id,
        ]);
        if (!isset($context['results']['errors'])) {
          $context['results']['errors'] = [];
        }
        $context['results']['errors'][] = $context['message'];
      }
      if ($error) {
        $this->io()->writeln($context['message']);
      }
    }
    $context['sandbox']['progress']++;
    $context['finished'] = $context['sandbox']['max'] > 0 ? $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
    if ($context['sandbox']['progress'] >= $context['sandbox']['max']) {
      $context['finished'] = 1;
    }
  }

  /**
   * Processes the content delete batch.
   *
   * @param array $content_to_sync
   *   The content to delete.
   * @param string $directory
   *   The source directory.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function deleteContent(array $content_to_sync, $directory, &$context) {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['queue'] = $content_to_sync;
      $context['sandbox']['directory'] = $directory;
      $context['sandbox']['max'] = count($content_to_sync);
    }
    if (!empty($context['sandbox']['queue'])) {
      $error = TRUE;
      $item = array_pop($context['sandbox']['queue']);
      list($entity_type_id, , $uuid) = explode('.', $item);
      $entity = $this->entityManager->loadEntityByUuid($entity_type_id, $uuid);
      if ($entity) {
        try {
          $message = $this->t('Deleted content @label (@entity_type: @id).', [
            '@label' => $entity->label(),
            '@id' => $entity->id(),
            '@entity_type' => $entity->getEntityTypeId(),
          ]);
          $entity->delete();
          $error = FALSE;
        }
        catch (EntityStorageException $e) {
          $message = $e->getMessage();
          $this->io()->writeln($message);
        }
      }
      else {
        $message = $this->t('Error deleting content of type @entity_type.', [
          '@entity_type' => $entity_type_id,
        ]);
      }
    }
    $context['results'][] = TRUE;
    $context['sandbox']['progress']++;
    $context['message'] = $message;

    if ($error) {
      if (!isset($context['results']['errors'])) {
        $context['results']['errors'] = [];
      }
      $context['results']['errors'][] = $context['message'];
    }

    $context['finished'] = $context['sandbox']['max'] > 0 ? $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
    if ($context['sandbox']['progress'] >= $context['sandbox']['max']) {
      $context['finished'] = 1;
    }
  }

  /**
   * Finish batch.
   *
   * This function is a static function to avoid serializing the ConfigSync
   * object unnecessarily.
   */
  public static function finishBatch($success, $results, $operations) {
    if ($success) {
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
          \Drupal::getContainer()->get('content_sync.commands')->io()->writeln($error);
          \Drupal::logger('config_sync')->error($error);
        }
        \Drupal::getContainer()->get('content_sync.commands')->io()->writeln(\Drupal::translation()
          ->translate('The content was imported with errors.')->__toString());
      }
      else {
        \Drupal::getContainer()->get('content_sync.commands')->io()->writeln(\Drupal::translation()
          ->translate('The content was imported successfully.')->__toString());
      }
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      $message = \Drupal::translation()
        ->translate('An error occurred while processing %error_operation with arguments: @arguments', [
          '%error_operation' => $error_operation[0],
          '@arguments' => print_r($error_operation[1], TRUE),
        ])->__toString();
      \Drupal::getContainer()->get('content_sync.commands')->io()->writeln($message);
    }
  }

  /**
   * Export Drupal content to a directory.
   *
   * @param string|null $label
   *   A content directory label (i.e. a key in $content_directories array in
   *   settings.php).
   * @param array $options
   *   The command options.
   *
   * @command content-sync:export
   * @interact-config-label
   * @option destination An arbitrary directory that should receive the exported files. A backup directory is used when no value is provided.
   * @option diff Show preview as a diff, instead of a change list.
   * @usage drush content-sync-export --destination
   *   Export content; Save files in a backup directory named content-export.
   * @aliases cse,content-sync-export
   */
  public function export($label = NULL, array $options = ['destination' => '', 'diff' => FALSE]) {
    // Get destination directory.
    $destination_dir = self::getDirectory($label, $options['destination']);

    drush_op([$this, 'doUpdate'], $destination_dir);

    $temp_source_dir = drush_tempdir();
    $this->getArchiver()->extract($temp_source_dir);
    $temp_source_storage = new FileStorage($temp_source_dir);

    // Do the actual content export operation.
    drush_op([$this, 'doExport'], $options, $destination_dir, $temp_source_storage);
  }

  /**
   * Copied from submitForm() at src/Form/ContentExportForm.php.
   */
  public function doUpdate($destination_dir) {
    // Delete the content tar file in case an older version exist.
    file_unmanaged_delete($this->getTempFile());
    // Set batch operations by entity type/bundle.
    $entities_list = [];
    $entity_type_definitions = $this->entityTypeManager->getDefinitions();
    foreach ($entity_type_definitions as $entity_type => $definition) {
      $reflection = new \ReflectionClass($definition->getClass());
      if ($reflection->implementsInterface(ContentEntityInterface::class)) {
        $entities = $this->entityTypeManager->getStorage($entity_type)
          ->getQuery()
          ->execute();
        foreach ($entities as $entity_id) {
          $entities_list[] = [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
          ];
        }
      }
    }
    if (!empty($entities_list)) {
      $batch = $this->generateBatch($entities_list, ['content_sync_directory' => $destination_dir]);
      batch_set($batch);
      drush_backend_batch_process();
    }
  }

  /**
   * Exports content.
   */
  public function doExport($options, $destination_dir, $temp_source_storage) {
    // Prepare the content storage for the export.
    if ($destination_dir == Path::canonicalize(\content_sync_get_content_directory('sync'))) {
      $target_storage = $this->getContentStorageSync();
    }
    else {
      $target_storage = new FileStorage($destination_dir);
    }

    if (count(glob($destination_dir . '/*')) > 0) {
      // Retrieve a list of differences between the active and target content.
      $content_comparer = new StorageComparer($temp_source_storage, $target_storage, $this->getConfigManager());
      if (!$content_comparer->createChangelist()->hasChanges()) {
        $this->getLogger()->notice(dt('The active content is identical to the content in the export directory (!target).', ['!target' => $destination_dir]));
        return;
      }
      $this->output()->writeln("Differences of the active content to the export directory:\n");

      if ($options['diff']) {
        $diff = self::getDiff($target_storage, $temp_source_storage, $this->output());
        $this->output()->writeln($diff);
      }
      else {
        $change_list = [];
        foreach ($content_comparer->getAllCollectionNames() as $collection) {
          $change_list[$collection] = $content_comparer->getChangelist(NULL, $collection);
        }
        // Print a table with changes in color.
        $table = self::contentChangesTable($change_list, $this->output());
        $table->render();
      }

      if (!$this->io()->confirm(dt('The .yml files in your export directory (!target) will be deleted and replaced with the active content.', ['!target' => $destination_dir]))) {
        throw new UserAbortException();
      }
      // Only delete .yml files, and not .htaccess or .git.
      $target_storage->deleteAll();
    }

    // Write all .yml files.
    self::copyContent($temp_source_storage, $target_storage);

    $this->getLogger()->success(dt('Content successfully exported to !target.', ['!target' => $destination_dir]));
    drush_backend_set_result($destination_dir);
  }

  /**
   * Build a table of content changes.
   *
   * @param array $content_changes
   *   An array of changes keyed by collection.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param bool $use_color
   *   If it should use color.
   *
   * @return \Symfony\Component\Console\Helper\Table
   *   A Symfony table object.
   */
  public static function contentChangesTable(array $content_changes, OutputInterface $output, $use_color = TRUE) {
    $rows = [];
    foreach ($content_changes as $collection => $changes) {
      foreach ($changes as $change => $contents) {
        switch ($change) {
          case 'delete':
            $colour = '<fg=white;bg=red>';
            break;

          case 'update':
            $colour = '<fg=black;bg=yellow>';
            break;

          case 'create':
            $colour = '<fg=white;bg=green>';
            break;

          default:
            $colour = "<fg=black;bg=cyan>";
            break;
        }
        if ($use_color) {
          $prefix = $colour;
          $suffix = '</>';
        }
        else {
          $prefix = $suffix = '';
        }
        foreach ($contents as $content) {
          $rows[] = [
            $collection,
            $content,
            $prefix . ucfirst($change) . $suffix,
          ];
        }
      }
    }
    $table = new Table($output);
    $table->setHeaders(['Collection', 'Content', 'Operation']);
    $table->addRows($rows);
    return $table;
  }

  /**
   * Copies content objects from source storage to target storage.
   *
   * @param \Drupal\Core\Config\StorageInterface $source
   *   The source content storage service.
   * @param \Drupal\Core\Config\StorageInterface $destination
   *   The destination content storage service.
   */
  public static function copyContent(StorageInterface $source, StorageInterface $destination) {
    // Make sure the source and destination are on the default collection.
    if ($source->getCollectionName() != StorageInterface::DEFAULT_COLLECTION) {
      $source = $source->createCollection(StorageInterface::DEFAULT_COLLECTION);
    }
    if ($destination->getCollectionName() != StorageInterface::DEFAULT_COLLECTION) {
      $destination = $destination->createCollection(StorageInterface::DEFAULT_COLLECTION);
    }

    // Export all the content.
    foreach ($source->listAll() as $name) {
      $destination->write($name, $source->read($name));
    }

    // Export content collections.
    foreach ($source->getAllCollectionNames() as $collection) {
      $source = $source->createCollection($collection);
      $destination = $destination->createCollection($collection);
      foreach ($source->listAll() as $name) {
        $destination->write($name, $source->read($name));
      }
    }
  }

  /**
   * Get diff between two content sets.
   *
   * @param \Drupal\Core\Config\StorageInterface $destination_storage
   *   The destination storage.
   * @param \Drupal\Core\Config\StorageInterface $source_storage
   *   The source storage.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   *
   * @return array|bool
   *   An array of strings containing the diff.
   */
  public static function getDiff(StorageInterface $destination_storage, StorageInterface $source_storage, OutputInterface $output) {
    // Copy active storage to a temporary directory.
    $temp_destination_dir = drush_tempdir();
    $temp_destination_storage = new FileStorage($temp_destination_dir);
    self::copyContent($destination_storage, $temp_destination_storage);

    // Copy source storage to a temporary directory as it could be
    // modified by the partial option or by decorated sync storages.
    $temp_source_dir = drush_tempdir();
    $temp_source_storage = new FileStorage($temp_source_dir);
    self::copyContent($source_storage, $temp_source_storage);

    $prefix = 'diff';
    if (drush_program_exists('git') && $output->isDecorated()) {
      $prefix = 'git diff --color=always';
    }
    drush_shell_exec($prefix . ' -u %s %s', $temp_destination_dir, $temp_source_dir);
    return drush_shell_exec_output();
  }

  /**
   * Determine which content directory to use and return directory path.
   *
   * Directory path is determined based on the following precedence:
   *  1. User-provided $directory.
   *  2. Directory path corresponding to $label (mapped via $content_directories
   * in settings.php).
   *  3. Default sync directory.
   *
   * @param string $label
   *   A content directory label.
   * @param string $directory
   *   A content directory.
   */
  public static function getDirectory($label, $directory = NULL) {
    $return = NULL;
    // If the user provided a directory, use it.
    if (!empty($directory)) {
      if ($directory === TRUE) {
        // The user did not pass a specific directory, make one.
        $return = class_exists('FsUtils') ? FsUtils::prepareBackupDir('content-import-export') : drush_prepare_backup_dir('content-import-export');
      }
      else {
        // The user has specified a directory.
        drush_mkdir($directory);
        $return = $directory;
      }
    }
    else {
      // If a directory isn't specified, use the label argument or default sync
      // directory.
      $return = \content_sync_get_content_directory($label ?: 'sync');
    }
    return Path::canonicalize($return);
  }

}
