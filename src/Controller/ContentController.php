<?php

namespace Drupal\content_sync\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\system\FileDownloadController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\Component\Utility\Unicode;

/**
 * Returns responses for content_yaml module routes.
 */
class ContentController implements ContainerInjectionInterface {

  /**
   * The file download controller.
   *
   * @var \Drupal\system\FileDownloadController
   */
  protected $fileDownloadController;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      new FileDownloadController()
    );
  }

  /**
   * Constructs a ContentController object.
   *
   * @param \Drupal\system\FileDownloadController $file_download_controller
   *   The file download controller.
   */
  public function __construct(FileDownloadController $file_download_controller) {
    $this->fileDownloadController = $file_download_controller;
  }

  /**
   * Downloads a tarball of the site content.
   */
  public function downloadExport() {
    // NOTE:  Getting - You are not authorized to access this page.
    //$request = new Request(['file' => 'content.tar.gz']);
    //return $this->fileDownloadController->download($request, 'temporary');
    $filename = 'content.tar.gz';
    $file_path = file_directory_temp() . '/' . $filename;
    if (file_exists($file_path) ) {
      unset($_SESSION['content_tar_download_file']);
      $mime = \Drupal::service('file.mime_type.guesser')->guess($file_path);
      $headers = array(
        'Content-Type' => $mime . '; name="' . Unicode::mimeHeaderEncode(basename($file_path)) . '"',
        'Content-Length' => filesize($file_path),
        'Content-Disposition' => 'attachment; filename="' . Unicode::mimeHeaderEncode($filename) . '"',
        'Cache-Control' => 'private',
      ); 
      return new BinaryFileResponse($file_path, 200, $headers);
    }
    return -1;
  }
}
