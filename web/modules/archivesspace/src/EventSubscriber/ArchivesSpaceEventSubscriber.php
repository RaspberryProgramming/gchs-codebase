<?php

namespace Drupal\archivesspace\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * MigrateEvents::POST_ROW_SAVE on the configured archival resources migration.
 */
class ArchivesSpaceEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   *
   * @return array
   *   The event names to listen for, and the methods that should be executed.
   */
  public static function getSubscribedEvents() {
    return [
      MigrateEvents::POST_ROW_SAVE => 'postRowSave',
    ];
  }

  /**
   * React to a config object being saved.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   Post row save event.
   */
  public function postRowSave(MigratePostRowSaveEvent $event) {
    if ($event->getMigration()->getDestinationConfiguration()['plugin'] == 'entity:node' and \Drupal::config('archivesspace.settings')->get('resource_description_queue_enabled')) {
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $node = $node_storage->load(array_pop(array_reverse($event->getDestinationIdValues())));
      if (in_array($node->bundle(), ['archival_resource', 'archival_object'])) {
        \Drupal::service('archivesspace.utils')->addItem($node);
      }
    }
  }

}
