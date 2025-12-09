# Config Guardian

[![Drupal 10.2+](https://img.shields.io/badge/Drupal-10.2%2B-blue.svg)](https://www.drupal.org/project/drupal)
[![Drupal 11](https://img.shields.io/badge/Drupal-11-blue.svg)](https://www.drupal.org/project/drupal)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

> **Enterprise-grade configuration management for Drupal** with point-in-time snapshots, safe rollback capabilities, dependency analysis, risk assessment, and comprehensive audit trails.

Config Guardian provides peace of mind when managing Drupal configuration. Create snapshots before risky changes, analyze the impact of pending imports, and safely rollback when things go wrong - all with a beautiful, intuitive interface.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage Guide](#usage-guide)
  - [Dashboard](#dashboard)
  - [Creating Snapshots](#creating-snapshots)
  - [Viewing and Comparing Snapshots](#viewing-and-comparing-snapshots)
  - [Rolling Back Configuration](#rolling-back-configuration)
  - [Impact Analysis](#impact-analysis)
  - [Configuration Synchronization](#configuration-synchronization)
  - [Activity Log](#activity-log)
- [Drush Commands](#drush-commands)
- [API Documentation](#api-documentation)
- [Theming](#theming)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

---

## Features

### Snapshot Management
- **Point-in-time snapshots** - Capture your entire configuration state at any moment
- **Dual storage capture** - V2 snapshots capture both active configuration AND sync directory
- **Automatic snapshots** - Schedule hourly, daily, or weekly automatic backups
- **Pre-import snapshots** - Automatically create a backup before any configuration import
- **Compressed storage** - Snapshots are gzip-compressed to minimize database size
- **Integrity verification** - SHA-256 hash ensures snapshot data integrity
- **Retention policies** - Automatically clean up old snapshots based on age or count

### Safe Rollback
- **Simulation mode** - Preview exactly what will change before executing a rollback
- **Conflict detection** - Identifies potential issues before they cause problems
- **Optional pre-rollback backup** - Creates a safety snapshot before rolling back (enabled by default, can be disabled)
- **Full environment restore** - Restores both active configuration AND sync directory (v2 snapshots)
- **Selective rollback** - Roll back specific configuration items (coming soon)

### Impact Analysis
- **Dependency mapping** - Visualize how configurations depend on each other
- **Interactive dependency graph** - D3.js-powered visualization with zoom, pan, and search
- **Risk assessment** - Automatic scoring (0-100) with risk level classification
- **Conflict detection** - Find circular dependencies and missing requirements
- **Change preview** - See what will be created, updated, or deleted

### Activity Logging
- **Complete audit trail** - Track who did what and when
- **IP tracking** - Record the source of all changes
- **Detailed logging** - Store affected configuration names and change details
- **Status tracking** - Monitor success, warnings, and errors

### Configuration Sync
- **Export management** - Export active configuration to sync storage
- **Import preview** - Review all changes before importing
- **Batch processing** - Handle large configuration sets efficiently
- **Change categorization** - See new, modified, and deleted configurations separately

---

## Requirements

| Requirement | Version |
|-------------|---------|
| Drupal | ^10.2 \|\| ^11 |
| PHP | 8.1+ |
| Core modules | config, system, user |

### Optional but Recommended
- **Drush 12+** - For command-line operations
- **Gin Admin Theme** - For the best visual experience with dark mode support

---

## Installation

### Via Composer (Recommended)

```bash
composer require drupal/config_guardian
```

Then enable the module:

```bash
drush en config_guardian -y
```

### Manual Installation

1. Download and extract the module to `web/modules/contrib/config_guardian` (or `web/modules/custom/` for custom installations)
2. Navigate to **Extend** (`/admin/modules`)
3. Enable "Config Guardian"
4. Clear caches: `drush cr`

### Verify Installation

After installation, navigate to `/admin/config/development/config-guardian` to access the dashboard.

---

## Configuration

### Module Settings

Navigate to **Configuration > Development > Config Guardian > Settings** (`/admin/config/development/config-guardian/settings`)

| Setting | Default | Description |
|---------|---------|-------------|
| **Auto snapshot enabled** | `true` | Enable automatic scheduled snapshots |
| **Pre-import snapshot** | `true` | Create snapshot before configuration imports |
| **Snapshot interval** | `daily` | Frequency: `hourly`, `daily`, or `weekly` |
| **Max snapshots** | `50` | Maximum number of snapshots to retain |
| **Retention days** | `90` | Delete snapshots older than this |
| **Compression** | `gzip` | Compression method for snapshot data |
| **Exclude patterns** | `system.cron`, `core.extension` | Config names to exclude from snapshots |

### Permissions

Config Guardian defines 11 permissions for granular access control:

| Permission | Description |
|------------|-------------|
| `administer config guardian` | Full access to all functionality |
| `create config snapshots` | Create manual snapshots |
| `view config snapshots` | View snapshot list and details |
| `restore config snapshots` | Execute rollback operations |
| `delete config snapshots` | Remove snapshots |
| `export config snapshots` | Download snapshots as files |
| `import config snapshots` | Upload snapshot files |
| `synchronize configuration` | Access sync overview page |
| `export configuration` | Export active config to sync |
| `import configuration` | Import config from sync |
| `analyze config impact` | View impact analysis |

---

## Usage Guide

### Dashboard

The main dashboard (`/admin/config/development/config-guardian`) provides:

- **Status Banner** - Current sync status at a glance
- **Statistics** - Pending changes, total snapshots, recent activity count
- **Pending Changes** - Quick view of configurations awaiting sync
- **Recent Activity** - Latest operations performed
- **Snapshots Table** - List of recent snapshots with quick actions

### Creating Snapshots

#### Via UI

1. Navigate to **Snapshots** tab
2. Click **Create Snapshot**
3. Enter a descriptive name (e.g., "Before theme update")
4. Optionally add a description
5. Click **Save**

#### Via Drush

```bash
# Create a snapshot with default type (manual)
drush config-guardian:snapshot "Before major update"

# Create with description
drush cg-snap "Pre-migration" --description="Before content migration"
```

### Viewing and Comparing Snapshots

#### View a Snapshot

1. Go to **Snapshots** tab
2. Click **View** on any snapshot
3. See all captured configurations with their values

#### Compare Two Snapshots

1. From a snapshot view, click **Compare**
2. Select another snapshot to compare against
3. View differences: added, removed, and modified configurations

### Rolling Back Configuration

**Warning:** Rollback modifies your active configuration. Always review the simulation first.

#### Via UI

1. From the snapshot list, click **Rollback** on the desired snapshot
2. Review the simulation showing:
   - Configurations to create
   - Configurations to update
   - Configurations to delete
   - Risk assessment score and factors
3. Optionally enable "Create backup snapshot before rollback"
4. Click **Rollback** to execute

#### Via Drush

```bash
# Simulate rollback (dry-run)
drush config-guardian:rollback 42 --dry-run

# Execute rollback with confirmation
drush cg-rollback 42

# Force rollback without confirmation
drush cg-rollback 42 --force

# Rollback without creating a backup snapshot
drush cg-rollback 42 --no-backup
```

### Impact Analysis

The impact analysis page (`/admin/config/development/config-guardian/analyze`) helps you understand the consequences of pending configuration changes:

1. **Risk Score** - 0-100 scale with levels:
   - **Low (0-25)** - Safe to proceed
   - **Medium (26-50)** - Review recommended
   - **High (51-75)** - Careful review required
   - **Critical (76-100)** - High risk, proceed with caution

2. **Dependency Graph** - Interactive visualization showing:
   - Configuration relationships
   - Risk-colored nodes
   - Search and filter capabilities
   - Zoom and pan controls

3. **Conflict Detection** - Identifies:
   - Missing dependencies
   - Circular references
   - Type mismatches

### Configuration Synchronization

#### Export Configuration

1. Navigate to **Sync > Export**
2. Review configurations to be exported
3. Click **Export** to write to sync storage

#### Import Configuration

1. Navigate to **Sync > Import**
2. Review pending changes with risk assessment
3. Expand sections to see detailed changes
4. Click **Import** to apply changes

### Activity Log

View all operations at `/admin/config/development/config-guardian/activity`:

- Filter by action type, user, or date range
- View detailed change information
- Export logs for auditing

---

## Drush Commands

Config Guardian provides comprehensive Drush integration:

| Command | Alias | Description |
|---------|-------|-------------|
| `config-guardian:snapshot <name>` | `cg-snap` | Create a new snapshot |
| `config-guardian:list` | `cg-list` | List all snapshots |
| `config-guardian:rollback <id>` | `cg-rollback` | Rollback to a snapshot |
| `config-guardian:analyze` | `cg-analyze` | Analyze pending changes |
| `config-guardian:diff <id1> <id2>` | `cg-diff` | Compare two snapshots |
| `config-guardian:export <id> <path>` | `cg-export` | Export snapshot to file |
| `config-guardian:delete <id>` | `cg-delete` | Delete a snapshot |

### Rollback Command Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Simulate the rollback without making changes |
| `--force` | Skip confirmation prompt |
| `--no-backup` | Skip creating a backup snapshot before rollback |

### Examples

```bash
# Create a snapshot before deployment
drush cg-snap "Pre-deployment $(date +%Y%m%d)"

# List recent snapshots
drush cg-list --limit=10

# List only automatic snapshots
drush cg-list --type=auto

# Analyze pending changes
drush cg-analyze

# Compare snapshots
drush cg-diff 41 42

# Export snapshot for backup
drush cg-export 42 /backups/snapshot-42.json

# Rollback with dry-run first
drush cg-rollback 42 --dry-run
drush cg-rollback 42 --force

# Rollback without backup (use with caution)
drush cg-rollback 42 --force --no-backup
```

---

## API Documentation

Config Guardian exposes several services for programmatic use:

### SnapshotManagerService

```php
// Get the service
$snapshotManager = \Drupal::service('config_guardian.snapshot_manager');

// Create a snapshot
$snapshot = $snapshotManager->createSnapshot(
  'My Snapshot',
  'manual',
  ['description' => 'Created via API']
);

// List snapshots
$snapshots = $snapshotManager->getSnapshotList(['type' => 'manual'], 10);

// Load a specific snapshot
$snapshot = $snapshotManager->loadSnapshot(42);

// Compare snapshots
$diff = $snapshotManager->compareSnapshots(41, 42);

// Delete a snapshot
$snapshotManager->deleteSnapshot(42);
```

### ConfigAnalyzerService

```php
$analyzer = \Drupal::service('config_guardian.config_analyzer');

// Get pending changes
$pending = $analyzer->getPendingChanges();
// Returns: ['create' => [...], 'update' => [...], 'delete' => [...]]

// Calculate risk score
$risk = $analyzer->calculateRiskScore($configNames);
// Returns RiskAssessment object with score, level, riskFactors

// Analyze a specific configuration
$analysis = $analyzer->analyzeConfig('system.site');
// Returns ConfigAnalysis with dependencies, dependents, impactScore

// Find conflicts
$conflicts = $analyzer->findConflicts($configNames);

// Build dependency graph
$graph = $analyzer->buildDependencyGraph($configNames);
```

### RollbackEngineService

```php
$rollbackEngine = \Drupal::service('config_guardian.rollback_engine');

// Simulate a rollback (dry-run)
$simulation = $rollbackEngine->simulateRollback(42);
// Returns RollbackSimulation with toCreate, toUpdate, toDelete, riskAssessment
// Also includes syncToCreate, syncToUpdate, syncToDelete for sync directory changes

// Execute rollback with backup (default)
$result = $rollbackEngine->rollbackToSnapshot(42);
// Returns RollbackResult with success, duration, changes, errors

// Execute rollback without backup
$result = $rollbackEngine->rollbackToSnapshot(42, ['create_backup' => false]);
```

### ActivityLoggerService

```php
$logger = \Drupal::service('config_guardian.activity_logger');

// Log a custom action
$logger->log('custom_action', [
  'description' => 'Custom operation performed',
  'config_names' => ['system.site'],
  'snapshot_id' => 42,
]);

// Get activity log
$activities = $logger->getActivityLog(['action' => 'rollback'], 50);
```

---

## Theming

### CSS Variables

Config Guardian uses CSS custom properties for easy theming. Override these in your admin theme:

```css
:root {
  /* Primary colors */
  --cg-primary: #0d6efd;
  --cg-primary-dark: #0b5ed7;

  /* Status colors */
  --cg-success: #198754;
  --cg-warning: #ffc107;
  --cg-danger: #dc3545;
  --cg-info: #0dcaf0;

  /* Risk level colors */
  --cg-risk-low: #198754;
  --cg-risk-medium: #ffc107;
  --cg-risk-high: #fd7e14;
  --cg-risk-critical: #dc3545;

  /* Layout */
  --cg-border-radius: 8px;
  --cg-spacing: 1rem;
  --cg-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}
```

### Template Locations

Override templates by copying to your theme's `templates/` directory:

| Template | Purpose |
|----------|---------|
| `config-guardian-dashboard.html.twig` | Main dashboard |
| `config-guardian-snapshot-view.html.twig` | Single snapshot view |
| `config-guardian-compare.html.twig` | Snapshot comparison |
| `config-guardian-impact-analysis.html.twig` | Impact analysis page |
| `config-guardian-activity-log.html.twig` | Activity log |
| `config-guardian-sync.html.twig` | Sync overview |

### Dark Mode

Config Guardian automatically supports dark mode when using compatible admin themes:

- **Gin**: Detected via `.gin--dark-mode` class
- **Claro**: Detected via `[data-color-scheme="dark"]`
- **System preference**: Falls back to `prefers-color-scheme: dark`

---

## Troubleshooting

### Snapshots not being created automatically

1. Ensure cron is running: `drush cron`
2. Check that "Auto snapshot enabled" is on in settings
3. Verify the snapshot interval hasn't been reached recently
4. Check the watchdog log for errors: `drush watchdog:show --type=config_guardian`

### Rollback fails with "Lock could not be acquired"

Another process is modifying configuration. Wait a moment and try again, or:

```bash
drush state:delete system.cron_last
drush cron
```

### Large snapshots causing memory issues

1. Increase PHP memory limit
2. Add large configs to exclude patterns in settings
3. Consider using batch export for very large sites

### Dependency graph not loading

1. Clear Drupal cache: `drush cr`
2. Check browser console for JavaScript errors
3. Ensure D3.js is loading from CDN
4. Try a different browser

### Permission denied errors

Ensure your user role has the necessary permissions. The `administer config guardian` permission provides full access.

---

## Contributing

We welcome contributions! Here's how you can help:

1. **Report bugs** - Use the [issue queue on Drupal.org](https://www.drupal.org/project/issues/config_guardian)
2. **Suggest features** - Open a feature request in the issue queue
3. **Submit patches** - Create a patch or merge request following [Drupal contribution guidelines](https://www.drupal.org/docs/develop/git/using-git-to-contribute-to-drupal)
4. **Improve documentation** - Help make the docs better
5. **Translate** - Help translate via [localize.drupal.org](https://localize.drupal.org/)

### Development Setup

```bash
# Clone the repository
git clone https://git.drupalcode.org/project/config_guardian.git

# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit modules/contrib/config_guardian/tests

# Check coding standards
./vendor/bin/phpcs --standard=Drupal,DrupalPractice modules/contrib/config_guardian
```

### Coding Standards

This project follows all [Drupal coding standards](https://www.drupal.org/docs/develop/standards):

- Follow [Drupal PHP coding standards](https://www.drupal.org/docs/develop/standards/coding-standards)
- Use strict types: `declare(strict_types=1);`
- Write PHPDoc for all public methods
- Include tests for new features
- All code must pass PHPCS with Drupal and DrupalPractice standards

### Security

If you discover a security vulnerability, please report it via the [Drupal security team](https://www.drupal.org/drupal-security-team) following the [security advisory process](https://www.drupal.org/drupal-security-team/security-advisory-process). Do NOT create a public issue.

---

## License

This project is licensed under the **GNU General Public License v2.0 or later** (GPL-2.0-or-later).

See the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for full details.

---

## Credits

Config Guardian was created by **Andrés Moreno** ([@andresmoreno28](https://github.com/andresmoreno28)).

- Architecture and development
- UI/UX design
- Documentation

---

*Made with care for the Drupal community*
