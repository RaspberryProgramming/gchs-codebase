<?php

namespace Drupal\archivesspace\Plugin\Action;

use GuzzleHttp\Exception\ServerException;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\archivesspace\ArchivesSpaceUtils;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Utility\Token;
use Drupal\media\Entity\Media;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates a Resource Description media & file.
 *
 * @Action(
 *   id = "update_as_resource_description_file",
 *   label = @Translation("Update ArchivesSpace Resource Description File"),
 *   type = "node",
 * )
 */
class UpdateResourceDescriptionFile extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * ArchivesSpace Utils.
   *
   * @var \Drupal\archivesspace\ArchivesSpaceUtils
   */
  protected $utils;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs a UpdateResourceDescriptionFile object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\archivesspace\ArchivesSpaceUtils $utils
   *   ArchivesSpace utilities for requesting resource description files.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, Token $token, ArchivesSpaceUtils $utils) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->utils = $utils;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('token'), $container->get('archivesspace.utils'));
  }

  /**
   * {@inheritdoc}
   */
  public function execute($node = NULL) {
    if ($node instanceof NodeInterface) {

      // Get the configured description media reference field.
      $field_id = $this->configuration['resource_description_field'];

      /** @var \Drupal\node\NodeInterface $node */
      if (!$node->hasField($field_id)) {
        throw new \RuntimeException(
          $this->t("Resource description field %field not found on node %id."),
          [
            '%field' => $field_id,
            '%id' => $node->id(),
          ]);
      }

      // Build the intended file path, from config.
      $directory = $this->configuration['filesystem'] . $this->configuration['path'] . '/';
      \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      // Sanitize the name based on the configured token value because who knows
      // *what* we will be handed, especially if they have HTML titles.
      $new_name = preg_replace(
        '/\-+/', '-',
        preg_replace(
          '/[^a-zA-Z0-9_-]+/', '',
          str_replace(
            [' ', '/'], '-',
             $this->token->replace(
               $this->configuration['filename_pattern'],
               ['node' => $node]
             )
           )
         )
       );
      $path = $directory . $new_name . '.' . $this->configuration['file_type'];
      try {
        // Call for the new file and save it.
        $stream = $this->utils->getResourceDescription($node->id(), $this->configuration['file_type']);
        $file = file_save_data($stream->getContents(), $path, FileSystemInterface::EXISTS_REPLACE);
      }
      catch (ServerException $se) {
        throw new \Exception("Failed to retrieve resource description from ArchivesSpace: " . $se->getMessage());
      }
      if (!$file) {
        throw new \Exception("Aborting Resource Description; failed to create or update '$path'.");
      }
      // Create or Update the media with the file.
      // Yes, we still need to update the media because the file path may
      // have changed.
      $media_file_field = 'field_media_' . $this->configuration['media_document_type'];
      $resource_description_media = $node->get($field_id)->entity;
      if (!$resource_description_media instanceof MediaInterface) {
        $resource_description_media = Media::create([
          'bundle' => $this->configuration['media_document_type'],
          'uid' => \Drupal::currentUser()->id(),
          $media_file_field => [
            'target_id' => $file->id(),
          ],
        ]);
        $node->set($field_id, $resource_description_media);
        $node->save();
      }
      else {
        $resource_description_media->set($media_file_field, $file);
        // Drupal doesn't see the media field set to the file as a change,
        // so we force the changed time update.
        $resource_description_media->setChangedTime(time());
        $resource_description_media->save();
      }
      \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'filesystem' => 'public://',
      'path' => 'finding-aids',
      'filename_pattern' => '[node:title]',
      'file_type' => 'pdf',
      'resource_description_field' => 'field_printable_pdf',
      'media_document_type' => 'document',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form['filesystem'] = [
      '#type' => 'textfield',
      '#title' => t('File System'),
      '#default_value' => $this->configuration['filesystem'],
      '#required' => TRUE,
      '#description' => t('The filesystem used to store the resource description files. Usually set to "public://" unless you need to restrict access to them, in which case you would use "private://".'),
    ];
    $form['path'] = [
      '#type' => 'textfield',
      '#title' => t('File Path'),
      '#default_value' => $this->configuration['path'],
      '#required' => TRUE,
      '#description' => t('Which directory the files should be stored (if any).'),
    ];
    $form['filename_pattern'] = [
      '#type' => 'textfield',
      '#title' => t('File System'),
      '#default_value' => $this->configuration['filename_pattern'],
      '#required' => TRUE,
      '#description' => t('The filename the system should use. Please include a token, such as "[node:id]" to ensure the filename is unique to avoid overwritting other resource description files.'),
    ];
    $form['file_type'] = [
      '#type' => 'textfield',
      '#title' => t('File System'),
      '#default_value' => $this->configuration['file_type'],
      '#required' => TRUE,
      '#description' => t('The type of resource description to create/update. "pdf" for PDFs and "xml" for EAD.'),
    ];
    $form['resource_description_field'] = [
      '#type' => 'textfield',
      '#title' => t('File System'),
      '#default_value' => $this->configuration['resource_description_field'],
      '#required' => TRUE,
      '#description' => t('Which field on the Archival Resource content type stores resource description media.'),
    ];
    $form['media_document_type'] = [
      '#type' => 'textfield',
      '#title' => t('File System'),
      '#default_value' => $this->configuration['media_document_type'],
      '#required' => TRUE,
      '#description' => t('The type of Media used to store the resource description file. "document" should be used for PDFs and "file" should be used for xml (EAD).'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['filesystem'] = $form_state->getValue('filesystem');
    $this->configuration['path'] = $form_state->getValue('path');
    $this->configuration['filename_pattern'] = $form_state->getValue('filename_pattern');
    $this->configuration['file_type'] = $form_state->getValue('file_type');
    $this->configuration['resource_description_field'] = $form_state->getValue('resource_description_field');
    $this->configuration['media_document_type'] = $form_state->getValue('media_document_type');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowed();
    return $return_as_object ? $result : $result->isAllowed();
  }

}
