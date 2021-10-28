<?php

namespace Drupal\archivesspace;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;

/**
 * ArchivesSpace Utilities that don't belong elsewhere.
 */
class ArchivesSpaceUtils {

  use StringTranslationTrait;

  /**
   * ArchivesSpaceSession that will allow us to issue API requests.
   *
   * @var \Drupal\archivesspace\ArchivesSpaceSession
   */
  protected $archivesspaceSession;

  /**
   * ArchivesSpace Settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * Maps AS uri prefixes type to their respective migrations.
   *
   * This is populated from the
   * `archivesspace.settings.batch_update.uri_migration_map` config
   * in the constructor. Not all of the possible item types are currently
   * supported. Unsupported types are:
   *   - repository: Doesn't appear to work as a type filter.
   *   - classification: No existing migrations and it doesn't appear to work
   *     as a type filter, but needs to be verified.
   *   - digital_object: not sure what to do with this one. The question is how
   *     submodules can update a parent module's config. Might need an install
   *     hook to work.
   *   - digital_object_component: see digital_object.
   *   - agent_software: No migrations are provided yet.
   * Site implementors, if they develop a migration for any of these can update
   * their own `archivesspace.settings.batch_update`.
   *
   * @var array
   */
  protected $uriMigrationMap = [];

  /**
   * Constructor to set defaults.
   */
  public function __construct(LoggerInterface $logger, TranslationInterface $stringTranslation) {
    $this->logger = $logger;
    $this->stringTranslation = $stringTranslation;
    // Map regex pattern to migration_id.
    $this->settings = \Drupal::config('archivesspace.settings');
    $migration_map = $this->settings->get('batch_update.uri_migration_map');
    if (!is_array($migration_map)) {
      throw new \Exception("ArchivesSpace URI migration map configuration is invalid.");
    }
    foreach ($migration_map as $regex_migration_pair) {
      $this->uriMigrationMap[$regex_migration_pair['uri_regex']] = $regex_migration_pair['migration_id'];
    }
    $this->archivesspaceSession = new ArchivesSpaceSession();
  }

  /**
   * URI to Migration Map.
   */
  public function getUriMigration(string $uri) {
    foreach ($this->uriMigrationMap as $regex => $migration_id) {
      if (preg_match($regex, $uri) == 1) {
        return $migration_id;
      }
    }
    return FALSE;
  }

  /**
   * Resource Node to Resource Description.
   *
   * @param int $nid
   *   The node ID of the resource we need the resource description of.
   * @param string $type
   *   What type of resource description, i.e. 'pdf' (default) or 'xml' (EAD).
   * @param array $params
   *   Parameters to pass to the ArchivesSpace API.
   *
   * @return GuzzleHttp\Psr7\Stream
   *   Returns a content stream to either save as a file or send as a download.
   */
  public function getResourceDescription(int $nid, string $type = 'pdf', array $params = [
    'include_unpublished' => 'false',
    'include_daos' => 'true',
  ]) {
    // It doesn't actually matter which resource URI we use, just one that
    // matches the URI patter that will give us the migration id we need.
    $migration_id = $this->getUriMigration('/repositories/2/resources/1');
    $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);
    $source = $migration->getIdMap()->lookupSourceId(['nid' => $nid]);
    if (empty($source)) {
      $this->logger->error($this->t("Could not find an ArchivesSpace URI for node %nid to produce it's %type resource description.",
      [
        '%nid' => $nid,
        '%type' => $type,
      ]));
      return FALSE;
    }
    try {
      $resource_description_uri = str_replace('resources', 'resource_descriptions', $source['uri']) . '.' . $type;
      return $this->archivesspaceSession->request('GET', $resource_description_uri, $params, TRUE);
    }
    catch (ClientException $e) {
      $this->logger->error(
        $this->t("Failed to produce a '%type' resource description for node %nid. The ArchivesSpace API request failed: %message",
        [
          '%nid' => $nid,
          '%type' => $type,
          '%message' => $e->getMessage(),
        ]));
    }
  }

  /**
   * Add item to queue.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node to add to the queue.
   */
  public function addItem(NodeInterface $node) {
    if ($node->bundle() == 'archival_resource') {
      $nidToUpdate = $node->id();
    }
    elseif ($node->bundle() == 'archival_object' && !empty($node->get('field_as_resource')->target_id)) {
      $nidToUpdate = $node->get('field_as_resource')->target_id;
    }
    else {
      return;
    }
    $types = $this->settings->get('resource_description_files.enabled_types');
    $queue = \Drupal::service('queue')->get('resource_description_queue');
    foreach ($types as $type) {
      $item = new \stdClass();
      $item->nid = $nidToUpdate;
      $item->type = $type;
      $item->updateTime = time();
      $queue->createItem($item);
    }
  }

}
