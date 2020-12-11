<?php

namespace Drupal\content_sync\Form;

use Drupal\content_sync\ContentSyncManagerInterface;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the content export form.
 */
class ContentExportForm extends FormBase {

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
   * ContentExportForm constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ContentExporterInterface $content_exporter, ContentSyncManagerInterface $content_sync_manager, FileSystemInterface $file_system) {
    $this->entityTypeManager = $entity_type_manager;
    $this->contentExporter = $content_exporter;
    $this->contentSyncManager = $content_sync_manager;
    $this->fileSystem = $file_system;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('content_sync.exporter'),
      $container->get('content_sync.manager'),
      $container->get('file_system')
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
    $this->fileSystem->delete($this->getTempFile());

    //Set batch operations by entity type/bundle
    $entities_list = [];
    $entity_type_definitions = $this->entityTypeManager->getDefinitions();
    foreach ($entity_type_definitions as $entity_type => $definition) {
      $reflection = new \ReflectionClass($definition->getClass());
      if ($reflection->implementsInterface(ContentEntityInterface::class)) {
        $entities = $this->entityTypeManager->getStorage($entity_type)
                                            ->getQuery()
                                            ->execute();
        foreach ($entities as $entity_id) {
          $entities_list[] = [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
          ];
        }
      }
    }
    if (!empty($entities_list)) {
      $serializer_context['export_type'] = 'tar';
      $serializer_context['include_files'] = 'folder';
      $batch = $this->generateExportBatch($entities_list, $serializer_context);
      batch_set($batch);
    }
  }

  public function snapshot() {
    //Set batch operations by entity type/bundle
    $entities_list = [];
    $entity_type_definitions = $this->entityTypeManager->getDefinitions();
    foreach ($entity_type_definitions as $entity_type => $definition) {
      $reflection = new \ReflectionClass($definition->getClass());
      if ($reflection->implementsInterface(ContentEntityInterface::class)) {
        $entities = $this->entityTypeManager->getStorage($entity_type)
                                            ->getQuery()
                                            ->execute();
        foreach ($entities as $entity_id) {
          $entities_list[] = [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
          ];
        }
      }
    }
    if (!empty($entities_list)) {
      $serializer_context['export_type'] = 'snapshot';
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
