<?php

namespace Drupal\content_sync\Controller;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Content\ContentStorageInterface;
use Drupal\content_sync\Content\ContentStorageComparer;
use Drupal\Core\Diff\DiffFormatter;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Component\Utility\Unicode;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\Core\Url;

use Drupal\content_sync\Form\ContentExportTrait; 
use Drupal\Core\StringTranslation\StringTranslationTrait;


/**
 * Controller for Content Sync
 */
class ContentSyncController implements ContainerInjectionInterface {

  use ContentExportTrait;
  use StringTranslationTrait;

  /**
   * The sync content object.
   *
   * @var \Drupal\content_sync\Content\ContentStorageInterface
   */
  protected $syncStorage;

  /**
   * The active content object.
   *
   * @var \Drupal\content_sync\Content\ContentStorageInterface
   */
  protected $activeStorage;

  /**
   * The content sync manager.
   *
   * @var \Drupal\content_sync\ContentSyncManagerInterface
   */
  protected $contentSyncManager;

  /**
   * The diff formatter.
   *
   * @var \Drupal\Core\Diff\DiffFormatter
   */
  protected $diffFormatter;

  /**
   * Constructs a ContentController object.
   *
   * @param \Drupal\content_sync\Content\ContentStorageInterface $sync_storage
   *   The source storage.
   * @param \Drupal\content_sync\Content\ContentStorageInterface $active_storage
   *   The target storage.
   * @param \Drupal\content_sync\ContentSyncManagerInterface $content_sync_manager
   *   The content sync manager.
   */
  public function __construct(ContentStorageInterface $sync_storage, ContentStorageInterface $active_storage, ContentSyncManagerInterface $content_sync_manager, DiffFormatter $diff_formatter) {
    $this->syncStorage = $sync_storage;
    $this->activeStorage = $active_storage;
    $this->contentSyncManager = $content_sync_manager;
    $this->diffFormatter = $diff_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content.storage.sync'),
      $container->get('content.storage'),
      $container->get('content_sync.manager'),
      $container->get('diff.formatter')
    );
  }

  /**
   * Create export change list.
   */
  public function exportChangeList($options = []) {
    //Generate comparer with filters.  -- Add results in the queue table.
    $storage_comparer = new ContentStorageComparer($this->activeStorage, $this->syncStorage);
    return $storage_comparer->filterChangeList($options);
  }

  /**
   * Process content export.
   */
  public function processExport($change_list = [], $options = []) {

    $storage_comparer = new ContentStorageComparer($this->activeStorage, $this->syncStorage);

    $entities_list = [];
    foreach ($change_list as $collection => $changes) {
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
   // $include_files = self::processFilesOption($options);

    // Set the Export Batch
    if (!empty($entities_list)) {
      $batch = $this->generateExportBatch($entities_list,
        ['export_type' => 'folder',
         'include_files' => 'none',
         'include_dependencies' => $options['include-dependencies']]);
      batch_set($batch);
      drush_backend_batch_process();
    }
  }

  /**
   * Create import change list.
   */
  public function importChangeList() {
    //Generate comparer with filters.  -- Add results in the queue table.
    $storage_comparer = new ContentStorageComparer($this->syncStorage, $this->activeStorage);
    $storage_comparer->filterChangeList($options);
  }

  /**
   * Downloads a tarball of the site content.
   */
  public function downloadExport() {
    $filename = 'content.tar.gz';
    $file_path = file_directory_temp() . '/' . $filename;
    if (file_exists($file_path) ) {
      unset($_SESSION['content_tar_download_file']);
      $mime = \Drupal::service('file.mime_type.guesser')->guess($file_path);
      $headers = array(
        'Content-Type' => $mime . '; name="' . Unicode::mimeHeaderEncode(basename($file_path)) . '"',
        'Content-Length' => filesize($file_path),
        'Content-Disposition' => 'attachment; filename="' . Unicode::mimeHeaderEncode($filename) . '"',
        'Cache-Control' => 'private',
      ); 
      return new BinaryFileResponse($file_path, 200, $headers);
    }
    return -1;
  }

  /**
   * Shows diff of specified content file.
   *
   * @param string $source_name
   *   The name of the content file.
   * @param string $target_name
   *   (optional) The name of the target content file if different from
   *   the $source_name.
   * @param string $collection
   *   (optional) The content collection name. Defaults to the default
   *   collection.
   *
   * @return string
   *   Table showing a two-way diff between the active and staged content.
   */
  public function diff($source_name, $target_name = NULL, $collection = NULL) {
    if (!isset($collection)) {
      $collection = ContentStorageInterface::DEFAULT_COLLECTION;
    }
    $diff = $this->contentSyncManager->diff($this->syncStorage, $this->activeStorage, $source_name, $target_name, $collection);
    $this->diffFormatter->show_header = FALSE;

    $build = [];

    $build['#title'] = t('View changes of @content_file', ['@content_file' => $source_name]);
    // Add the CSS for the inline diff.
    $build['#attached']['library'][] = 'system/diff';

    $build['diff'] = [
      '#type' => 'table',
      '#attributes' => [
        'class' => ['diff'],
      ],
      '#header' => [
        ['data' => t('Active'), 'colspan' => '2'],
        ['data' => t('Staged'), 'colspan' => '2'],
      ],
      '#rows' => $this->diffFormatter->format($diff),
    ];

    $build['back'] = [
      '#type' => 'link',
      '#attributes' => [
        'class' => [
          'dialog-cancel',
        ],
      ],
      '#title' => "Back to 'Synchronize content' page.",
      '#url' => Url::fromRoute('content.sync'),
    ];

    return $build;
  }


  /**
   * @{@inheritdoc}
   */
  protected function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * @{@inheritdoc}
   */
  protected function getContentExporter() {
    return $this->contentExporter;
  }

  /**
   * @{@inheritdoc}
   */
  protected function getExportLogger() {
    return $this->logger('content_sync');
  }


}
