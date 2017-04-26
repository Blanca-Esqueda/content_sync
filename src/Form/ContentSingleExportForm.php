<?php

namespace Drupal\content_sync\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\ContentEntityType;
use Symfony\Component\DependencyInjection\ContainerInterface;



/**
 * Provides a form for exporting a single content file.
 */
class ContentSingleExportForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity bundle manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfo
   */
  protected $entityBundleManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * Constructs a new ContentSingleImportForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   entity type manager
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfo $entity_bundle_manager
   *   entity bundle manager
   *
   * @param \Drupal\Core\Entity\EntityFieldManager $entity_field_manager
   *   entity field manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfo $entity_bundle_manager, EntityFieldManager $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityBundleManager = $entity_bundle_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_single_export_form';
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
      '#attributes' => array('onchange' => 'this.form.content_name.value = null; if(this.form.content_entity){ this.form.content_entity.value = null; } this.form.export.value = null; this.form.submit();'),
    ];

    $default_type = $form_state->getValue('content_type', $content_type);
    $default_name = $form_state->getValue('content_name', $content_name);
    $form['content_name'] = [
      '#title' => $this->t('Content name'),
      '#type' => 'select',
      '#options' => $this->findContent($default_type),
      '#default_value' => $content_name,
      '#attributes' => array('onchange' => 'if(this.form.content_entity){ this.form.content_entity.value = null; } this.form.export.value = null; this.form.submit();'),
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
        '#ajax' => [
          'callback' => '::updateExport',
          'wrapper' => 'edit-export-wrapper',
            'event' => 'autocompleteclose',
        ],
      ];
      // Remove target bundles for user & file because it is not supported.
      if($default_type == 'user' || $default_type == 'file'){
        unset($form['content_entity']['#selection_settings']);
      }
    }

    $form['export'] = [
      '#title' => $this->t('Here is your configuration:'),
      '#type' => 'textarea',
      '#rows' => 24,
      '#prefix' => '<div id="edit-export-wrapper">',
      '#suffix' => '</div>',
    ];

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
   * Handles switching the export textarea.
   */
  public function updateExport($form, FormStateInterface $form_state) {
    // Get submitted values
    $entity_type = $form_state->getValue('content_type');
    $entity_bundle = $form_state->getValue('content_name');
    $entity_id = $form_state->getValue('content_entity');

    //Validate that it is a Content Entity
    $instances = $this->entityTypeManager->getDefinitions();
    if(!( isset($instances[$entity_type]) && $instances[$entity_type] instanceof ContentEntityType ) ){
      $output = t('Entity type does not exist or it is not a content instance.');
    }else{
      // Get Entity Fields.
      $fields = array_filter(
        $this->entityFieldManager->getFieldDefinitions($entity_type, $entity_bundle), function ($field_definition) {
          return $field_definition;
        }
      );

      // Initialize array of elements to export.
      $entity_elements = [];
      foreach($fields as $fieldID => $field){
        $entity_elements[$field->getName()] = $field->getName();
      }

      // Get Entity Properties - to know the id and bundle fields.
      $properties = $this->entityTypeManager->getDefinitions()[$entity_type]->getKeys();
      
      // Get data to fill the yaml.
      $entity_data = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
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
      foreach ($entity_elements as $elementID => $element){
        //Include parent UUID if it exist
        if ($element == 'parent') {
          $parent = $this->entityTypeManager->getStorage($entity_type)->loadParents($entity_id);
          $parent = reset($parent);
          if ( !empty($parent) ) {
            $entity['values'][0][$element] = $parent->uuid();
          }
        }else {
          $entity['values'][0][$element] = $entity_data->get($element)
            ->getValue();
        }
        //Check if it is an entity reference and use UUID instead of target id.
        $element_type = $entity_data->get($element)->getFieldDefinition()->getType();
        if ( $element_type == "entity_reference" ||
          $element_type == "image" ||
          $element_type == "file" ) {
          if ( $entity_data->get($element)->entity ) {
            $reference_type = $entity_data->get($element)->entity->getEntityType()->id();
            //Loop all the values
            foreach ($entity_data->get($element)->getValue() as $er_key => $er_val) {
              $entity['values'][0][$element][$er_key]['target_id'] = $this->entityTypeManager->getStorage($reference_type)->load($er_val['target_id'])->uuid();
            }
          }
        }
      }
      // TODO - Check the path value.
      // Exception to get the path as it can not be retrieved as regular value.
      // Not set for image because gives an error.
      //$current_path = \Drupal::service('path.current')->getPath();
      if($entity_type != "file") {
        $entity['values'][0]['path'] = "/" . $entity_data->toUrl()->getInternalPath();
      }

      // Include Translations
      $lang_default = $entity['values'][0]['langcode'][0]['value'];
      // Remove translations if they are in the import data the they would be inserted.
      foreach ($entity_data->getTranslationLanguages() as $langcode => $language) {
        $c = 0;
        if($entity_data->hasTranslation($langcode)) {
          $entity_data_translation = $entity_data->getTranslation($langcode);
          // Verify that it is not the default langcode.
          if ($langcode != $lang_default){
            foreach ($entity_elements as $elementID => $element){
              // Only translatable elements for translations
              if ($fields[$elementID]->isTranslatable() == TRUE){
                $entity['values'][0]['translations'][$c][$element] =  $entity_data_translation->get($element)->getValue();
                //Check if it is an entity reference and use UUID instead of target id.
                $element_type = $entity_data_translation->get($element)
                  ->getFieldDefinition()
                  ->getType();
                if ( $element_type == "entity_reference" ||
                  $element_type == "image" ||
                  $element_type == "file" ) {
                  if ( $entity_data_translation->get($element)->entity ) {
                    $reference_type = $entity_data_translation->get($element)->entity->getEntityType()->id();
                    //Loop all the values
                    foreach ($entity_data_translation->get($element)->getValue() as $er_key => $er_val) {
                      $entity['values'][0]['translations'][$c][$element][$er_key]['target_id'] = $this->entityTypeManager->getStorage($reference_type)->load($er_val['target_id'])->uuid();
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
      $output = Yaml::encode($entity);
      $name = $entity_type . "." . $entity_bundle . "." . $entity['values']['uuid'][0]['value'];
    }

    $form['export']['#value'] = $output;
    $form['export']['#description'] = $this->t('Filename: %name', ['%name' => $name . '.yml']);
    return $form['export'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Nothing to submit.
  }

}
