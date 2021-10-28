<?php

namespace Drupal\archivesspace\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Save queue item in a node.
 *
 * To process the queue items whenever Cron is run,
 * we need a QueueWorker plugin with an annotation witch defines
 * to witch queue it applied.
 *
 * @QueueWorker(
 *   id = "resource_description_queue",
 *   title = @Translation("Trigger Resource Description file actions."),
 * )
 */
class ResourceDescriptionQueueBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  /**
   * Files per batch.
   *
   * @var int
   */
  protected $filesPerBatch = 3;

  /**
   * Maximum number of resource descriptons to update. ('-1' for no limit.)
   *
   * @var int
   */
  protected $maxItems = -1;

  /**
   * Contructs a Resource Description File Worker.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for messages.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   String translation for messages.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   Storage to load nodes.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(
     LoggerInterface $logger,
     TranslationInterface $stringTranslation,
     EntityTypeManagerInterface $entity_manager,
     ConfigFactoryInterface $config_factory
   ) {
    $this->entityTypeManager = $entity_manager;
    $this->config = $config_factory->get('archivesspace.settings');
    $this->logger = $logger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('logger.channel.archivesspace'),
      $container->get('string_translation'),
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    $action_id = $this->config->get('resource_description_files.' . $item->type . '_generate_action');
    $action = $this->entityTypeManager
      ->getStorage('action')
      ->load($action_id);
    // Requests to republish a resource description can pile up. We need to
    // ignore any queue items with an update time earlier than the most
    // recent resource description update.
    $node = $this->entityTypeManager->getStorage('node')->load($item->nid);
    $resource_description_media = $node->get($action->get('configuration')['resource_description_field'])->entity;
    if ($resource_description_media instanceof MediaInterface
      && $item->updateTime > intval($resource_description_media->getChangedTime())) {
      \Drupal::logger('archivesspace')->info("Updating the resource description @type for '@node'.", [
        '@type' => $item->type,
        '@node' => $node->label(),
      ]);
      $action->execute([$node]);
    }
  }

}
