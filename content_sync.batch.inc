<?php
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Archiver\ArchiveTar;

/**
 * Processes the content import batch and persists the importer.
 *
 * @param $data
 *   The batch content to persist.
 * @param array $context
 *   The batch context.
 */
function processContentBatch($data, &$context) {
  // Initialize Batch
  if ( empty($context['sandbox']) ) {
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['current_number'] = 0;
    $context['sandbox']['max'] = (int) count($data['values']);
  }

  // Entity Type and Field Manager
  $entityTypeManager = \Drupal::entityTypeManager();
  $entityFieldManager = \Drupal::service('entity_field.manager');
  $entityBundles = \Drupal::service("entity_type.bundle.info");
  $entityRepository = \Drupal::service('entity.repository');

  // Process data only if there is a valid entity type and bundle flag
  $valid_entity_type = TRUE;

  // Validate SiteID.
  $site_config = \Drupal::config('system.site');
  $site_uuid_source = $site_config->get('uuid');
  // Get site ID
  if ( isset($data['site_uuid']) ) {
    $site_uuid_target = $data['site_uuid'];
  }
  // Validate SiteID.
  if ( empty($site_uuid_target) || $site_uuid_target != $site_uuid_source ) {
    $context['results']['errors'][] = t('The staged content cannot be imported, because it originates from a different site than this site. You can only synchronize content between cloned instances of this site.');
    $context['sandbox']['max'] = $context['sandbox']['progress'];
    $valid_entity_type = FALSE;
  }

  // Get entity type
  if ( isset($data['entity_type']) ) {
    $entity_type = $data['entity_type'];
  }

  // Get entity bundle
  if ( isset($data['bundle']) ) {
    $entity_bundle = $data['bundle'];
  }

  // Validate entity_type and bundle
  if ( empty($entity_type) || empty($entity_bundle) ) {
    $context['results']['errors'][] = t('Entity Type and Bundle are required.');
    $context['sandbox']['max'] = $context['sandbox']['progress'];
    $valid_entity_type = FALSE;
  }
  elseif ( !empty($entity_type) && !empty($entity_bundle) ) {
    // Check that entity type exists and it is a content instance
    $instances = $entityTypeManager->getDefinitions();
    if ( !(isset($instances[$entity_type]) && $instances[$entity_type] instanceof ContentEntityType) ) {
      $context['results']['errors'][] = t('Entity type does not exist or it is not a content instance.');
      $context['sandbox']['max'] = $context['sandbox']['progress'];
      $valid_entity_type = FALSE;
    }
    else {
      // Verify that bundle exists
      if ( !isset($entityBundles->getBundleInfo($entity_type)[$entity_bundle]) ) {
        $context['results']['errors'][] = t('Bundle does not exist, please review the site configuration.');
        $context['sandbox']['max'] = $context['sandbox']['progress'];
        $valid_entity_type = FALSE;
      }
      else {
        // Check if there are data to be inserted.
        if ( !isset($data['values']) ) {
          $context['results']['errors'][] = t('Values to be imported not found, check the YAML file.');
          $context['sandbox']['max'] = $context['sandbox']['progress'];
          $valid_entity_type = FALSE;
        }
      }
    }
  }

  //Process data
  if ( $valid_entity_type ) {
    // Initialize array of elements to save.
    $entity_elements = [];

    // Get Entity Fields.
    $fields = array_filter(
      $entityFieldManager->getFieldDefinitions($entity_type, $entity_bundle), function ($field_definition) {
       return $field_definition;
      }
    );
    foreach ($fields as $fieldID => $field) {
      $entity_elements[$field->getName()] = $field->getName();
    }

    // Get Entity Properties - to know the id and bundle fields.
    $properties = $entityTypeManager->getDefinitions()[$entity_type]->getKeys();
    // Remove property ID as we are gonna use UUID to avoid conflicts.
    unset($entity_elements[$properties['id']]);
    $entity_elements = array_filter($entity_elements);

    // Get arrays of content to be inserted.
    $data_value = $data['values'][$context['sandbox']['progress']];

    // Remove vid to avoid conflicts w/revisions.
    unset($data_value['vid']);

    // Gather Content to import
    $record = [];
    $data_value[$properties['bundle']] = $entity_bundle;
    foreach ($entity_elements as $elementID => $element) {
      // Set value for entity fields -  from import or default.
      if ( isset($data_value[$element]) ) {
        // TODO --- Validate type of field value.  -- DataType.
        // TODO --- Is required ???

        $record['values'][$element] = $data_value[$element];
        // If it is entity reference get the id from the uuid
        $element_type = $fields[$element]->getType();
        if ( $element_type == "entity_reference" ||
          $element_type == "image" ||
          $element_type == "file"
        ) {
          // Get entity type
          $reference_type = $fields[$element]->getSettings()['target_type'];
          // Loop all the values
          if ( is_array($data_value[$element]) ) {
            foreach ($data_value[$element] as $er_key => $er_val) {
              $entity = $entityRepository->loadEntityByUuid($reference_type, $er_val['target_id']);
              unset($record['values'][$element][$er_key]['target_id']);
              if ( !empty($entity) ) {
                $record['values'][$element][$er_key]['target_id'] = $entity->id();
              }
            }
          }
        }
      }
    }
    //$record['values']['path']['pathauto'] = TRUE;

    // Translations
    if ( isset($data_value['translations']) && is_array($data_value['translations']) ) {
      foreach ($data_value['translations'] as $translations) {
        $langcode = $translations['langcode'][0]['value'];
        // TODO --- verify if langcode is available in the site
        $translations[$properties['bundle']] = $entity_bundle;
        foreach ($entity_elements as $elementID => $element) {
          if ( isset($translations[$element]) ) {
            // Only translatable elements for translations
            if ( $fields[$elementID]->isTranslatable() == TRUE ) {
              $record['translations'][$langcode][$element] = $translations[$element];
              // If it is entity reference get the id from the uuid
              $element_type = $fields[$element]->getType();
              if ( $element_type == "entity_reference" ||
                $element_type == "image" ||
                $element_type == "file"
              ) {
                // Get entity type
                $reference_type = $fields[$element]->getSettings()['target_type'];
                // Loop all the values
                if ( is_array($translations[$element]) ) {
                  foreach ($translations[$element] as $er_key => $er_val) {
                    $entity = $entityRepository->loadEntityByUuid($reference_type, $er_val['target_id']);
                    unset($record['translations'][$langcode][$element][$er_key]['target_id']);
                    if ( !empty($entity) ) {
                      $record['translations'][$langcode][$element][$er_key]['target_id'] = $entity->id();
                    }
                  }
                }
              }
            } // TODO --- Warning about untranslatable fields set on translations.
          }
        }
        $record['translations'][$langcode]['path']['pathauto'] = TRUE;
      }
    }

    // Flag to detect if the entity exist or is a new one.
    $new_entity = TRUE;

    // Get the id of the the parent uuid.
    if ( isset($record['values']['parent']) ) {
      // Load the entity from the UUID
      $parent_uuid = $record['values']['parent'];
      $entity = $entityRepository->loadEntityByUuid($entity_type, $parent_uuid);
      // Set parent only if the parent UUID exist.
      unset($record['values']['parent']);
      if ( !empty($entity) ) {
        // Get the  parent entity id and exchange it for the uuid.
        $record['values']['parent'] = $entity->id();
      }
    }

    // Check the UUID of the entity being imported.
    if ( isset($record['values']['uuid'][0]['value']) ) {
      // Load the entity from the UUID
      $uuid = $record['values']['uuid'][0]['value'];
      $entity = $entityRepository->loadEntityByUuid($entity_type, $uuid);
      // If the entity exist then update it.
      if ( !empty($entity) ) {
        // Set the new entity flag as false.
        $new_entity = FALSE;
        foreach ($entity_elements as $elementID => $element) {
          if ( isset($record['values'][$elementID]) ) {
            $entity->set($elementID, $record['values'][$elementID]);
          }
          else {
            // Get default value.
            $default_value = $fields[$elementID]->getDefaultValue($entity);
            $entity->set($elementID, $default_value);
          }
        }
        $entity->Save();
      }
    }
    //  Entity is new, add it.
    if ( $new_entity ) {
      $entity = $entityTypeManager->getStorage($entity_type)
        ->create($record['values']);
      $entity->Save();
    }

    // Include Translations
    $lang_default = $entity->langcode->value;
    // Remove translations if they are in the import data the they would be inserted.
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      // Verify that it is not the default langcode.
      if ( $langcode != $lang_default ) {
        $entity->removeTranslation($langcode);
      }
    }
    if ( isset($record['translations']) && is_array($record['translations']) ) {
      // Commented because automatically pathauto has been set.
      // \Drupal::service('path.alias_storage')->save("/taxonomy/term/" . $term->id(), "/".$entity_bundle."/".$term->id(), $lancode_original);
      foreach ($record['translations'] as $langcode => $translation) {
        // Add Translation if it is not the default language
        if ( $langcode != $lang_default ) {
          $term_translation = $entity->addTranslation($langcode);
          foreach ($translation as $itemID => $item) {
            $term_translation->$itemID->setValue($item);
          }
          $term_translation->save();
          // Commented because automatically pathauto has been set.
          // \Drupal::service('path.alias_storage')->save("/taxonomy/term/" . $term->id(), "/".$entity_bundle."/".$langcode.$term->id(), $langcode);
        }
      }
    }

    // Batch Context status.
    $context['results'][] = $entity->uuid();
    $context['message'] = 'UUID - ' . $entity->uuid();
  }
  $context['sandbox']['progress']++;
  if ( $context['sandbox']['progress'] != $context['sandbox']['max'] ) {
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }
}

