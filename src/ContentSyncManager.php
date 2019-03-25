<?php

namespace Drupal\content_sync;

use Drupal\content_sync\DependencyResolver\ImportQueueResolver;
use Drupal\content_sync\DependencyResolver\ExportQueueResolver;
use Drupal\content_sync\Exporter\ContentExporterInterface;
use Drupal\content_sync\Importer\ContentImporterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Diff\Diff;
use Symfony\Component\Serializer\Serializer;


class ContentSyncManager implements ContentSyncManagerInterface {

  const DELIMITER = '.';
  
  /**
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\content_sync\Exporter\ContentExporterInterface
   */
  protected $contentExporter;

  /**
   * @var \Drupal\content_sync\Importer\ContentImporterInterface
   */
  protected $contentImporter;

  /**
   * ContentSyncManager constructor.
   */
  public function __construct(Serializer $serializer, EntityTypeManagerInterface $entity_type_manager, ContentExporterInterface $content_exporter, ContentImporterInterface $content_importer) {
    $this->serializer = $serializer;
    $this->entityTypeManager = $entity_type_manager;
    $this->contentExporter = $content_exporter;
    $this->contentImporter = $content_importer;
  }

  /**
   * @return \Drupal\content_sync\Exporter\ContentExporterInterface
   */
  public function getContentExporter() {
    return $this->contentExporter;
  }

  /**
   * @return \Drupal\content_sync\Importer\ContentImporterInterface
   */
  public function getContentImporter() {
    return $this->contentImporter;
  }


  /**
   * @param $file_names
   * @param $directory
   *
   * @return array
   */
  public function generateImportQueue($file_names, $directory) {
    $queue = [];
    foreach ($file_names as $file) {
      $ids = explode('.', $file);
      list($entity_type_id, $bundle, $uuid) = $ids;
      $file_path = $directory . "/" . $entity_type_id . "/" . $bundle . "/" . $file . ".yml";
      if (!file_exists($file_path) || !$this->isValidFilename($file)) {
        continue;
      }
      $content = file_get_contents($file_path);
      $format = $this->contentImporter->getFormat();
      $decoded_entity = $this->serializer->decode($content, $format);
      $decoded_entities[$file] = $decoded_entity;
    }
    if (!empty($decoded_entities)) {
      $resolver = new ImportQueueResolver();
      $queue = $resolver->resolve($decoded_entities);
    }
    return $queue;
  }

  /**
   * @param $file_names
   * @param $directory
   *
   * @return array
   */
  public function generateExportQueue($decoded_entities) {
    $queue = [];
    if (!empty($decoded_entities)) {
      $resolver = new ExportQueueResolver();
      $queue = $resolver->resolve($decoded_entities);
    }
    return $queue;
  }

  /**
   * @return \Symfony\Component\Serializer\Serializer
   */
  public function getSerializer() {
    return $this->serializer;
  }

  /**
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * Checks filename structure
   *
   * @param $filename
   *
   * @return bool
   */
  protected function isValidFilename($filename) {
    $parts = explode(static::DELIMITER, $filename);
    return count($parts) === 3;
  }

  /**
   * {@inheritdoc}
   */
  public function diff(ContentStorageInterface $source_storage, ContentStorageInterface $target_storage, $source_name, $target_name = NULL, $collection = ContentStorageInterface::DEFAULT_COLLECTION) {
    if ($collection != ContentStorageInterface::DEFAULT_COLLECTION) {
      $source_storage = $source_storage->createCollection($collection);
      $target_storage = $target_storage->createCollection($collection);
    }
    if (!isset($target_name)) {
      $target_name = $source_name;
    }
    // The output should show content object differences formatted as YAML.
    // But the content is not necessarily stored in files. Therefore, they
    // need to be read and parsed, and lastly, dumped into YAML strings.
    $source_data = explode("\n", Yaml::encode($source_storage->read($source_name)));
    $target_data = explode("\n", Yaml::encode($target_storage->read($target_name)));

    // Check for new or removed files.
    if ($source_data === ['false']) {
      // Added file.
      // Cast the result of t() to a string, as the diff engine doesn't know
      // about objects.
      $source_data = [(string) $this->t('File added')];
    }
    if ($target_data === ['false']) {
      // Deleted file.
      // Cast the result of t() to a string, as the diff engine doesn't know
      // about objects.
      $target_data = [(string) $this->t('File removed')];
    }

    return new Diff($source_data, $target_data);
  }

}
