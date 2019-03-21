<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Content\ContentDatabaseStorage;
use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\File\FileSystemInterface;

/**
 * Defines the content export form.
 */
trait ContentExportTrait {

  /**
   * @var ArchiveTar
   */
  protected $archiver;

  /**
   * @param $entities
   *
   * @param $serializer_context
   * export_type:
   * Tar -> YML to Tar file
   * Snapshot -> YML to content_sync table.
   * Directory -> YML to content_sync_directory_entities.
   *
   * content_sync_directory_entities:
   * path for the content sync directory.
   *
   * content_sync_directory_files:
   * path to store media/files.
   *
   * content_sync_file_base_64:
   * Include file as a data in the YAML.
   *
   * @return array
   */
  public function generateExportBatch($entities, $serializer_context = []) {
    $serializer_context['content_sync_directory_entities'] =  content_sync_get_content_directory(CONFIG_SYNC_DIRECTORY)."/entities";
    if (isset($serializer_context['include_files'])){
      if ($serializer_context['include_files'] == 'folder'){
        $serializer_context['content_sync_directory_files'] =  content_sync_get_content_directory(CONFIG_SYNC_DIRECTORY)."/files";
      }
      if ($serializer_context['include_files'] == 'base64'){
        $serializer_context['content_sync_file_base_64'] = TRUE;
      }
      unset($serializer_context['include_files']);
    }

    //Set batch operations by entity type/bundle
    $operations = [];
    $operations[] = [[$this, 'generateSiteUUIDFile'], [0 => $serializer_context]];
    

    // Generate temporary queue table
    //$query_queue_columns = " SELECT 'identifier', 'entity_type', 'id_type', 'visited', 'processed' ";
    //$cs_queue = \Drupal::database()->queryTemporary($query_queue_columns);

    //If table doesn't exist
    // Create a temporaty queue table  //TEMPORARY 
    \Drupal::database()->query("
    CREATE TABLE IF NOT EXISTS cs_queue (
        identifier VARCHAR(100) NOT NULL,  
        entity_type VARCHAR (50) NOT NULL,
        id_type VARCHAR(20) NOT NULL,
        visited BOOLEAN DEFAULT 0,
        processed BOOLEAN DEFAULT 0 )
    ");

    $query = \Drupal::database()->delete('cs_queue')
                                ->execute();


    foreach ($entities as $entity) {
      if (isset($entity['entity_uuid']) || isset($entity['entity_id'])){
        if (isset($entity['entity_uuid'])){
          $entity['entity_id'] = $entity['entity_uuid'];
          $entity['id_type'] = 'entity_uuid';
        }else{
          $entity['id_type'] = 'entity_id';
        }
        if(!empty($entity['entity_id'])){
          //Insert entity identifier in table cs_queue
          $query = \Drupal::database()->merge('cs_queue')
                                      ->key(['identifier' =>  $entity['entity_id'], 'entity_type' => $entity['entity_type'] ])
                                      ->fields([
                                        'identifier' =>  $entity['entity_id'],
                                        'entity_type' => $entity['entity_type'],
                                        'id_type' => $entity['id_type'],
                                        ])
                                      ->execute();
        }
      }
    }

    // TODO
    // is it needed to pass an entity???
    // Set batch only if there is entities to process..
    //$operations[] = [[$this, 'processContentExportFiles'], [[$entity], $serializer_context]];
    $operations[] = [[$this, 'processContentExportFiles'], [$serializer_context]];
    //Set Batch
    $batch = [
      'operations' => $operations,
      'title' => $this->t('Exporting content'),
      'init_message' => $this->t('Starting content export.'),
      'progress_message' => $this->t('Completed @current step of @total.'),
      'error_message' => $this->t('Content export has encountered an error.'),
    ];
    if (isset($serializer_context['export_type'])
      && $serializer_context['export_type'] == 'tar') {
      $batch['finished'] = [$this,'finishContentExportBatch'];
    }
    return $batch;
  }

  /**
   * Processes the content archive export batch
   *
   * @param $files
   *   The batch content to persist.
   * @param $serializer_context
   * @param array $context
   *   The batch context.
   */
  public function processContentExportFiles($serializer_context = [], &$context) {
    $query =\Drupal::database()->select('cs_queue', 'csq')
                               ->fields('csq')
                               ->condition('processed', 0, '=')
                               ->range(0, 1);
    //->sort('created', 'DESC') 
    $item = $query->execute()->fetchAssoc();
    $item[$item['id_type']] =  $item['identifier'];

    // Get submitted values
    $entity_type = $item['entity_type'];

    //Validate that it is a Content Entity
    $instances = $this->getEntityTypeManager()->getDefinitions();
    if (!(isset($instances[$entity_type]) && $instances[$entity_type] instanceof ContentEntityType)) {
      $context['results']['errors'][] = $this->t('Entity type does not exist or it is not a content instance.') . $entity_type;
    }
    else {
      if (isset($item['entity_uuid'])){
        $entity_id = $item['entity_uuid'];
        $entity = $this->getEntityTypeManager()->getStorage($entity_type)
                       ->loadByProperties(['uuid' => $entity_id]);
        $entity = array_shift($entity);
      }else{
        $entity_id = $item['entity_id'];
        $entity = $this->getEntityTypeManager()->getStorage($entity_type)
                       ->load($entity_id);
      }

      //Make sure the entity exist for export
      if(empty($entity)){
        $context['results']['errors'][] = $this->t('Entity does not exist:') . $entity_type . "(".$entity_id.")";
      }else{

        // Create the name
        $bundle = $entity->bundle();
        $uuid = $entity->uuid();
        $name = $entity_type . "." .  $bundle . "." . $uuid;

        if (!isset($context['exported'][$name])) {

          // Generate the YAML file.
          $exported_entity = $this->getContentExporter()
                                  ->exportEntity($entity, $serializer_context);

          if (isset($serializer_context['export_type'])){
            if ($serializer_context['export_type'] == 'snapshot') {
              //Save to cs_db_snapshot table.
              $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
              $activeStorage->cs_write($name, Yaml::decode($exported_entity), $entity_type.'.'.$bundle);
            }else{
              // Compate the YAML from the snapshot.
              // If for some reason is not on our snapshoot then add it.
              // Or if the new YAML is different the update it.
              $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
              $exported_entity_snapshoot = $activeStorage->cs_read($name);

              if (!$exported_entity_snapshoot || Yaml::encode($exported_entity_snapshoot) !== $exported_entity ){
                //Save to cs_db_snapshot table.
                $activeStorage->cs_write($name, Yaml::decode($exported_entity), $entity_type.'.'.$bundle);
              }

              if ($serializer_context['export_type'] == 'tar') {
                // YAML in Archive .
                $this->getArchiver()->addString("entities/$entity_type/$bundle/$name.yml", $exported_entity);

                // Include Files to the archiver.
                if (method_exists($entity, 'getFileUri')
                    && !empty($serializer_context['content_sync_directory_files']) ) {
                  $uri = $entity->getFileUri();
                  $scheme = \Drupal::service('file_system')->uriScheme($uri);
                  $destination = "{$serializer_context['content_sync_directory_files']}/{$scheme}/";
                  $destination = str_replace($scheme . '://', $destination, $uri);
                  $strip_path = str_replace('/files' , '', $serializer_context['content_sync_directory_files'] );
                  $this->getArchiver()->addModify($destination, '', $strip_path);
                }
              }
              if( $serializer_context['export_type'] == 'folder') {
                // YAML in a directory.
                $path = $serializer_context['content_sync_directory_entities']."/$entity_type/$bundle";
                $destination = $path . "/$name.yml";
                file_prepare_directory($path, FILE_CREATE_DIRECTORY);
                $file = file_unmanaged_save_data($exported_entity, $destination, FILE_EXISTS_REPLACE);
              }

              // Invalidate the CS Cache of the entity.
              $cache = \Drupal::cache('content')->invalidate($entity_type.".".$bundle.":".$name);

              
              if($serializer_context['include_dependencies']){
                // Make sure the identifier is a UUID.
                if ($item['id_type'] == 'entity_id'){
                  // remove the UUID record if it exists - it was added by the resolver.
                  $query = \Drupal::database()->delete('cs_queue')
                                              ->condition('identifier', $uuid , '=')
                                              ->condition('entity_type', $item['entity_type'], '=')
                                              ->execute();
                  // Update the original record with the UUID
                  $query =\Drupal::database()->update('cs_queue')
                                             ->fields(['identifier' => $uuid, 'id_type' => 'entity_uuid'])
                                             ->condition('identifier', $item['identifier'], '=')
                                             ->condition('entity_type', $item['entity_type'], '=')
                                             ->execute();
                  $item['identifier'] = $uuid;
                }
                // Check dependencies only if entity hasn't been checked for dependencies.
                $query =\Drupal::database()->select('cs_queue', 'csq')
                                           ->fields('csq')
                                           ->condition('identifier', $item['identifier'], '=')
                                           ->condition('entity_type', $item['entity_type'], '=')
                                           ->condition('visited', 1, '=');
                $visited = !(bool) $query->countQuery()->execute()->fetchField();
                if ($visited){
                  $exported_entity = Yaml::decode($exported_entity);
                  // Add dependencies to the queue
                  $queue = $this->contentSyncManager->generateExportQueue($exported_entity);
                  //Set the dependencies-visited flag for the entity.
                  $query =\Drupal::database()->update('cs_queue')
                               ->fields(['visited' => 1])
                               ->condition('identifier', $item['identifier'], '=')
                               ->condition('entity_type', $item['entity_type'], '=')
                               ->execute();
                } 
              }
            }
          }
        }
      }
    }
    $context['message'] = $name;
    $context['results'][] = $name;
    //$context['sandbox']['progress']++;
    //$context['finished'] = $context['sandbox']['max'] > 0
    //                    && $context['sandbox']['progress'] < $context['sandbox']['max'] ?
    //                       $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
  
    //Set as processed the current entity.
    $query =\Drupal::database()->update('cs_queue')
                               ->fields(['processed' => 1])
                               ->condition('identifier', $item['identifier'], '=')
                               ->condition('entity_type', $item['entity_type'], '=')
                               ->execute();


    // Total enntities being processed.
    $query =\Drupal::database()->select('cs_queue', 'csq')
                               ->fields('csq');
    $context['sandbox']['max'] = $query->countQuery()->execute()->fetchField();
    // Finished is 1 when all the items in the queue table are processed.
    $query =\Drupal::database()->select('cs_queue', 'csq')
                               ->fields('csq')
                               ->condition('processed', 1, '=');
    //$context['finished'] = !(bool) $query->countQuery()->execute()->fetchField();
    $context['sandbox']['progress'] = $query->countQuery()->execute()->fetchField();
    $context['finished'] = $context['sandbox']['max'] > 0
                        && $context['sandbox']['progress'] < $context['sandbox']['max'] ?
                           $context['sandbox']['progress'] / $context['sandbox']['max'] : 1;
  }

  /**
   * Generate UUID YAML file
   * To use for site UUID validation.
   *
   * @param $data
   *   The batch content to persist.
   * @param array $context
   *   The batch context.
   */
  public function generateSiteUUIDFile($serializer_context, &$context) {
    //Include Site UUID to YML file
    $site_config = \Drupal::config('system.site');
    $site_uuid_source = $site_config->get('uuid');
    $entity['site_uuid'] = $site_uuid_source;

    // Set the name
    $name = "site.uuid";
    if (isset($serializer_context['export_type'])){
      if ($serializer_context['export_type'] == 'snapshot') {
        //Save to cs_db_snapshot table.
        $activeStorage = new ContentDatabaseStorage(\Drupal::database(), 'cs_db_snapshot');
        $activeStorage->write($name, $entity);
      }elseif( $serializer_context['export_type'] == 'tar') {
        // Add YAML to the archiver
        $this->getArchiver()->addString("entities/$name.yml", Yaml::encode($entity));
      }elseif( $serializer_context['export_type'] == 'folder') {
        $path = $serializer_context['content_sync_directory_entities'];
        $destination = $path . "/$name.yml";
        file_prepare_directory($path, FILE_CREATE_DIRECTORY);
        $file = file_unmanaged_save_data(Yaml::encode($entity), $destination, FILE_EXISTS_REPLACE);
      }
    }
    $context['message'] = $name;
    $context['results'][] = $name;
    $context['finished'] = 1;
  }

  /**
   * Finish batch.
   *
   * Provide information about the Content Batch results.
   */
   public function finishContentExportBatch($success, $results, $operations) {
    if ($success) {
      if (isset($results['errors'])){
        $errors = $results['errors'];
        unset($results['errors']);
      }
      $results = array_unique($results);
      // Log all the items processed
      foreach ($results as $key => $result) {
        if ($key != 'errors') {
          //drupal_set_message(t('Processed UUID @title.', array('@title' => $result)));
          $this->getExportLogger()
               ->info('Processed UUID @title.', [
                 '@title' => $result,
                 'link' => 'Export',
               ]);
        }
      }
      if (isset($errors) && !empty($errors)) {
        // Log the errors
        $errors = array_unique($errors);
        foreach ($errors as $error) {
          //drupal_set_message($error, 'error');
          $this->getExportLogger()->error($error);
        }
        // Log the note that the content was exported with errors.
        drupal_set_message($this->t('The content was exported with errors. <a href=":content-overview">Logs</a>', [':content-overview' => \Drupal::url('content.overview')]), 'warning');
        $this->getExportLogger()
             ->warning('The content was exported with errors.', ['link' => 'Export']);
      }
      else {
        // Log the new created export link if applicable.
        drupal_set_message($this->t('The content was exported successfully. <a href=":export-download">Download tar file</a>', [':export-download' => \Drupal::url('content.export_download')]));
        $this->getExportLogger()
             ->info('The content was exported successfully. <a href=":export-download">Download tar file</a>', [
               ':export-download' => \Drupal::url('content.export_download'),
               'link' => 'Export',
             ]);
      }
    }
    else {
      // Log that there was an error
      $message = $this->t('Finished with an error.<a href=":content-overview">Logs</a>', [':content-overview' => \Drupal::url('content.overview')]);
      drupal_set_message($message);
      $this->getExportLogger()
           ->error('Finished with an error.', ['link' => 'Export']);
    }
  }

  protected function getArchiver() {
    if (!isset($this->archiver)) {
      $this->archiver = new ArchiveTar($this->getTempFile(), 'gz');
    }
    return $this->archiver;
  }

  protected function getTempFile() {
    return file_directory_temp() . '/content.tar.gz';
  }

  /**
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  abstract protected function getEntityTypeManager();

  /**
   * @return \Drupal\content_sync\Exporter\ContentExporterInterface
   */
  abstract protected function getContentExporter();

  /**
   * @return \Psr\Log\LoggerInterface
   */
  abstract protected function getExportLogger();

}