/*
 *  Process Content Directory
 */
function processContentDirectoryBatch($files, &$context) {
  //Initialize Batch
  if ( empty($context['sandbox']) ) {
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['current_number'] = 0;
    $context['sandbox']['max'] = (int) count($files);
  }

  // Validate file YAML format
  $file = $files[$context['sandbox']['progress']];
  $info = pathinfo($file);
  if ( strtolower($info["extension"]) != "yml" ) {
    $context['results']['errors'][] = t("File Skipped: ") . $file;
    $context['message'] = "File Skipped: " . $file;
    $context['sandbox']['progress']++;
    if ( $context['sandbox']['progress'] != $context['sandbox']['max'] ) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }
  else {
    //Load the yml file and decode.
    try {
      $file_data = file_get_contents($file);
      $data = Yaml::decode($file_data);
    }
    catch (\Exceptio $e) {
      $context['results']['errors'][] = t('Error: %message.', ['%message' => $e->getMessage()]);
      $data = "";
    }
    // Entity Manager
    //$entityManager = \Drupal::entityManager();

    // Entity Type and Field Manager
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityFieldManager = \Drupal::service('entity_field.manager');
    $entityBundles = \Drupal::service("entity_type.bundle.info");
    $entityRepository = \Drupal::service('entity.repository');

    // Process data only if there is a valid entity type and bundle flag
    $valid_entity_type = TRUE;

    // Validate SiteID.
    $site_config = \Drupal::config('system.site');
    $site_uuid_source = $site_config->get('uuid');
    // Get site ID
    if ( isset($data['site_uuid']) ) {
      $site_uuid_target = $data['site_uuid'];
    }
    // Validate SiteID.
    if ( empty($site_uuid_target) || $site_uuid_target != $site_uuid_source ) {
      $context['results']['errors'][] = t('The staged content cannot be imported, because it originates from a different site than this site. You can only synchronize content between cloned instances of this site.');
      $context['sandbox']['max'] = $context['sandbox']['progress'];
      $valid_entity_type = FALSE;
    }

    // Get entity type
    if ( isset($data['entity_type']) ) {
      $entity_type = $data['entity_type'];
    }
    // Get entity bundle
    if ( isset($data['bundle']) ) {
      $entity_bundle = $data['bundle'];
    }
    // Validate entity_type and bundle
    if ( empty($entity_type) || empty($entity_bundle) ) {
      $context['results']['errors'][] = t('Entity Type and Bundle are required.') . $file;
      //$context['sandbox']['max'] = $context['sandbox']['progress'];
      $context['sandbox']['progress']++;
      $valid_entity_type = FALSE;
    }
    elseif ( !empty($entity_type) && !empty($entity_bundle) ) {
      // Check that entity type exists and it is a content instance
      $instances = $entityTypeManager->getDefinitions();
      if ( !(isset($instances[$entity_type]) && $instances[$entity_type] instanceof ContentEntityType) ) {
        $context['results']['errors'][] = t('Entity type does not exist or it is not a content instance.') . $file;
        $context['sandbox']['progress']++;
        $valid_entity_type = FALSE;
      }
      else {
        // Verify that bundle exists
        if ( !isset($entityBundles->getBundleInfo($entity_type)[$entity_bundle]) ) {
          $context['results']['errors'][] = t('Bundle does not exist, please review the site configuration.') . $file;
          $context['sandbox']['progress']++;
          $valid_entity_type = FALSE;
        }
        else {
          // Check if there are data to be inserted.
          if ( !isset($data['values']) ) {
            $context['results']['errors'][] = t('Values to be imported not found, check the YAML file.') . $file;
            $context['sandbox']['progress']++;
            $valid_entity_type = FALSE;
          }
        }
      }
    }

    //Process data 
    if ( $valid_entity_type ) {
      // Initialize array of elements to save.
      $entity_elements = [];

      // Get Entity Fields.
      $fields = array_filter(
        $entityFieldManager->getFieldDefinitions($entity_type, $entity_bundle), function ($field_definition) {
          return $field_definition;
        }
      );
      foreach ($fields as $fieldID => $field) {
        $entity_elements[$field->getName()] = $field->getName();
      }

      // Get Entity Properties - to know the id and bundle fields.
      $properties = $entityTypeManager->getDefinitions()[$entity_type]->getKeys();
      // Remove property ID as we are gonna use UUID to avoid conflicts.
      unset($entity_elements[$properties['id']]);
      $entity_elements = array_filter($entity_elements);

      // Get arrays of content to be inserted.
      $data_value = $data['values'][0];

      // Remove vid to avoid conflicts w/revisions.
      unset($data_value['vid']);

      // Gather Content to import
      $record = [];
      // Content
      $data_value[$properties['bundle']] = $entity_bundle;
      foreach ($entity_elements as $elementID => $element) {
        // Set value for entity fields -  from import or default.
        if ( isset($data_value[$element]) ) {
          // TODO --- Validate type of field value.  -- DataType.
          // TODO --- Is required ???

          $record['values'][$element] = $data_value[$element];
          // If it is entity reference get the id from the uuid
          $element_type = $fields[$element]->getType();
          if ( $element_type == "entity_reference" ||
            $element_type == "image" ||
            $element_type == "file"
          ) {
            // Get entity type
            $reference_type = $fields[$element]->getSettings()['target_type'];
            // Loop all the values
            if ( is_array($data_value[$element]) ) {
              foreach ($data_value[$element] as $er_key => $er_val) {
                $entity = $entityRepository->loadEntityByUuid($reference_type, $er_val['target_id']);
                unset($record['values'][$element][$er_key]['target_id']);
                if ( !empty($entity) ) {
                  $record['values'][$element][$er_key]['target_id'] = $entity->id();
                }
              }
            }
          }
        }
      }
      //$record['values']['path']['pathauto'] = TRUE;

      // Translations
      if ( isset($data_value['translations']) && is_array($data_value['translations']) ) {
        foreach ($data_value['translations'] as $translations) {
          $langcode = $translations['langcode'][0]['value'];
          // TODO --- verify if langcode is available in the site
          $translations[$properties['bundle']] = $entity_bundle;
          foreach ($entity_elements as $elementID => $element) {
            if ( isset($translations[$element]) ) {
              // Only translatable elements for translations
              if ( $fields[$elementID]->isTranslatable() == TRUE ) {
                $record['translations'][$langcode][$element] = $translations[$element];
                // If it is entity reference get the id from the uuid
                $element_type = $fields[$element]->getType();
                if ( $element_type == "entity_reference" ||
                  $element_type == "image" ||
                  $element_type == "file"
                ) {
                  // Get entity type
                  $reference_type = $fields[$element]->getSettings()['target_type'];
                  // Loop all the values
                  if ( is_array($translations[$element]) ) {
                    foreach ($translations[$element] as $er_key => $er_val) {
                      $entity = $entityRepository->loadEntityByUuid($reference_type, $er_val['target_id']);
                      unset($record['translations'][$langcode][$element][$er_key]['target_id']);
                      if ( !empty($entity) ) {
                        $record['translations'][$langcode][$element][$er_key]['target_id'] = $entity->id();
                      }
                    }
                  }
                }
              } // TODO --- Warning about untranslatable fields set on translations.
            }
          }
          $record['translations'][$langcode]['path']['pathauto'] = TRUE;
        }
      }

      // Flag to detect if the entity exist or is a new one.
      $new_entity = TRUE;

      // Get the id of the the parent uuid.
      if ( isset($record['values']['parent']) ) {
        // Load the entity from the UUID
        $parent_uuid = $record['values']['parent'];
        $entity = $entityRepository->loadEntityByUuid($entity_type, $parent_uuid);
        // Set parent only if the parent UUID exist.
        unset($record['values']['parent']);
        if ( !empty($entity) ) {
          // Get the  parent entity id and exchange it for the uuid.
          $record['values']['parent'] = $entity->id();
        }
      }

      // Check the UUID of the entity being imported.
      if ( isset($record['values']['uuid'][0]['value']) ) {
        // Load the entity from the UUID
        $uuid = $record['values']['uuid'][0]['value'];
        $entity = $entityRepository->loadEntityByUuid($entity_type, $uuid);
        // If the entity exist then update it.
        if ( !empty($entity) ) {
          // Set the new entity flag as false.
          $new_entity = FALSE;
          foreach ($entity_elements as $elementID => $element) {
            if ( isset($record['values'][$elementID]) ) {
              $entity->set($elementID, $record['values'][$elementID]);
            }
            else {
              // Get default value.
              $default_value = $fields[$elementID]->getDefaultValue($entity);
              $entity->set($elementID, $default_value);
            }
          }
          $entity->Save();
        }
      }
      //  Entity is new, add it.
      if ( $new_entity ) {
        $entity = $entityTypeManager->getStorage($entity_type)->create($record['values']);
        $entity->Save();
      }

      // Include Translations
      $lang_default = $entity->langcode->value;
      // Remove translations if they are in the import data the they would be inserted.
      foreach ($entity->getTranslationLanguages() as $langcode => $language) {
        // Verify that it is not the default langcode.
        if ( $langcode != $lang_default ) {
          $entity->removeTranslation($langcode);
        }
      }
      if ( isset($record['translations']) && is_array($record['translations']) ) {
        // Commented because automatically pathauto has been set.
        // \Drupal::service('path.alias_storage')->save("/taxonomy/term/" . $term->id(), "/".$entity_bundle."/".$term->id(), $lancode_original);
        foreach ($record['translations'] as $langcode => $translation) {
          // Add Translation if it is not the default language
          if ( $langcode != $lang_default ) {
            $term_translation = $entity->addTranslation($langcode);
            foreach ($translation as $itemID => $item) {
              $term_translation->$itemID->setValue($item);
            }
            $term_translation->save();
            // Commented because automatically pathauto has been set.
            // \Drupal::service('path.alias_storage')->save("/taxonomy/term/" . $term->id(), "/".$entity_bundle."/".$langcode.$term->id(), $langcode);
          }
        }
      }

      // Batch Context status.
      $context['results'][] = $entity->uuid();
      $context['message'] = 'UUID - ' . $entity->uuid();
      $context['sandbox']['progress']++;
    }
    if ( $context['sandbox']['progress'] != $context['sandbox']['max'] ) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }
}


