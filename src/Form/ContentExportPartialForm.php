<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;



use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\ContentEntityType;


/**
 * Defines the content export form.
 */
class ContentExportPartialForm extends FormBase {

  use ContentExportTrait;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\content_sync\Exporter\ContentExporterInterface
   */
  protected $contentExporter;

  /**
   * @var \Drupal\content_sync\ContentSyncManagerInterface
   */
  protected $contentSyncManager;

  /**
   * The entity bundle manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfo
   */
  protected $entityBundleManager;

  /**
   * ContentExportForm constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ContentExporterInterface $content_exporter, ContentSyncManagerInterface $content_sync_manager,
    EntityTypeBundleInfo $entity_bundle_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->contentExporter = $content_exporter;
    $this->contentSyncManager = $content_sync_manager;
    $this->entityBundleManager = $entity_bundle_manager;

  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('content_sync.exporter'),
      $container->get('content_sync.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $content_type = 'node', $content_name = NULL, $content_entity = NULL) {
    $entity_types = [];
    $entity_type_definitions = $this->entityTypeManager->getDefinitions();
    foreach ($entity_type_definitions as $entity_type => $definition) {
      if ($definition instanceof ContentEntityType) {
        $entity_types[$entity_type] = $definition->getLabel();
      }
    }

    uasort($entity_types, 'strnatcasecmp');
    $content_types = $entity_types;
    $form['content_type'] = [
      '#title' => $this->t('Content type'),
      '#type' => 'select',
      '#options' => $content_types,
      '#default_value' => $content_type,
      '#attributes' => array('onchange' => 'this.form.content_name.value = null; if(this.form.content_entity){ this.form.content_entity.value = null; }  this.form.submit();'),
    ];

    $default_type = $form_state->getValue('content_type', $content_type);
    $default_name = $form_state->getValue('content_name', $content_name);
    $form['content_name'] = [
      '#title' => $this->t('Content name'),
      '#type' => 'select',
      '#options' => $this->findContent($default_type),
      '#default_value' => $content_name,
      '#attributes' => array('onchange' => 'if(this.form.content_entity){ this.form.content_entity.value = null; }  this.form.submit();'),
    ];

    // Auto-complete field for the content entity
    if($default_type && $default_name){
      $form['content_entity'] = [
        '#title' => $this->t('Content Entity'),
        '#type' => 'entity_autocomplete',
        '#target_type' => $default_type,
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => [$default_name],
        ],
      ]; 
      // Autocomplete doesn't support target bundles parameter on bundle-less entities.
      $target_type = \Drupal::entityManager()->getDefinition($default_type);
      $target_type_bundles = $target_type->getBundleEntityType();
      if(is_null($target_type_bundles)){
        unset($form['content_entity']['#selection_settings']);
      }

      $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export'),
      ];
    }

    return $form;
  }

  /**
   * Handles switching the content type selector.
   */
  protected function findContent($content_type) {
    $names = [
      '' => $this->t('- Select -'),
    ];
    // For a given entity type, load all entities.
    if ($content_type) {
      $entity_storage = $this->entityBundleManager->getBundleInfo($content_type);
      foreach ($entity_storage as $entityKey => $entity) {
        $entity_id = $entityKey;
        if ($label = $entity['label']) {
          $names[$entity_id] = new TranslatableMarkup('@label (@id)', ['@label' => $label, '@id' => $entity_id]);
        }
        else {
          $names[$entity_id] = $entity_id;
        }
      }
    }
    return $names;
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Delete the content tar file in case an older version exist.
    file_unmanaged_delete($this->getTempFile());

    // Get submitted values
    $entity_type = $form_state->getValue('content_type');
    $entity_id = $form_state->getValue('content_entity');

    $entities_list[] = [
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
    ];

    if (!empty($entities_list)) {
      $serializer_context['export_type'] = 'tar';
      $serializer_context['include_files'] = 'folder';
      $serializer_context['include_dependencies'] = TRUE;
      $batch = $this->generateExportBatch($entities_list, $serializer_context);
      batch_set($batch);
    }
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
