<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for logging Config Guardian activities.
 */
class ActivityLoggerService {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The cache tags invalidator.
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * Constructs an ActivityLoggerService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    RequestStack $request_stack,
    Connection $database,
    TimeInterface $time,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
    $this->database = $database;
    $this->time = $time;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  /**
   * Logs an activity.
   *
   * @param string $action
   *   The action performed.
   * @param array $details
   *   Details about the action.
   * @param array $config_names
   *   List of affected configuration names.
   * @param int|null $snapshot_id
   *   Related snapshot ID.
   * @param string $status
   *   Status: success, error, warning.
   */
  public function log(
    string $action,
    array $details = [],
    array $config_names = [],
    ?int $snapshot_id = NULL,
    string $status = 'success',
  ): void {
    $request = $this->requestStack->getCurrentRequest();
    $ip_address = $request ? $request->getClientIp() : '';

    $this->database->insert('config_guardian_activity_log')
      ->fields([
        'action' => $action,
        'details' => json_encode($details),
        'config_names' => json_encode($config_names),
        'snapshot_id' => $snapshot_id,
        'user_id' => $this->currentUser->id(),
        'ip_address' => $ip_address,
        'timestamp' => $this->time->getRequestTime(),
        'status' => $status,
      ])
      ->execute();

    // Invalidate cache tags to refresh dashboard.
    $this->cacheTagsInvalidator->invalidateTags(['config_guardian_activity_list']);
  }

  /**
   * Gets activity log entries.
   *
   * @param array $filters
   *   Optional filters.
   * @param int $limit
   *   Maximum number of results.
   * @param int $offset
   *   Offset for pagination.
   *
   * @return array
   *   List of activity entries.
   */
  public function getActivities(array $filters = [], int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('config_guardian_activity_log', 'a')
      ->fields('a')
      ->orderBy('timestamp', 'DESC')
      ->range($offset, $limit);

    if (!empty($filters['action'])) {
      $query->condition('action', $filters['action']);
    }

    if (!empty($filters['status'])) {
      $query->condition('status', $filters['status']);
    }

    if (!empty($filters['user_id'])) {
      $query->condition('user_id', $filters['user_id']);
    }

    if (!empty($filters['from_date'])) {
      $query->condition('timestamp', $filters['from_date'], '>=');
    }

    if (!empty($filters['to_date'])) {
      $query->condition('timestamp', $filters['to_date'], '<=');
    }

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    // Decode JSON fields.
    foreach ($results as &$row) {
      $row['details'] = json_decode($row['details'], TRUE) ?? [];
      $row['config_names'] = json_decode($row['config_names'], TRUE) ?? [];
    }

    return $results;
  }

  /**
   * Gets the total count of activities.
   *
   * @param array $filters
   *   Optional filters.
   *
   * @return int
   *   The total count.
   */
  public function getActivityCount(array $filters = []): int {
    $query = $this->database->select('config_guardian_activity_log', 'a')
      ->countQuery();

    if (!empty($filters['action'])) {
      $query->condition('action', $filters['action']);
    }

    if (!empty($filters['status'])) {
      $query->condition('status', $filters['status']);
    }

    return (int) $query->execute()->fetchField();
  }

  /**
   * Gets recent activities for dashboard.
   *
   * @param int $limit
   *   Number of activities to return.
   *
   * @return array
   *   List of recent activities.
   */
  public function getRecentActivities(int $limit = 10): array {
    return $this->getActivities([], $limit);
  }

  /**
   * Cleans up old activity logs.
   *
   * @param int $days
   *   Delete logs older than this many days.
   *
   * @return int
   *   Number of deleted entries.
   */
  public function cleanup(int $days): int {
    $cutoff = $this->time->getRequestTime() - ($days * 86400);

    $deleted = $this->database->delete('config_guardian_activity_log')
      ->condition('timestamp', $cutoff, '<')
      ->execute();

    if ($deleted > 0) {
      $this->cacheTagsInvalidator->invalidateTags(['config_guardian_activity_list']);
    }

    return $deleted;
  }

}
