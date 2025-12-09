<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\config_guardian\Service\ActivityLoggerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for activity log.
 */
class ActivityController extends ControllerBase {

  /**
   * The activity logger.
   */
  protected ActivityLoggerService $activityLogger;

  /**
   * Constructs an ActivityController object.
   */
  public function __construct(ActivityLoggerService $activity_logger) {
    $this->activityLogger = $activity_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.activity_logger')
    );
  }

  /**
   * Lists all activities.
   *
   * @return array
   *   A render array.
   */
  public function list(): array {
    $activities = $this->activityLogger->getActivities([], 100);

    return [
      '#theme' => 'config_guardian_activity_log',
      '#activities' => $activities,
      '#attached' => [
        'library' => ['config_guardian/admin'],
      ],
    ];
  }

}
