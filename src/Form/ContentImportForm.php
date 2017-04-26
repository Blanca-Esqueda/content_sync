<?php

namespace Drupal\content_sync\Form;

use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the content import form.
 */
class ContentImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    //TODO -- Find out how to declare the content folder  -- task for sync.
    $app_root = \Drupal::root();
    $directory = $app_root.'/../content/sync';
    $directory_is_writable = is_writable($directory);
    if (!$directory_is_writable) {
      drupal_set_message($this->t('The directory %directory is not writable.', ['%directory' => $directory]), 'error');
    }

    $form['import_tarball'] = [
      '#type' => 'file',
      '#title' => $this->t('Configuration archive'),
      '#description' => $this->t('Allowed types: @extensions.', ['@extensions' => 'tar.gz tgz tar.bz2']),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload'),
      '#disabled' => !$directory_is_writable,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $all_files = $this->getRequest()->files->get('files', []);
    if (!empty($all_files['import_tarball'])) {
      $file_upload = $all_files['import_tarball'];
      if ($file_upload->isValid()) {
        $form_state->setValue('import_tarball', $file_upload->getRealPath());
        return;
      }
    }
    $form_state->setErrorByName('import_tarball', $this->t('The file could not be uploaded.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($path = $form_state->getValue('import_tarball')) {
      //TODO -- Find out how to declare the content folder  -- task for sync.
      $app_root = \Drupal::root();
      $directory = $app_root.'/../content/sync';
      emptyDirectory($directory);

      try {
        $archiver = new ArchiveTar($path, 'gz');
        $files = [];
        foreach ($archiver->listContent() as $file) {
          $files[] = $file['filename'];
        }
        $archiver->extractList($files, $directory);
        drupal_set_message($this->t('Your content files were successfully uploaded'));
        //Set Batch to process the files from the content directory.
        //Get the files to be processed
        $files = scan_dir($directory);
        //Flat the files array
        array_walk_recursive($files, function($a) use (&$data) { $data[] = $a; });
        $operations = [];
        $operations[] = ['processContentDirectoryBatch', [$data]];
        $operations[] = ['processContentDirectoryBatch', [$data]];
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
      catch (\Exception $e) {
        drupal_set_message($this->t('Could not extract the contents of the tar file. The error message is <em>@message</em>', ['@message' => $e->getMessage()]), 'error');
      }
      unlink($path);
    }
  }
}

/*
 * Help to count the number of files in the directory.
 */
function scan_dir($dir) { 
  $result = array(); 
  $cdir = scandir($dir); 
  foreach ($cdir as $key => $value){ 
    if (!in_array($value,array(".",".."))){ 
      if (is_dir($dir . DIRECTORY_SEPARATOR . $value)) { 
        $result[$value] = scan_dir($dir . DIRECTORY_SEPARATOR . $value); 
      } else { 
        $result[] = $dir."/".$value; 
      } 
    } 
  } 
  return $result; 
} 

/* 
 * Help to empty a directory
 */
function emptyDirectory($dirname,$self_delete=false) {
   if (is_dir($dirname))
      $dir_handle = opendir($dirname);
   if (!$dir_handle)
      return false;
   while($file = readdir($dir_handle)) {
      if ($file != "." && $file != "..") {
         if (!is_dir($dirname."/".$file))
            @unlink($dirname."/".$file);
         else
            emptyDirectory($dirname.'/'.$file,true);    
      }
   }
   closedir($dir_handle);
   if ($self_delete){
        @rmdir($dirname);
   }   
   return true;
}
