<?php

namespace Drupal\archivesspace\Commands;

use Drupal\archivesspace\ResourceDescriptionBatchWorker;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

/**
 * A Drush commandfile.
 */
class ArchivesSpaceResourceDescriptionQueueCommand extends DrushCommands {

  /**
   * Batch Update Builder.
   *
   * @var \Drupal\archivesspace\ResourceDescriptionBatchWorker
   */
  protected $batchUpdateBuilder;

  /**
   * Constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for reporting out.
   * @param \Drupal\archivesspace\ResourceDescriptionBatchWorker $bub
   *   The class responsible for building batch updates for processing.
   */
  public function __construct(LoggerInterface $logger, ResourceDescriptionBatchWorker $bub) {
    $this->logger = $logger;
    $this->batchUpdateBuilder = $bub;
  }

  /**
   * Resource description file updates.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @option max-items Maximum number of update requests to process.
   * @option items-per-batch Number of update requests to process per batch group.
   *
   * @command archivesspace:resource-description-queue
   * @aliases asrdq, as-rdq
   *
   * @usage archivesspace:resource-description-queue
   *   Process pending resource description file updates.
   * @usage archivesspace:update --max-items=[items]
   *   Limit run to a certain number of items.
   * @usage archivesspace:update --items-per-batch=[items]
   *   Set how many items to run per group of items.
   */
  public function runResourceDescriptionQueue(array $options = [
    'max-items' => self::REQ,
    'items-per-batch' => self::REQ,
  ]) {

    // Let Batch Update Builder do the sanity checking.
    if (!empty($options['items-per-batch'])) {
      $this->batchUpdateBuilder->setFilesPerBatch($options['items-per-batch']);
    }
    if (!empty($options['max-items'])) {
      $this->batchUpdateBuilder->setMaxItems($options['max-items']);
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
      $this->logger()->notice("Done.");
    }
  }

}
