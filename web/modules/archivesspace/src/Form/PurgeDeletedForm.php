<?php

namespace Drupal\archivesspace\Form;

use Drupal\archivesspace\Purger;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implments a Purge Deleted Update form.
 */
class PurgeDeletedForm extends FormBase {

  /**
   * Purger.
   *
   * @var \Drupal\archivesspace\Purger
   */
  protected $purger;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'archivesspace_purger';
  }

  /**
   * Constructor.
   *
   * @param \Drupal\archivesspace\Purger $purger
   *   The class responsible for purging deleted items.
   */
  public function __construct(Purger $purger) {
    $this->purger = $purger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('archivesspace.purger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['max_pages'] = [
      '#type' => 'number',
      '#title' => 'Max Pages',
      '#size' => 5,
      '#description' => t('Maximum number of pages to process. Optional, leave blank to process all updates.'),
      '#required' => FALSE,
    ];

    $form['start_page'] = [
      '#type' => 'number',
      '#title' => 'Start Page',
      '#size' => 5,
      '#default_value' => $this->purger->getFirstPage(),
      '#description' => t('The delete feed page to start working from. Auto-populated based on past runs.'),
      '#required' => FALSE,
    ];

    // Types dropdown?
    $form['submit_button'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge Deleted'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $max_pages = intval($form_state->getValue('max_pages'));
    if ($max_pages > 0) {
      $this->purger->setMaxPages($max_pages);
    }
    $this->purger->setFirstPage($form_state->getValue('start_page'));
    if ($batch = $this->purger->buildBatchDefinition()) {
      batch_set($batch);
    }
    else {
      $this->messenger()->addMessage(t("No deletes to process!"));
    }

  }

}
