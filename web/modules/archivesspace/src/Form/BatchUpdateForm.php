<?php

namespace Drupal\archivesspace\Form;

use Drupal\archivesspace\BatchUpdateBuilder;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * Implments a Batch Update form.
 */
class BatchUpdateForm extends FormBase {

  /**
   * Batch Update Builder.
   *
   * @var \Drupal\archivesspace\BatchUpdateBuilder
   */
  protected $batchUpdateBuilder;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'archivesspace_batch_update';
  }

  /**
   * Constructor.
   *
   * @param \Drupal\archivesspace\BatchUpdateBuilder $bub
   *   The class responsible for building batch updates for processing.
   */
  public function __construct(BatchUpdateBuilder $bub) {
    $this->batchUpdateBuilder = $bub;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('archivesspace.batch_update_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['#cache'] = ['max-age' => 0];

    try {
      $this->batchUpdateBuilder->getArchivesSpaceSession()->getSession();
    }
    catch (ClientException $e) {
      \Drupal::logger('archivesspace')->warning('Could not connect to ArchivesSpace: ' . $e->getMessage());
      $form['warning'] = [
        '#type' => 'item',
        '#markup' => $this->t('Unable to connect to ArchivesSpace. Please check your <a href="/admin/archivesspace/config">ArchivesSpace Core Settings</a>!'),
      ];
      return $form;
    }

    $form['max_pages'] = [
      '#type' => 'number',
      '#title' => 'Max Pages',
      '#size' => 5,
      '#description' => t('Maximum number of pages to process. Optional, leave blank to process all updates.'),
      '#required' => FALSE,
    ];

    // Updated since.
    $latest_user_mtime = \Drupal::state()->get('archivesspace.latest_user_mtime');
    $timestamp = (!empty($latest_user_mtime)) ? strtotime($latest_user_mtime) : 1;
    $form['updated_since'] = [
      '#type' => 'datetime',
      '#title' => 'Updated Since',
      '#default_value' => DrupalDateTime::createFromTimestamp($timestamp),
      '#description' => t('When to start looking for updates. Auto-populated with the last known successful update timestamp.'),
      '#required' => TRUE,
    ];

    // Types dropdown?
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

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $max_pages = intval($form_state->getValue('max_pages'));
    if ($max_pages > 0) {
      $this->batchUpdateBuilder->setMaxPages($max_pages);
    }
    $this->batchUpdateBuilder->setUpdatedSince($form_state->getValue('updated_since')->format(DATE_ATOM));
    if ($batch = $this->batchUpdateBuilder->buildBatchDefinition()) {
      batch_set($batch);
    }
    else {
      $this->messenger()->addMessage(t("No updates to process!"));
    }

  }

}
