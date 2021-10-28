<?php

namespace Drupal\archivesspace;

use Psr\Log\LoggerInterface;

/**
 * Builds batch definitions for removing objects deleted from ArchivesSpace.
 */
class Purger {
  /**
   * ArchivesSpaceSession that will allow us to issue API requests.
   *
   * @var ArchivesSpaceSession
   */
  protected $archivesspaceSession;

  /**
   * ArchivesSpace Utils.
   *
   * @var ArchivesSpaceUtils
   */
  protected $utils;

  /**
   * The first page of results to being processing.
   *
   * @var int
   */
  protected $firstPage = 1;

  /**
   * The maximum number of pages of 50 items we want to update with this batch.
   *
   * @var int
   */
  protected $maxPages = 0;

  /**
   * Constructor.
   */
  public function __construct(LoggerInterface $logger, ArchivesSpaceUtils $utils) {
    $this->logger = $logger;
    $this->utils = $utils;
    if ($last_page = \Drupal::state()->get('archivesspace.delete_feed_page')) {
      $this->setFirstPage((int) $last_page);
    }
    $this->archivesspaceSession = new ArchivesSpaceSession();
  }

  /**
   * ArchivesSpaceSession setter.
   */
  public function setArchivesSpaceSession(ArchivesSpaceSession $archivesspace_session) {
    $this->archivesspaceSession = $archivesspace_session;
  }

  /**
   * Max pages setter.
   *
   * @var int
   */
  public function setMaxPages(int $max_pages) {
    $this->maxPages = $max_pages;
  }

  /**
   * First page setter.
   *
   * @var int
   */
  public function setFirstPage(int $page) {
    if ($page > 0) {
      $this->firstPage = $page;
    }
    else {
      $this->firstPage = 1;
    }
  }

  /**
   * Get first page number.
   *
   * @return int
   *   first page.
   */
  public function getFirstPage() {
    return $this->firstPage;
  }

  /**
   * Builds the delete-feed request parameters.
   */
  public function buildRequestParameters() {
    $parameters = [
      'page' => '1',
      'page_size' => 50,
    ];
    return $parameters;
  }

  /**
   * Builds a batch definition.
   */
  public function buildBatchDefinition() {
    $parameters = $this->buildRequestParameters();
    $results = $this->archivesspaceSession->request('GET', '/delete-feed', $parameters);
    $last_page = (!empty($this->maxPages && (($this->firstPage - 1 + $this->maxPages) < $results['last_page']))) ? ($this->firstPage - 1 + $this->maxPages) : $results['last_page'];
    $this->logger->notice(t("Processing delete-feed pages @first through @last out of @available possible pages.",
    [
      '@first' => $this->firstPage,
      '@last' => $last_page,
      '@available' => $results['last_page'],
    ]));
    // Create an operation for each page, upto the max page count.
    $operations = [];
    for ($batchId = $this->firstPage; $batchId <= $last_page; $batchId++) {
      $batch_parameters = $parameters;
      $batch_parameters['page'] = $batchId;
      $operations[] = [
        [$this, 'batchPurge'],
        [
          $batchId,
          $this->archivesspaceSession,
          $batch_parameters,
        ],
      ];
    }
    return([
      'title' => t('Processing @num pages of deletions.', ['@num' => $max_pages]),
      'operations' => $operations,
      'finished' => [$this, 'purgeFinished'],
    ]);
  }

  /**
   * Batch process callback.
   *
   * @param int $id
   *   Batch ID.
   * @param \Drupal\archivesspace\ArchivesSpaceSession $session
   *   Details of the operation.
   * @param array $parameters
   *   The operations' parameters.
   * @param object $context
   *   Context for operations.
   */
  public function batchPurge($id, ArchivesSpaceSession $session, array $parameters, &$context) {
    // Get page number and query from operation details.
    // Issue query.
    $results = $session->request('GET', '/delete-feed', $parameters);
    $context['message'] = t("Processing page @page of deletions.", [
      '@page' => $results['this_page'],
    ]);
    // Group the items into migrations so we can retrieve the IDs for
    // each migration all at once.
    $migrations = [];
    foreach ($results['results'] as $uri) {
      if ($migration_id = $this->utils->getUriMigration($uri)) {
        $migrations[$migration_id][] = $uri;
      }
    }
    foreach ($migrations as $migration_id => $uris) {
      $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);
      if (!$migration) {
        $context['message'] = t("Could not find a migration with the ID '@id'!", ['@id' => $migration_id]);
        continue;
      }
      $id_map = $migration->getIdMap();
      $destination_configuration = $migration->getDestinationConfiguration();
      $type = substr($destination_configuration['plugin'], strpos($destination_configuration['plugin'], ':') + 1);
      $storage = \Drupal::entityTypeManager()->getStorage($type);
      $destination_ids = [];
      foreach ($uris as $uri) {
        $destination_id = $id_map->lookupDestinationIds([$uri])[0][0];
        if (empty($destination_id)) {
          // Never migrated.
          continue;
        }
        $condemed = $storage->load($id_map->lookupDestinationIds([$uri])[0][0]);
        if (empty($condemed)) {
          // Already deleted it.
          continue;
        }
        $context['message'] = t("Purging @type @id: '@label'", [
          '@type' => $type,
          '@id' => $condemed->id(),
          '@label' => $condemed->label(),
        ]);
        $condemed->delete();
        $context['results']['deletions'][$type] += 1;
      }
    }

    $context['results']['page'] = $id;
    $high_water_mark = \Drupal::state()->get('archivesspace.delete_feed_page');
    if ($id > $high_water_mark) {
      \Drupal::state()->set('archivesspace.delete_feed_page', $id);
    }
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Success of the operation.
   * @param array $results
   *   Array of results for post processing.
   * @param array $operations
   *   Array of operations.
   */
  public function purgeFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    if ($success) {
      if (!empty($results['page'])) {
        $messenger->addMessage(t("Purged records found through page @page.", ['@page' => $results['page']]));
      }
      else {
        $messenger->addWarning(t("Unable to process any pages of deletions."));
      }

      // Report out deletions.
      if (!empty($results['deletions'])) {
        foreach ($results['deletions'] as $type => $count) {
          $messenger->addMessage(\Drupal::translation()->formatPlural($count, "Removed @count @type.", "Removed @count @types.", ['@type' => $type]));
        }
      }
      else {
        $messenger->addWarning(t("No deletions appeared to have occurred."));
      }
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      $messenger->addWarning(
        t('An error occurred while processing @operation with arguments : @args',
          [
            '@operation' => 'Deletions Page',
            '@args' => print_r($error_operation[1][2], TRUE),
          ]
        )
          );
    }
  }

}
