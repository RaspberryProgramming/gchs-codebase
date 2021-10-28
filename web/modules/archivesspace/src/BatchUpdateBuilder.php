<?php

namespace Drupal\archivesspace;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Psr\Log\LoggerInterface;

/**
 * Builds batch objects for updates.
 */
class BatchUpdateBuilder {

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
   * Supported ArchivesSpace item types.
   *
   * @var array
   */
  protected $supportedTypes = [
    'resource',
    'archival_object',
    'agent_person',
    'agent_corporate_entity',
    'agent_family',
    'subject',
    'top_container',
    'repository',
    'classifications',
    'classification_term',
  ];

  /**
   * The maximum number of pages of 10 items we want to update with this batch.
   *
   * @var int
   */
  protected $maxPages = 0;

  /**
   * An ISO 8601 timestamp string updates should be more recent than.
   *
   * @var string
   */
  protected $startMtime = '1970-01-01T00:00:00Z';

  /**
   * The type of ArchivesSpace item to update. Empty for all types.
   *
   * @var string
   */
  protected $itemType = '';

  /**
   * Constructor to set defaults.
   */
  public function __construct(LoggerInterface $logger, ArchivesSpaceUtils $utils) {
    $this->logger = $logger;
    $this->utils = $utils;
    // Get the latest_user_mtime from state to use as an initial startMtime.
    if ($state_update_time = \Drupal::state()->get('archivesspace.latest_user_mtime')) {
      // We can *probably* trust the state value to be a valid ISO 8601
      // timestamp, but it won't hurt to be paranoid here..
      if ($timestamp = strtotime($state_update_time)) {
        $this->startMtime = date(DATE_ATOM, $timestamp);
      }
    }

    // Get a session with default settings in state.
    // Devs can use ArchivesSpace::withConnectionInfo and the
    // BatchUpdateBuilder->setArchivesSpaceSession if they want different
    // credentials.
    $this->archivesspaceSession = new ArchivesSpaceSession();
  }

  /**
   * ArchivesSpaceSession setter.
   */
  public function setArchivesSpaceSession(ArchivesSpaceSession $archivesspace_session) {
    $this->archivesspaceSession = $archivesspace_session;
  }

  /**
   * ArchivesSpaceSession getter.
   */
  public function getArchivesSpaceSession() {
    return $this->archivesspaceSession;
  }

  /**
   * Max pages setter.
   */
  public function setMaxPages(int $max_pages) {
    $this->maxPages = $max_pages;
  }

  /**
   * UpdatedSince setter.
   *
   * @throws Exception.
   */
  public function setUpdatedSince(string $updated_since) {
    // Parse the string to make sure it is a valid timestamp.
    if (!empty($updated_since) && $timestamp = strtotime($updated_since)) {
      $this->startMtime = date(DATE_ATOM, $timestamp);
    }
    else {
      $error = dt('Provided string "@time" is not recognized. Please provide a valid timestamp (ISO 8601 preferred). E.g. 2020-01-01T00:00:00Z.', ['@time' => $updated_since]);
      $this->logger->error($error);
      throw new \Exception($error);
    }
  }

  /**
   * Type setter.
   */
  public function setType(string $type_to_update) {
    if (!empty($type_to_update) && !in_array($type_to_update, $this->supportedTypes)) {
      $error = dt('ArchivesSpace type "@type" not available. Set blank to update all or use one of the following: @types.',
        [
          '@type' => $type_to_update,
          '@types' => implode(', ', $this->supportedTypes),
        ]
      );
      $this->logger->error($error);
      throw new \Exception($error);
    }
    else {
      $this->itemType = $type_to_update;
    }
  }

