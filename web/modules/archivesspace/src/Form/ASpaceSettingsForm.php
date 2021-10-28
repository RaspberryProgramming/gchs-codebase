<?php

namespace Drupal\archivesspace\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure ArchivesSpace Integration settings for this site.
 */
class ASpaceSettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'archivesspace.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'archivesspace_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $state = \Drupal::state();

    $form['connection'] = [
      '#type' => 'details',
      '#title' => t('ArchivesSpace API Connection'),
      '#open' => TRUE,
    ];

    $form['connection']['archivesspace_base_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ArchivesSpace API Prefix'),
      '#default_value' => $state->get('archivesspace.base_uri'),
    ];

    $form['connection']['archivesspace_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ArchivesSpace Username'),
      '#default_value' => $state->get('archivesspace.username'),
    ];

    $form['connection']['archivesspace_password'] = [
      '#type' => 'password',
      '#title' => $this->t('ArchivesSpace Password'),
      '#default_value' => '',
      '#description'   => t('Leave blank to make no changes, use an invalid string to disable if need be.'),
    ];

    $config = $this->config(static::SETTINGS);
    $form['resource_description'] = [
      '#type' => 'details',
      '#title' => t('Resource Description Files'),
      '#open' => FALSE,
    ];
    $form['resource_description']['resource_description_queue_enabled'] = [
      '#type' => 'checkbox',
      '#title' => t('Enable Resource Description File Update Queue'),
      '#description' => t("Allow resource description and archival object updates can trigger resource description file update requests. Note: We ignore update requests that were made before the resource description file's most recent update. This prevents redundant updates."),
      '#default_value' => $config->get('resource_description_queue_enabled'),
    ];

    $form['breadcrumbs'] = [
      '#type' => 'details',
      '#title' => t('Breadcrumbs'),
      '#description' => t('ArchivesSpace breadcrumbs show the parent hierarchy of archival objects and are usually displayed across the top of a page.'),
      '#open' => FALSE,
    ];

    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('node');
    foreach ($bundles as $content_type => $content_type_properties) {
      $content_type_options[$content_type] = $content_type_properties['label'];
    }

    $form['breadcrumbs']['breadcrumb_content_types'] = [
      '#title' => $this->t('ArchivesSpace Content Types'),
      '#type' => 'checkboxes',
      '#options' => $content_type_options,
      '#default_value' => $config->get('breadcrumb.content_types'),
      '#description' => t('Select which content types represent ArchivesSpace resources and archival objects. Deselecting them effectively disables this breadcrumb service allowing either the system-provided breadcrumbs or other breadcrumb generators to be used instead.'),
    ];

    $field_map = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('entity_reference');
    $node_fields = array_keys($field_map['node']);
    $parent_options = array_combine($node_fields, $node_fields);
    $form['breadcrumbs']['parent_field'] = [
      '#type' => 'select',
      '#title' => t('Field that contains reference to parents'),
      '#options' => $parent_options,
      '#empty_option' => t('None'),
      '#default_value' => $config->get('breadcrumb.parent_field'),
      '#description' => t("Machine name of field that contains references to parent node."),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Set the provided values in Drupal state.
    $state = \Drupal::state();
    $state->set('archivesspace.base_uri', $form_state->getValue('archivesspace_base_uri'));
    $state->set('archivesspace.username', $form_state->getValue('archivesspace_username'));
    if (!empty($form_state->getValue('archivesspace_password'))) {
      $state->set('archivesspace.password', $form_state->getValue('archivesspace_password'));
    }

    $this->configFactory->getEditable(static::SETTINGS)
      ->set('breadcrumb.content_types', array_keys(array_filter($form_state->getValue('breadcrumb_content_types'))))
      ->set('breadcrumb.parent_field', $form_state->getValue('parent_field'))
      ->set('resource_description_queue_enabled', $form_state->getValue('resource_description_queue_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
