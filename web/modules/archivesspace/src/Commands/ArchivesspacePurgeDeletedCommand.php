<?php

namespace Drupal\archivesspace\Commands;

use Drupal\archivesspace\Purger;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

/**
 * A Drush commandfile for purging items deleted from ArchivesSpace.
 */
class ArchivesspacePurgeDeletedCommand extends DrushCommands {

  /**
   * Purger.
   *
   * @var \Drupal\archivesspace\Purger
   */
  protected $purger;

  /**
   * Constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for reporting out.
   * @param \Drupal\archivesspace\Purger $purger
   *   The class responsible for purging deleted items.
   */
  public function __construct(LoggerInterface $logger, Purger $purger) {
    $this->logger = $logger;
    $this->purger = $purger;
  }

  /**
   * Remove previously migrated ArchivesSpace items since deleted.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @option max-pages Maximum number of pages (50 items/page) to process.
   * @option first-page The first page of deletions to process.
   *
   * @command archivesspace:purge
   * @aliases as-delete
   * @aliases asd
   */
  public function purge(array $options = [
    'max-pages' => self::REQ,
    'first-page' => self::REQ,
  ]) {
    if (!empty($options['first-page'])) {
      $this->purger->setFirstPage($options['first-page']);
    }
    if (!empty($options['max-pages'])) {
      $this->purger->setMaxPages($options['max-pages']);
    }

    // Build and run the batch.
    if ($batch = $this->purger->buildBatchDefinition()) {
      batch_set($batch);
      drush_backend_batch_process();
      $this->logger()->notice("Done processing batch.");
    }
  }

}
