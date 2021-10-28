<?php

namespace Drupal\archivesspace\Form;

use Drupal\archivesspace\ResourceDescriptionBatchWorker;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implments a Batch Update form.
 */
class ResourceDescriptionFileUpdateForm extends FormBase {

  /**
   * Batch Update Builder.
   *
   * @var \Drupal\archivesspace\ResourceDescriptionBatchWorker
   */
  protected $descriptionBatchWorker;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'archivesspace_resource_description_file_update';
  }

  /**
   * Constructor.
   *
   * @param \Drupal\archivesspace\ResourceDescriptionBatchWorker $rdbw
   *   The class responsible for building batch updates for processing.
   */
  public function __construct(ResourceDescriptionBatchWorker $rdbw) {
    $this->descriptionBatchWorker = $rdbw;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('archivesspace.resource_description_batch_worker')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['#cache'] = ['max-age' => 0];

    $form['max_files_to_update'] = [
      '#type' => 'number',
      '#title' => 'Maximum Update Requests to Process',
      '#size' => 5,
      '#description' => t('Maximum number of updated requests. Optional, leave blank to update remaining items.'),
      '#required' => FALSE,
    ];

    $form['files_per_batch'] = [
      '#type' => 'number',
      '#title' => 'Update Requests per Batch',
      '#size' => 5,
      '#default_value' => $this->descriptionBatchWorker->getFilesPerBatch(),
      '#description' => t('Number of update requests per batch. Set high if you just performed a large update of archival objects. Set low if the update requests are from resource description updates.'),
      '#required' => TRUE,
    ];

    $form['submit_button'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Updates'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (intval($form_state->getValue('files_per_batch')) < 1) {
      $form_state->setErrorByName('files_per_batch', $this->t('The items per batch must be greater than zero.'));
    }
    $max_files = $form_state->getValue('max_files_to_update');
    if (!empty($max_files) and intval($max_files) < 1) {
      $form_state->setErrorByName('max_files_to_update', $this->t('The maximum update requests to process must be either greater than zero OR blank.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->descriptionBatchWorker->setFilesPerBatch(intval($form_state->getValue('files_per_batch')));

    // Value will either be a positive integer or empty.
    // Set to -1 for all if empty.
    $max_files = intval($form_state->getValue('max_files_to_update'));
    if ($max_files == 0) {
      $max_files = -1;
    }
    $this->descriptionBatchWorker->setMaxItems($max_files);

    if ($batch = $this->descriptionBatchWorker->buildBatchDefinition()) {
      batch_set($batch);
    }
    else {
      $this->messenger()->addMessage(t("No updates to process!"));
    }

  }

}
