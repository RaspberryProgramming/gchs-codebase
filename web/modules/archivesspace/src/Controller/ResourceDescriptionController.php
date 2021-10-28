<?php

namespace Drupal\archivesspace\Controller;

use Drupal\archivesspace\ArchivesSpaceUtils;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Controls managing Resource Description files.
 *
 * Currently focusing on PDFs but can include EAD (XML) later.
 */
class ResourceDescriptionController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * ArchivesSpace Utils.
   *
   * @var \Drupal\archivesspace\ArchivesSpaceUtils
   */
  protected $utils;

  /**
   * Migration manager to load resource migration.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $migrationManager;

  /**
   * Queue factory to track actions.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Class constructor.
   */
  public function __construct(
    ArchivesSpaceUtils $utils,
    MigrationPluginManagerInterface $migrationManager,
    TranslationInterface $stringTranslation,
    QueueFactory $queue_factory) {
    $this->utils = $utils;
    $this->migrationManager = $migrationManager;
    $this->stringTranslation = $stringTranslation;
    $this->queue_factory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('archivesspace.utils'),
      $container->get('plugin.manager.migration'),
      $container->get('string_translation'),
      $container->get('queue')
    );
  }

  /**
   * A page for managing Resource Description files.
   *
   * @return array
   *   An array suitable for drupal_render().
   */
  public function content() {
    if (\Drupal::config('archivesspace.settings')->get('resource_description_queue_enabled')) {

      // Gathering the data.
      $migration_id = $this->utils->getUriMigration('/repositories/2/resources/1');
      $migration = $this->migrationManager->createInstance($migration_id);
      $importedCount = $migration->getIdMap()->importedCount();
      $queue = $this->queue_factory->get('resource_description_queue');
      $pending_updates = $queue->numberOfItems();

      // The data.
      $build['stats_table'] = [
        '#theme' => 'container',
        '#children' => [
              ['#markup' => $this->t("<h2>Stats</h2>")],
              [
                '#type' => 'table',
                '#rows' => [
                  [$this->t('Imported Resource Descriptions'), $importedCount],
                  [$this->t('Pending Updates'), $pending_updates],
                ],
              ],
        ],
      ];
      // Form for batch updating more resource descriptions.
      $build['update_form'] = \Drupal::formBuilder()->getForm('Drupal\archivesspace\Form\ResourceDescriptionFileUpdateForm');
    }
    else {
      $build['disabled_rdq_message'] = [
        '#markup' => $this->t("The resource description queue has been disabled. Updating resource description files must be done by manually triggering the update action for each resource. You can enable this feature with the Core Settings form."),
      ];
    }

    return $build;
  }

}