  /**
   * Builds the request parameters for the "updated since advanced query".
   */
  public function buildRequestParameters() {
    // Note: ArchivesSpace compares the user_mtime as greater than the
    // provided time *plus one day*.
    // See https://github.com/archivesspace/archivesspace/blob/v2.5.2/backend/app/model/advanced_query_string.rb#L47-L48.
    // So, to get everything updated as of a certain time,
    // take your datetime and subtract one day.
    $update_time = date(DATE_ATOM, strtotime('-1 day', strtotime($this->startMtime)));
    $parameters = [
      'page' => '1',
      'sort' => 'user_mtime asc',
      'aq' => json_encode([
        'jsonmodel_type' => 'advanced_query',
        'query' => [
          'jsonmodel_type' => 'boolean_query',
          'op' => 'AND',
          'subqueries' => [
            // Update Time query.
            [
              'jsonmodel_type' => 'boolean_query',
              'op' => 'OR',
              'subqueries' => [
                [
                  'field' => 'user_mtime',
                  'value' => $update_time,
                  'comparator' => 'greater_than',
                  'jsonmodel_type' => 'date_field_query',
                ],
                // We add system time to catch "Publish All" updates to
                // archival_objects.
                [
                  'field' => 'system_mtime',
                  'value' => $update_time,
                  'comparator' => 'greater_than',
                  'jsonmodel_type' => 'date_field_query',
                ],
              ],
            ],
            // Filter out PUI only results from the index.
            [
              'field' => 'types',
              'value' => 'pui_only',
              'jsonmodel_type' => 'field_query',
              'negated' => TRUE,
            ],
          ],
        ],
      ]),
    ];
    if (!empty($this->itemType)) {
      $parameters['type[]'] = $this->itemType;
    }
    return $parameters;
  }

