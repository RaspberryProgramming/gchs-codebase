<?php

namespace Drupal\archivesspace;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a breadcrumb builder for finding aids.
 */
class ArchivesSpaceBreadcrumbBuilder implements BreadcrumbBuilderInterface {
  use StringTranslationTrait;

  /**
   * The configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Storage to load nodes.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * Constructs a breadcrumb builder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   Storage to load nodes.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager, ConfigFactoryInterface $config_factory) {
    $this->nodeStorage = $entity_manager->getStorage('node');
    $this->config = $config_factory->get('archivesspace.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $attributes) {
    $nid = $attributes->getRawParameters()->get('node');
    if (!empty($nid)) {
      $node = $this->nodeStorage->load($nid);
      return (!empty($node) && in_array($node->bundle(), $this->config->get('breadcrumb.content_types')));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $nid = $route_match->getRawParameters()->get('node');
    $node = $this->nodeStorage->load($nid);
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    $parents = $this->walkRelations($node);

    // Don't include the current item. @TODO make configurable.
    array_pop($parents);
    $breadcrumb->addCacheableDependency($node);

    // Add parents to the breadcrumb.
    foreach ($parents as $crumb) {
      $breadcrumb->addCacheableDependency($crumb);
      $breadcrumb->addLink($crumb->toLink());
    }
    $breadcrumb->addCacheContexts(['route']);
    return $breadcrumb;
  }

  /**
   * Walks up the finding-aid hierarchy.
   */
  protected function walkRelations(EntityInterface $entity) {
    $parent_field = $this->config->get('breadcrumb.parent_field');
    if (!empty($parent_field) && $entity->hasField($parent_field) &&
      !$entity->get($parent_field)->isEmpty()) {
      $crumbs = $this->walkRelations($entity->get($parent_field)->entity);
      $crumbs[] = $entity;
      return $crumbs;
    }
    return [$entity];
  }

}
