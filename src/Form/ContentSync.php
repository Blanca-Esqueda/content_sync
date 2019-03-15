<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Construct the storage changes in a content synchronization form.
 */
class ContentSync extends FormBase {

  use ContentImportTrait;

  /**
   * The sync content object.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $syncStorage;

  /**
   * The active content object.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * The configuration manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface;
   */
  protected $configManager;

  /**
   * @var \Drupal\content_sync\ContentSyncManagerInterface
   */
  protected $contentSyncManager;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\StorageInterface $sync_storage
   *   The source storage.
   * @param \Drupal\Core\Config\StorageInterface $active_storage
   *   The target storage.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   Configuration manager.
   * @param \Drupal\content_sync\ContentSyncManagerInterface $content_sync_manager
   *   The content sync manager.
   */
  public function __construct(StorageInterface $sync_storage, StorageInterface $active_storage, ConfigManagerInterface $config_manager, ContentSyncManagerInterface $content_sync_manager) {
    $this->syncStorage = $sync_storage;
    $this->activeStorage = $active_storage;
    $this->configManager = $config_manager;
    $this->contentSyncManager = $content_sync_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content.storage.sync'),
      $container->get('content.storage'),
      $container->get('config.manager'),
      $container->get('content_sync.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_admin_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Validate site uuid unless bypass the validation is selected
    $config = \Drupal::config('content_sync.settings');
    if ($config->get('content_sync.site_uuid_override') == FALSE) {
      // Get site uuid from site settings configuration.
      $site_config = $this->config('system.site');
      $target = $site_config->get('uuid');
      // Get site uuid from content sync folder
      $source = $this->syncStorage->read('site.uuid');

      if ($source['site_uuid'] !== $target) {
        drupal_set_message($this->t('The staged content cannot be imported, because it originates from a different site than this site. You can only synchronize content between cloned instances of this site.'), 'error');
        $form['actions']['#access'] = FALSE;
        return $form;
      }
    }

    //check that there is something on the content sync folder.
    $source_list = $this->syncStorage->listAll();
    $storage_comparer = new StorageComparer($this->syncStorage, $this->activeStorage, $this->configManager);
    $storage_comparer->createChangelist();

    // Store the comparer for use in the submit.
    $form_state->set('storage_comparer', $storage_comparer);

    // Add the AJAX library to the form for dialog support.
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    // Drupal table element that allows user to select contents to import.
    $form['list'] = [
      '#type' => 'tableselect',
      '#header' => ['name' => $this->t('Content Entity'), 'action' => $this->t('Action'), 'operations' => $this->t('Operations')],
    ];
    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      foreach ($storage_comparer->getChangelist(NULL, $collection) as $content_change_type => $content_names) {
        if (empty($content_names)) {
          continue;
        }
        foreach ($content_names as $content_name) {
          
          if ($content_change_type == 'rename') {
            $names = $storage_comparer->extractRenameNames($content_name);
            $route_options = [
              'source_name' => $names['old_name'],
              'target_name' => $names['new_name'],
            ];
            $content_name = $this->t('@source_name to @target_name', [
              '@source_name' => $names['old_name'],
              '@target_name' => $names['new_name'],
            ]);
          }
          else {
            $route_options = ['source_name' => $content_name];
          }
          if ($collection != StorageInterface::DEFAULT_COLLECTION) {
            $route_name = 'content.diff_collection';
            $route_options['collection'] = $collection;
          }
          else {
            $route_name = 'content.diff';
          }

          $links['view_diff'] = [
            'title' => $this->t('View differences'),
            'url' => Url::fromRoute($route_name, $route_options),
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => json_encode([
                'width' => 700,
              ]),
            ],
          ];
          // Table rows with checkboxs to select content.
          $form['list']['#options'][$content_change_type.'='.$content_name] = [
            'name' => $content_name,
            'action' => $content_change_type,
            'operations' => [
              'data' => [
                '#type' => 'operations',
                '#links' => $links,
              ],
            ],
          ];
        }
      }
    }
    if(isset($form['list']['#options'])){
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Import'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Contents selected to update.
    $content['delete'] = [];
    $content['sync'] = [];
    $entities = $form_state->getValue('list');  
    foreach ($entities as $entity) {
      $entity = explode('=', $entity);
      list($action, $identifier) = $entity;
      $content[$action][] = $identifier;
    }
    if (!empty($content['create'])) {
      $content['sync'] = array_merge($content['sync'], $content['create']);
    }
    if (!empty($content['update'])) {
      $content['sync'] = array_merge($content['sync'], $content['update']);
    }    

    $serializer_context = [];
    $batch = $this->generateImportBatch($content['sync'], $content['delete'], $serializer_context);
    batch_set($batch);

    /*
    $comparer = $form_state->get('storage_comparer');
    $collections = $comparer->getAllCollectionNames();
    //Set Batch to process the files from the content directory.
    //Get the files to be processed
    $content_to_sync = [];
    $content_to_delete = [];
    foreach ($collections as $collection => $collection_name) {
      $actions = $comparer->getChangeList("", $collection_name);
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
    $serializer_context = [];
    $batch = $this->generateImportBatch($content_to_sync, $content_to_delete, $serializer_context);
    batch_set($batch);*/

  }

}
