<?php

namespace Drupal\archivesspace\Commands;

use Drupal\archivesspace\BatchUpdateBuilder;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

/**
 * A Drush commandfile.
 */
class ArchivesSpaceBatchUpdateCommand extends DrushCommands {

  /**
   * Batch Update Builder.
   *
   * @var \Drupal\archivesspace\BatchUpdateBuilder
   */
  protected $batchUpdateBuilder;

  /**
   * Constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for reporting out.
   * @param \Drupal\archivesspace\BatchUpdateBuilder $bub
   *   The class responsible for building batch updates for processing.
   */
  public function __construct(LoggerInterface $logger, BatchUpdateBuilder $bub) {
    $this->logger = $logger;
    $this->batchUpdateBuilder = $bub;
  }

  /**
   * Migrate Updates.
   *
   * @param string $type
   *   Type of archivesspace data to update (Optional).
   * @param array $options
   *   Additional options for the command.
   *
   * @option max-pages Maximum number of pages (10 items/page) to process.
   * @option update-time A timestamp to begin updates from
   *
   * @command archivesspace:update
   * @aliases asup, as-update
   *
   * @usage archivesspace:update
   *   Update everything.
   * @usage archivesspace:update [type]
   *   Update a specific type of archivesspace object (resource, subject, ect.).
   *   Using this option will prevent updating the stored update-time value.
   * @usage archivesspace:update type --max-pages=[pages]
   *   Limit updates to a certain number of pages.
   *   E.g. 2 pages = 20 items (10 items/page).
   * @usage archivesspace:update --update-time=[iso 8601 timestamp]
   *   Process items updated since the provided ISO 8601 timestamp.
   */
  public function updateArchivesSpace($type = '', array $options = [
    'max-pages' => self::REQ,
    'update-time' => self::REQ,
  ]) {

    // Let Batch Update Builder do the sanity checking.
    if (!empty($type)) {
      $this->batchUpdateBuilder->setType($type);
    }
    if (!empty($options['update-time'])) {
      $this->batchUpdateBuilder->setUpdatedSince($options['update-time']);
    }
    if (!empty($options['max-pages'])) {
      $this->batchUpdateBuilder->setMaxPages($options['max-pages']);
    }
    // @todo add command-line options to provide connection information.
    // That will necessitate instantiating it with
    // ArchivesSpaceSession::withConnectionInfo() and then setting it with
    // $this->batchUpdateBuilder->setArchivesSpaceSession().
    //
    // Build and run the batch.
    if ($batch = $this->batchUpdateBuilder->buildBatchDefinition()) {
      batch_set($batch);
      drush_backend_batch_process();
      $this->logger()->notice("Done processing batch.");
    }
  }

}
