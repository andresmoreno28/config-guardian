<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\config_guardian\Service\ActivityLoggerService;
use Drupal\config_guardian\Service\SnapshotManagerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for deleting a snapshot.
 */
class SnapshotDeleteForm extends ConfirmFormBase {

  /**
   * The snapshot manager.
   */
  protected SnapshotManagerService $snapshotManager;

  /**
   * The activity logger.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * The snapshot data.
   */
  protected ?array $snapshot = NULL;

  /**
   * Constructs a SnapshotDeleteForm object.
   */
  public function __construct(
    SnapshotManagerService $snapshot_manager,
    ActivityLoggerService $activity_logger,
  ) {
    $this->snapshotManager = $snapshot_manager;
    $this->activityLogger = $activity_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.snapshot_manager'),
      $container->get('config_guardian.activity_logger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'config_guardian_snapshot_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the snapshot "@name"?', [
      '@name' => $this->snapshot['name'] ?? '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('config_guardian.snapshots');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action cannot be undone. The snapshot and all its configuration data will be permanently deleted.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $config_snapshot = NULL): array {
    $this->snapshot = $this->snapshotManager->loadSnapshot((int) $config_snapshot);

    if (!$this->snapshot) {
      $this->messenger()->addError($this->t('Snapshot not found.'));
      return [];
    }

    $form = parent::buildForm($form, $form_state);

    $form['snapshot_id'] = [
      '#type' => 'hidden',
      '#value' => $config_snapshot,
    ];

    // Custom button styling.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'button';
      $form['actions']['submit']['#attributes']['class'][] = 'button--danger';
    }
    if (isset($form['actions']['cancel'])) {
      $form['actions']['cancel']['#attributes']['class'][] = 'button';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $snapshot_id = (int) $form_state->getValue('snapshot_id');
    $snapshot_name = $this->snapshot['name'] ?? '';

    if ($this->snapshotManager->deleteSnapshot($snapshot_id)) {
      $this->activityLogger->log(
        'snapshot_deleted',
        [
          'snapshot_id' => $snapshot_id,
          'snapshot_name' => $snapshot_name,
        ]
      );

      $this->messenger()->addStatus($this->t('Snapshot "@name" has been deleted.', [
        '@name' => $snapshot_name,
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Failed to delete snapshot.'));
    }

    $form_state->setRedirect('config_guardian.snapshots');
  }

}
