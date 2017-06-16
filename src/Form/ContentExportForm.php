<?php

namespace Drupal\content_sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityType;

/**
 * Defines the content export form.
 */
class ContentExportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Delete the content tar file in case an older version exist.
    file_unmanaged_delete(file_directory_temp() . '/content.tar.gz');

    //Entity types manager
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityBundles = \Drupal::service("entity_type.bundle.info");
    //Set batch operations by entity type/bundle
    $operations = [];
    $operations[] = ['generateSiteUUIDFile', [0=>0]];

    $entity_type_definitions = $entityTypeManager->getDefinitions();
    foreach ($entity_type_definitions as $entity_type => $definition) {
      if ($definition instanceof ContentEntityType) {
        $entity_bundles = $entityBundles->getBundleInfo($entity_type);
        foreach ($entity_bundles as $entity_bundle => $bundle) {
          //Get BundleKey
          $bundleKey = \Drupal::entityTypeManager()->getStorage($entity_type)->getEntityType()->getKey('bundle');
          if (!empty($bundleKey)) {
            // Load entities by their property values.
            $entities = \Drupal::entityTypeManager()
              ->getStorage($entity_type)
              ->loadByProperties(array($bundleKey => $entity_bundle));
          }else{
            $entities = \Drupal::entityTypeManager()
              ->getStorage($entity_type)
              ->loadMultiple();
          }
          $entity = [];
          foreach($entities as $entity_id => $entity_obj) {
            $entity['values'][] = [
              'entity_type' => $entity_type,
              'entity_bundle' => $entity_bundle,
              'entity_id' => $entity_id
            ];
          }
          if(!empty($entity)) {
            $operations[] = ['processContentExportFiles', $entity];
          }
        }
      }
    }
    if(empty($operations)){
      $operations[] = ['processContentExportFiles', [0=>0] ];
    }
    //Set Batch
    $batch = [
      'operations' => $operations,
      'finished' => 'finishContentExportBatch',
      'title' => $this->t('Exporting content'),
      'init_message' => $this->t('Starting content export.'),
      'progress_message' => $this->t('Completed @current step of @total.'),
      'error_message' => $this->t('Content export has encountered an error.'),
      'file' => drupal_get_path('module', 'content_sync') . '/content_sync.batch.inc',
    ];
    batch_set($batch);
  }
}