/**
 * Finish batch.
 *
 * Provide information about the Content Batch results.
 */
function finishContentBatch($success, $results, $operations) {
  if ( $success ) {
    if ( !empty($results['errors']) ) {
      drupal_set_message(\Drupal::translation()
        ->translate('The content was imported with errors.'), 'warning');
      foreach ($results['errors'] as $error) {
        drupal_set_message($error, 'error');
        \Drupal::logger('content_sync')->error($error);
      }
    }
    else {
      drupal_set_message(\Drupal::translation()->translate('The content was imported successfully.'));
    }
    unset($results['errors']);
    foreach ($results as $result) {
      drupal_set_message(t('Processed UUID @title.', array('@title' => $result)));
    }
    //$message = \Drupal::translation()->formatPlural(count($results), 'One post processed.', '@count posts processed.');
  }
  else {
    $message = t('Finished with an error.');
    drupal_set_message($message);
  }
  // Providing data for the redirected page is done through $_SESSION.
}


/*
 *  Process Export Content Archive
 */
function processContentExportFiles($files, &$context) {
  //print_r($files);
  //print_r(count($files));

  //Initialize ArchiverTar
  $archiver = new ArchiveTar(file_directory_temp() . '/content.tar.gz', 'gz');

  //Initialize Batch
  if ( empty($context['sandbox']) ) {
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['current_number'] = 0;
    $context['sandbox']['max'] = count($files);
  }
  // Get submitted values
  $entity_type = $files[$context['sandbox']['progress']]['entity_type'];
  $entity_bundle = $files[$context['sandbox']['progress']]['entity_bundle'];
  $entity_id = $files[$context['sandbox']['progress']]['entity_id'];

  //$entity_type = $files['entity_type'];
  //$entity_bundle = $files['entity_bundle'];
  //$entity_id = $files['entity_id'];

  //print_r($files);
  //exit;


  //Validate that it is a Content Entity
  $entityTypeManager = \Drupal::entityTypeManager();
  $instances = $entityTypeManager->getDefinitions();
  if ( !(isset($instances[$entity_type]) && $instances[$entity_type] instanceof ContentEntityType) ) {
    $context['results']['errors'][] = t('Entity type does not exist or it is not a content instance.') . $entity_type;
  }
  else {
    // Generate the YAML file.
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityFieldManager = \Drupal::service('entity_field.manager');

    // Get Entity Fields.
    $fields = array_filter(
      $entityFieldManager->getFieldDefinitions($entity_type, $entity_bundle), function ($field_definition) {
        return $field_definition;
      }
    );

    // Initialize array of elements to export.
    $entity_elements = [];
    foreach ($fields as $fieldID => $field) {
      $entity_elements[$field->getName()] = $field->getName();
    }

    // Get Entity Properties - to know the id and bundle fields.
    $properties = $entityTypeManager->getDefinitions()[$entity_type]->getKeys();

    // Get data to fill the yaml.
    $entity_data = $entityTypeManager->getStorage($entity_type)->load($entity_id);
    $entity = [];
    $entity['entity_type'] = $entity_type;
    $entity['bundle'] = $entity_bundle;

    //Include Site UUID
    $site_config = \Drupal::config('system.site');
    $site_uuid_source = $site_config->get('uuid');
    $entity['site_uuid'] = $site_uuid_source;

    // Remove property ID as we are gonna use UUID to avoid conflicts.
    unset($entity_elements[$properties['id']]);
    // Remove bundle as it is defined already
    unset($entity_elements[$properties['bundle']]);
    // Remove vid to avoid conflicts w/versions
    unset($entity_elements['vid']);
    // Filter array
    $entity_elements = array_filter($entity_elements);

    //Get entity values
    foreach ($entity_elements as $elementID => $element) {
      //Include parent UUID if it exist
      if ($element == 'parent') {
        $parent = $entityTypeManager->getStorage($entity_type)->loadParents($entity_id);
        $parent = reset($parent);
        if ( !empty($parent) ) {
          $entity['values'][0][$element] = $parent->uuid();
        }
      }else {
        $entity['values'][0][$element] = $entity_data->get($element)
          ->getValue();
      }
      //Check if it is an entity reference and use UUID instead of target id.
      $element_type = $entity_data->get($element)
        ->getFieldDefinition()
        ->getType();
      if ( $element_type == "entity_reference" ||
        $element_type == "image" ||
        $element_type == "file"
      ) {
        if ( $entity_data->get($element)->entity ) {
          $reference_type = $entity_data->get($element)->entity->getEntityType()
            ->id();
          //Loop all the values
          foreach ($entity_data->get($element)
                     ->getValue() as $er_key => $er_val) {
            $entity['values'][0][$element][$er_key]['target_id'] = $entityTypeManager->getStorage($reference_type)
              ->load($er_val['target_id'])
              ->uuid();
          }
        }
      }
    }
    // Exception to get the path as it can not be retrieved as regular value.
    // Not set for image because gives an error.
    //$current_path = \Drupal::service('path.current')->getPath();
    if($entity_type != "file") {
      $entity['values'][0]['path'] = "/" . $entity_data->toUrl()->getInternalPath();
    }

    // Include Translations
    $lang_default = $entity['values'][0]['langcode'][0]['value'];
    foreach ($entity_data->getTranslationLanguages() as $langcode => $language) {
      $c = 0;
      if ( $entity_data->hasTranslation($langcode) ) {
        $entity_data_translation = $entity_data->getTranslation($langcode);
        // Verify that it is not the default langcode.
        if ( $langcode != $lang_default ) {
          foreach ($entity_elements as $elementID => $element) {
            // Only translatable elements for translations
            if ( $fields[$elementID]->isTranslatable() == TRUE ) {
              $entity['values'][0]['translations'][$c][$element] = $entity_data_translation->get($element)
                ->getValue();

              //Check if it is an entity reference and use UUID instead of target id.
              $element_type = $entity_data_translation->get($element)
                ->getFieldDefinition()
                ->getType();
              if ( $element_type == "entity_reference" ||
                $element_type == "image" ||
                $element_type == "file"
              ) {
                if ( $entity_data_translation->get($element)->entity ) {
                  $reference_type = $entity_data_translation->get($element)->entity->getEntityType()
                    ->id();
                  //Loop all the values
                  foreach ($entity_data_translation->get($element)
                             ->getValue() as $er_key => $er_val) {
                    $entity['values'][0]['translations'][$c][$element][$er_key]['target_id'] = $entityTypeManager->getStorage($reference_type)
                      ->load($er_val['target_id'])
                      ->uuid();
                  }
                }
              }

            }
          }
          //$entity['translations'][$c]['path'] = $entity_data_translation->toUrl()->getInternalPath();
          $c++;
        }
      }
    }
    // Create the name
    $name = $entity_type . "." . $entity_bundle . "." . $entity['values'][0]['uuid'][0]['value'];
    // Create the file.
    $archiver->addString("$name.yml", Yaml::encode($entity));
    $context['message'] = $name;
  }

  $context['results'][] = $name;
  $context['sandbox']['progress']++;
  if ( $context['sandbox']['progress'] != $context['sandbox']['max'] ) {
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }
}

/**
 * Finish batch.
 *
 * Provide information about the Content Batch results.
 */
function finishContentExportBatch($success, $results, $operations) {
  if ( $success ) {
    if ( !empty($results['errors']) ) {
      drupal_set_message(\Drupal::translation()->translate('The content was exported with errors.'), 'warning');
      foreach ($results['errors'] as $error) {
        drupal_set_message($error, 'error');
        \Drupal::logger('content_sync')->error($error);
      }
    }
    else {
      drupal_set_message(\Drupal::translation()->translate('The content was exported successfully. <a href=":export-download">Download tar file</a>', [':export-download' => \Drupal::url('content.export_download')]));
    }
    unset($results['errors']);
    foreach ($results as $result) {
      drupal_set_message(t('Processed UUID @title.', array('@title' => $result)));
    }
    //$message = \Drupal::translation()->formatPlural(count($results), 'One post processed.', '@count posts processed.');
  }
  else {
    $message = t('Finished with an error.');
    drupal_set_message($message);
  }
}
