<?php

namespace Drupal\archivesspace\Plugin\migrate\process;

use Drupal\Component\Utility\UrlHelper;
use Drupal\migrate\MigrateSkipProcessException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Transforms an ArchivesSpace External Document object into a Link.
 *
 * We use this plugin so we can skip un-published external documents
 * without attempting to monkey a solution using the sub_process plugin.
 *
 * Currently only allows URIs using http and https; all others are skipped.
 *
 * @MigrateProcessPlugin(
 *   id = "external_document_link"
 * )
 */
class ExternalDocumentLink extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value) or
      !is_array($value) or
      empty($value['publish']) or
      !in_array(parse_url($value['location'])['scheme'], UrlHelper::getAllowedProtocols())) {
      throw new MigrateSkipProcessException();
    }
    return [
      'title' => $value['title'],
      'uri' => $value['location'],
    ];
  }

}
