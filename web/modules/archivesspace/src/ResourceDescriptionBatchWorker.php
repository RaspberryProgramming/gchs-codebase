<?php

namespace Drupal\archivesspace;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Manages the bulk gathering of resource description files.
 */
class ResourceDescriptionBatchWorker {

  use StringTranslationTrait;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Files per batch.
   *
   * @var int
   */
  protected $filesPerBatch = 5;

  /**
   * Maximum number of resource descriptons to update. ('-1' for no limit.)
   *
   * @var int
   */
  protected $maxItems = -1;

  /**
   * Resource Description Tracker.
   *
   * @var Drupal\archivesspace\ResourceDescriptionTracker
   */
  protected $tracker;

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
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   */
  public function __construct(
     LoggerInterface $logger,
     TranslationInterface $stringTranslation,
     EntityTypeManagerInterface $entity_manager,
     ConfigFactoryInterface $config_factory,
     QueueFactory $queue_factory
   ) {
    $this->entityTypeManager = $entity_manager;
    $this->config = $config_factory->get('archivesspace.settings');
    $this->logger = $logger;
    $this->stringTranslation = $stringTranslation;
    $this->queueFactory = $queue_factory;
  }

  /**
   * Files per batch setter.
   *
   * @param int $files_per_batch
   *   Number of files to generate per batch. Set low if your repository has
   *   resource descriptions with thousands of archival objects.
   */
  public function setFilesPerBatch(int $files_per_batch) {
    $this->filesPerBatch = $files_per_batch;
  }

  /**
   * Max items setter.
   *
   * @param int $max_items
   *   Maximum number of resource descriptons to update. ('-1' for no limit.)
   */
  public function setMaxItems(int $max_items) {
    $this->maxItems = $max_items;
  }

  /**
   * Files per batch getter.
   */
  public function getFilesPerBatch() {
    return $this->filesPerBatch;
  }

  /**
   * Max items getter.
   */
  public function getMaxItems() {
    return $this->maxItems;
  }

  /**
   * Builds a batch definition.
   */
  public function buildBatchDefinition() {

    $queue = $this->queueFactory->get('resource_description_queue');
    $max = ($this->maxItems > 0 && $this->maxItems < $queue->numberOfItems()) ? $this->maxItems : $queue->numberOfItems();
    $count_accounted_for = 0;
    for ($i = 0; $i < ceil($max / $this->filesPerBatch); $i++) {
      $items_in_this_batch = (($count_accounted_for + $this->filesPerBatch) < $max) ? $this->filesPerBatch : ($max - $count_accounted_for);
      $count_accounted_for += $items_in_this_batch;
      $operations[] = [
        'Drupal\archivesspace\ResourceDescriptionBatchWorker::updateResourceDescriptions',
        [
          $items_in_this_batch,
        ],
      ];
    }
    return([
      'title' => t('Processing @max items in @num sets.', [
        '@max' => $max,
        '@num' => count($operations),
      ]),
      'operations' => $operations,
    ]);
  }

  /**
   * Batch process callback.
   *
   * @param int $count
   *   Count of items to process in this batch.
   * @param object $context
   *   Context for operations.
   */
  public static function updateResourceDescriptions($count, &$context) {
    $queue = \Drupal::service('queue')->get('resource_description_queue');
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('resource_description_queue');
    $number_to_process = ($queue->numberOfItems() < $count) ? $queue->numberOfItems() : $count;
    $context['message'] = t("Processing @number update requests.", [
      '@number' => $number_to_process,
    ]);
    for ($i = 0; $i < $number_to_process; $i++) {
      if ($item = $queue->claimItem()) {
        try {
          $queue_worker->processItem($item->data);
        }
        catch (\Exception $e) {
          \Drupal::logger('archivesspace')->warning("Failed to process update request for node @item_id: @error", [
            '@item_id' => $item->data->nid,
            '@error' => $e->getMessage(),
          ]);
        } finally {
          $queue->deleteItem($item);
        }
      }
    }
  }

}
