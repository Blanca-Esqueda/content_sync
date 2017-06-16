<?php

namespace Drupal\content_sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Serialization\Yaml;

/**
 * Provides a form for importing a single content file.
 */
class ContentSingleImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_single_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['import'] = array(
      '#title' => $this->t('Paste your content here'),
      '#type' => 'textarea',
      '#rows' => 24,
      '#required' => TRUE,
    );
    
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    try {
      // Decode the submitted import.
      $data = Yaml::decode($form_state->getValue('import'));
      // Store the decoded version of the submitted import.
      $form_state->setValueForElement($form['import'], $data);
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('import', $this->t('The import failed with the following message: %message', ['%message' => $e->getMessage()]));
      $this->logger('content_sync')->error('The import failed with the following message: %message', ['%message' => $e->getMessage(), 'link' => 'Import Single']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $data = $form_state->getValue('import');
    $operations = [];
    $operations[] = ['processContentBatch', [$data]];
    $batch = [
      'operations' => $operations,
      'finished' => 'finishContentBatch',
      'title' => $this->t('Importing content'),
      'init_message' => $this->t('Starting content import.'),
      'progress_message' => $this->t('Completed @current step of @total.'),
      'error_message' => $this->t('Content import has encountered an error.'),
      'file' => drupal_get_path('module', 'content_sync') . '/content_sync.batch.inc',
    ];
    batch_set($batch);
  }
}
