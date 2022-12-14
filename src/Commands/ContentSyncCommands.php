<?php

namespace Drupal\content_sync\Commands;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\content_sync\Form\ContentExportTrait;
use Drupal\content_sync\Form\ContentImportTrait;
use Drupal\content_sync\Form\ContentSync;
use Drupal\config\StorageReplaceDataWrapper;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\content_sync\Content\ContentStorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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
  use ContentImportTrait;
  use DependencySerializationTrait;
  use StringTranslationTrait;


  protected $configManager;

  protected $contentStorage;

  protected $contentStorageSync;

  protected $contentSyncManager;

  protected $entityTypeManager;

  protected $contentExporter;

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
  protected function getExportLogger() {
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
  public function __construct(ConfigManagerInterface $configManager, StorageInterface $contentStorage, StorageInterface $contentStorageSync, ContentSyncManagerInterface $contentSyncManager, EntityTypeManagerInterface $entity_type_manager, ContentExporterInterface $content_exporter, ModuleHandlerInterface $moduleHandler, EventDispatcherInterface $eventDispatcher, LockBackendInterface $lock, TypedConfigManagerInterface $configTyped, ModuleInstallerInterface $moduleInstaller, ThemeHandlerInterface $themeHandler, TranslationInterface $stringTranslation) {
    parent::__construct();
    $this->configManager = $configManager;
    $this->contentStorage = $contentStorage;
    $this->contentStorageSync = $contentStorageSync;
    $this->contentSyncManager = $contentSyncManager;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.manager'),
      $container->get('content.storage'),
      $container->get('content.storage.sync'),
      $container->get('content_sync.manager')
    );
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
   * @option entity-types A list of entity type names separated by commas.
   * @option uuids A list of UUIDs separated by commas.
   * @option actions A list of Actions separated by commas.
   * @option skiplist skip the change list before proceed with the import.
   * @usage drush content-sync-import.
   * @aliases csi,content-sync-import
   */
  public function import($label = NULL, array $options = [
    'entity-types' => '',
    'uuids' => '',
    'actions' => '',
    'skiplist' => FALSE ]) {

    //Generate comparer with filters.
    $storage_comparer = new ContentStorageComparer($this->contentStorageSync, $this->contentStorage,  $this->configManager);
    $change_list = [];
    $collections = $storage_comparer->getAllCollectionNames();
    if (!empty($options['entity-types'])){
      $entity_types = explode(',', $options['entity-types']);
      $match_collections = [];
      foreach ($entity_types as $entity_type){
        $match_collections = $match_collections + preg_grep('/^'.$entity_type.'/', $collections);
      }
      $collections = $match_collections;
    }
    foreach ($collections as $collection){
      if (!empty($options['uuids'])){
        $storage_comparer->createChangelistbyCollectionAndNames($collection, $options['uuids']);
      }else{
        $storage_comparer->createChangelistbyCollection($collection);
      }
      if (!empty($options['actions'])){
        $actions = explode(',', $options['actions']);
        foreach ($actions as $op){
          if (in_array($op, ['create','update','delete'])){
            $change_list[$collection][$op] = $storage_comparer->getChangelist($op, $collection);
          }
        }
      }else{
        $change_list[$collection] = $storage_comparer->getChangelist(NULL, $collection);
      }
      $change_list = array_map('array_filter', $change_list);
      $change_list = array_filter($change_list);
    }
    unset($change_list['']);

    // Display the change list.
    if (empty($options['skiplist'])){
      //Show differences
      $this->output()->writeln("Differences of the export directory to the active content:\n");
      // Print a table with changes in color.
      $table = self::contentChangesTable($change_list, $this->output());
      $table->render();
      // Ask to continue
      if (!$this->io()->confirm(dt('Do you want to import?'))) {
        throw new UserAbortException();
      }
    }
    //Process the Import Data
    $content_to_sync = [];
    $content_to_delete = [];
    foreach ($change_list as $collection => $actions) {
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
    // Set the Import Batch
    if (!empty($content_to_sync) || !empty($content_to_delete)) {
      $batch = $this->generateImportBatch($content_to_sync,
        $content_to_delete);
      batch_set($batch);
      drush_backend_batch_process();
    }
  }


  /**
   * Export Drupal content to a directory.
   *
   * @param string|null $label
   *   A content directory label (i.e. a key in $content_directories array in
   *   settings.php).
   *
   * @param array $options
   *   The command options.
   *
   * @command content-sync:export
   * @interact-config-label
   * @option entity-types A list of entity type names separated by commas.
   * @option uuids A list of UUIDs separated by commas.
   * @option actions A list of Actions separated by commas.
   * @option files A value none/base64/folder  -  default folder.
   * @option include-dependencies export content dependencies.
   * @option skiplist skip the change list before proceed with the export.
   * @usage drush content-sync-export.
   * @aliases cse,content-sync-export.
   */
  public function export($label = NULL, array $options = [
    'entity-types' => '',
    'uuids' => '',
    'actions' => '',
    'files' => '',
    'include-dependencies' => FALSE,
    'skiplist' => FALSE ]) {

    //Generate comparer with filters.
    $storage_comparer = new ContentStorageComparer($this->contentStorage, $this->contentStorageSync, $this->configManager);
    $change_list = [];
    $collections = $storage_comparer->getAllCollectionNames();
    if (!empty($options['entity-types'])){
      $entity_types = explode(',', $options['entity-types']);
      $match_collections = [];
      foreach ($entity_types as $entity_type){
        $match_collections = $match_collections + preg_grep('/^'.$entity_type.'/', $collections);
      }
      $collections = $match_collections;
    }
    foreach ($collections as $collection){
      if (!empty($options['uuids'])){
        $storage_comparer->createChangelistbyCollectionAndNames($collection, $options['uuids']);
      }else{
        $storage_comparer->createChangelistbyCollection($collection);
      }
      if (!empty($options['actions'])){
        $actions = explode(',', $options['actions']);
        foreach ($actions as $op){
          if (in_array($op, ['create','update','delete'])){
            $change_list[$collection][$op] = $storage_comparer->getChangelist($op, $collection);
          }
        }
      }else{
        $change_list[$collection] = $storage_comparer->getChangelist(NULL, $collection);
      }
      $change_list = array_map('array_filter', $change_list);
      $change_list = array_filter($change_list);
    }
    unset($change_list['']);

    // Display the change list.
    if (empty($options['skiplist'])){
      //Show differences
      $this->output()->writeln("Differences of the active content to the export directory:\n");
      // Print a table with changes in color.
      $table = self::contentChangesTable($change_list, $this->output());
      $table->render();
      // Ask to continue
      if (!$this->io()->confirm(dt('Do you want to export?'))) {
        throw new UserAbortException();
      }
    }

    //Process the Export.
    $entities_list = [];
    foreach ($change_list as $collection => $changes) {
      //$storage_comparer->getTargetStorage($collection)->deleteAll();
      foreach ($changes as $change => $contents) {
        switch ($change) {
          case 'delete':
            foreach ($contents as $content) {
              $storage_comparer->getTargetStorage($collection)->delete($content);
            }
            break;
          case 'update':
          case 'create':
            foreach ($contents as $content) {
              //$data = $storage_comparer->getSourceStorage($collection)->read($content);
              //$storage_comparer->getTargetStorage($collection)->write($content, $data);
              $entity = explode('.', $content);
              $entities_list[] = [
                'entity_type' => $entity[0],
                'entity_uuid' => $entity[2],
              ];
            }
            break;
        }
      }
    }
    // Files options
    $include_files = self::processFilesOption($options);

    // Set the Export Batch
    if (!empty($entities_list)) {
      $batch = $this->generateExportBatch($entities_list,
        ['export_type' => 'folder',
         'include_files' => $include_files,
         'include_dependencies' => $options['include-dependencies']]);
      batch_set($batch);
      drush_backend_batch_process();
    }
  }

  /**
   * Builds a table of content changes.
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
      if(is_array($changes)){
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
    }
    $table = new Table($output);
    $table->setHeaders(['Collection', 'Content Name', 'Operation']);
    $table->addRows($rows);
    return $table;
  }

  /**
   * Processes 'files' option.
   *
   * @param array $options
   *   The command options.
   * @return string
   *   Processed 'files' option value.
   */
  public static function processFilesOption($options) {
    $include_files = !empty($options['files']) ? $options['files'] : 'folder';
    if (!in_array($include_files, ['folder', 'base64'])) $include_files = 'none';
    return $include_files;
  }
}
