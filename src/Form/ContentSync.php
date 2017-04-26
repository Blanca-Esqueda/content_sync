<?php

namespace Drupal\content_sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Construct the storage changes in a content synchronization form.
 */
class ContentSync extends FormBase {

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
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