  /**
   * Builds a batch definition.
   */
  public function buildBatchDefinition() {
    $parameters = $this->buildRequestParameters();
    $results = $this->archivesspaceSession->request('GET', '/search', $parameters);
    $this->logger->notice(t("Looking for updates since @time.", ['@time' => date(DATE_ATOM, strtotime($this->startMtime))]));
    if ($results['last_page'] == 0) {
      $this->logger->notice("Nothing to update!");
      return FALSE;
    }
    $max_pages = (!empty($this->maxPages && $this->maxPages < $results['last_page'])) ? $this->maxPages : $results['last_page'];
    $this->logger->notice(t("Processing @pages pages of results out of @available possible pages.", [
      '@pages' => $max_pages,
      '@available' => $results['last_page'],
    ]));
    // Create an operation for each page, upto the max page count.
    $operations = [];
    for ($batchId = 1; $batchId <= $max_pages; $batchId++) {
      $batch_parameters = $parameters;
      $batch_parameters['page'] = $batchId;
      $operations[] = [
        [$this, 'updatePage'],
        [
          $batchId,
          $this->archivesspaceSession,
          $batch_parameters,
        ],
      ];
    }
    return([
      'title' => t('Processing @num pages of updates.', ['@num' => $max_pages]),
      'operations' => $operations,
      'finished' => [$this, 'updatePageFinished'],
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
  public function updatePage($id, ArchivesSpaceSession $session, array $parameters, &$context) {
    // Get page number and query from operation details.
    // Issue query.
    $results = $session->request('GET', '/search', $parameters);
    $context['message'] = t("Processing page @page of updates.", [
      '@page' => $results['this_page'],
    ]);
    // Group embedded data rows into migrations based on each's json_model.
    $migrations = [];
    foreach ($results['results'] as $count => $result) {
      if ($migration_id = $this->utils->getUriMigration($result['uri'])) {
        $migrations[$migration_id][] = json_decode($result['json'], TRUE);
        // Grab the user_mtime from the last item.
        // By the end we will have the last user_mtime because results are
        // sorted by the user_mtime.
        $context['results']['last_user_mtime'] = $result['user_mtime'];
      }
    }

    foreach ($migrations as $migration_id => $data) {
      $context['message'] = t("Running migration '@migration' with @rows items.", [
        '@migration' => $migration_id,
        '@rows' => count($data),
      ]);
      // Load the relevant migration with the embedded data source.
      $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id, [
        'source' => [
          'plugin' => 'embedded_data',
          'data_rows' => $data,
          'ids' => ['uri' => ['type' => 'string']],
        ],
      ]);
      if (!$migration) {
        $context['message'] = t("Could not find a migration with the ID '@id'!", ['@id' => $migration_id]);
        return;
      }
      // Force the migration for batches rather than fail
      // due to missed requirements.
      $migration->set('requirements', []);
      $migration->getIdMap()->prepareUpdate();
      $executable = new MigrateExecutable($migration, new MigrateMessage());
      $migration_result = $executable->import();
      if ($migration_result == MigrationInterface::RESULT_COMPLETED) {
        if (!array_key_exists('migration_counts', $context['results'])) {
          $context['results']['migration_counts'] = [];
        }
        if (!array_key_exists($migration_id, $context['results']['migration_counts'])) {
          $context['results']['migration_counts'][$migration_id] = 0;
        }
        $context['results']['migration_counts'][$migration_id] += count($data);
      }
      else {
        $result_msg = '';
        switch ($migration_result) {
          // Error messages pulled from the MigrationInterface API docs.
          case MigrationInterface::RESULT_DISABLED:
            $result_msg = "This migration is disabled, skipping.";
            break;

          case MigrationInterface::RESULT_FAILED:
            $result_msg = "The process had a fatal error.";
            break;

          case MigrationInterface::RESULT_INCOMPLETE:
            $result_msg = "The process has stopped itself (e.g., the memory limit is approaching).";
            break;

          case MigrationInterface::RESULT_SKIPPED:
            $result_msg = "Dependencies are unfulfilled - skip the process.";
            break;

          case MigrationInterface::RESULT_STOPPED:
            $result_msg = "The process was stopped externally (e.g., via drush migrate-stop).";
            break;
        }
        // Kill further processing.
        throw new \Exception(t("Migration '@migration' from page @page failed to complete with the message: @result", [
          '@migration' => $migration_id,
          '@page' => $results['this_page'],
          '@result' => $result_msg,
        ]));
      }
      // Update state with the most recent user_mtime since this operation
      // completed successfully, but the next one may not.
      // But only if we don't have a type filter.
      $existing_timestamp = strtotime(\Drupal::state()->get('archivesspace.latest_user_mtime'));
      $new_timestamp = strtotime($context['results']['last_user_mtime']);
      if (!array_key_exists('type[]', $parameters) && $new_timestamp > $existing_timestamp) {
        \Drupal::state()->set('archivesspace.latest_user_mtime', $context['results']['last_user_mtime']);
      }
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
  public function updatePageFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    if ($success) {
      // Update the most recent update time with latest user_mtime found.
      if (!empty($results['last_user_mtime'])) {
        $messenger->addMessage(t("Proccessed items updated through @time.", ['@time' => $results['last_user_mtime']]));
        // Add one so we don't keep updating the last batch.
        $existing_timestamp = strtotime(\Drupal::state()->get('archivesspace.latest_user_mtime'));
        $new_timestamp = strtotime($results['last_user_mtime']) + 1;
        if ($new_timestamp > $existing_timestamp) {
          \Drupal::state()->set('archivesspace.latest_user_mtime', date(DATE_ATOM, $new_timestamp));
          $messenger->addMessage("New time: " . \Drupal::state()->get('archivesspace.latest_user_mtime'));
        }
      }
      else {
        $messenger->addWarning(t("Warning: Unable to to update the update's last modified time."));
      }

      // Report out migrations.
      if (!empty($results['migration_counts'])) {
        foreach ($results['migration_counts'] as $mid => $count) {
          $messenger->addMessage(t("Migration @mid processed @count items.", [
            '@mid' => $mid,
            '@count' => $count,
          ]));
        }
      }
      else {
        $messenger->addWarning(t("Warning: no migrations appear to have been run."));
      }

    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      $messenger->addWarning(
        t('An error occurred while processing @operation with arguments : @args',
          [
            '@operation' => 'Update Page',
            '@args' => print_r($error_operation[1][2], TRUE),
          ]
        )
          );
    }
  }

}
