/**
 * @file
 * Config Guardian - Configuration Sync functionality.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Config Sync behaviors.
   */
  Drupal.behaviors.configGuardianSync = {
    attach: function (context, settings) {
      // Initialize search functionality.
      once('cg-config-search', '.cg-config-search', context).forEach(function (searchInput) {
        searchInput.addEventListener('input', function (e) {
          Drupal.configGuardianSync.filterConfigs(e.target.value, searchInput);
        });

        // Clear search on escape.
        searchInput.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            searchInput.value = '';
            Drupal.configGuardianSync.filterConfigs('', searchInput);
          }
        });
      });

      // Initialize collapsible sections.
      once('cg-details-memory', 'details.cg-config-group', context).forEach(function (details) {
        // Remember open/closed state.
        var storageKey = 'cg-details-' + details.querySelector('summary').textContent.trim();
        var savedState = sessionStorage.getItem(storageKey);

        if (savedState === 'open') {
          details.setAttribute('open', '');
        }

        details.addEventListener('toggle', function () {
          sessionStorage.setItem(storageKey, details.open ? 'open' : 'closed');
        });
      });

      // Initialize risk badge tooltips.
      once('cg-risk-tooltip', '.cg-risk-badge', context).forEach(function (badge) {
        var level = badge.textContent.trim().toLowerCase();
        var tooltips = {
          'low': Drupal.t('Low risk - Safe to apply'),
          'medium': Drupal.t('Medium risk - Review recommended'),
          'high': Drupal.t('High risk - Careful review required'),
          'critical': Drupal.t('Critical risk - May cause significant changes')
        };

        if (tooltips[level]) {
          badge.setAttribute('title', tooltips[level]);
        }
      });

      // Highlight code elements on hover.
      once('cg-code-highlight', '.cg-config-list code, .cg-changes-section code', context).forEach(function (code) {
        code.addEventListener('mouseenter', function () {
          code.style.backgroundColor = 'var(--cg-primary)';
          code.style.color = 'white';
        });

        code.addEventListener('mouseleave', function () {
          code.style.backgroundColor = '';
          code.style.color = '';
        });
      });
    }
  };

  /**
   * Config Sync utility functions.
   */
  Drupal.configGuardianSync = {
    /**
     * Filters configuration list based on search term.
     *
     * @param {string} searchTerm
     *   The search term.
     * @param {HTMLElement} searchInput
     *   The search input element.
     */
    filterConfigs: function (searchTerm, searchInput) {
      var targetId = searchInput.getAttribute('data-search-target') || 'config-groups';
      var container = document.getElementById(targetId);

      if (!container) {
        return;
      }

      var term = searchTerm.toLowerCase().trim();
      var groups = container.querySelectorAll('.cg-config-group, details');
      var totalVisible = 0;

      groups.forEach(function (group) {
        var items = group.querySelectorAll('li, .cg-config-item');
        var visibleItems = 0;

        items.forEach(function (item) {
          var text = item.textContent.toLowerCase();
          var matches = !term || text.includes(term);

          item.style.display = matches ? '' : 'none';

          if (matches) {
            visibleItems++;
            totalVisible++;

            // Highlight matching text.
            if (term) {
              Drupal.configGuardianSync.highlightText(item, term);
            } else {
              Drupal.configGuardianSync.removeHighlight(item);
            }
          }
        });

        // Show/hide group based on whether it has visible items.
        group.style.display = visibleItems > 0 ? '' : 'none';

        // Auto-expand groups with matches when searching.
        if (term && visibleItems > 0 && group.tagName === 'DETAILS') {
          group.setAttribute('open', '');
        }

        // Update group summary count if present.
        var summary = group.querySelector('summary');
        if (summary && term) {
          var originalText = summary.getAttribute('data-original-text');
          if (!originalText) {
            originalText = summary.textContent;
            summary.setAttribute('data-original-text', originalText);
          }

          summary.textContent = originalText.replace(/\(\d+\)/, '(' + visibleItems + ')');
        } else if (summary && !term) {
          var original = summary.getAttribute('data-original-text');
          if (original) {
            summary.textContent = original;
          }
        }
      });

      // Show no results message.
      var noResults = container.querySelector('.cg-no-results');
      if (term && totalVisible === 0) {
        if (!noResults) {
          noResults = document.createElement('div');
          noResults.className = 'cg-no-results';
          noResults.style.padding = '1rem';
          noResults.style.textAlign = 'center';
          noResults.style.color = 'var(--cg-text-muted)';
          noResults.textContent = Drupal.t('No configurations found matching "@term"', {'@term': searchTerm});
          container.appendChild(noResults);
        } else {
          noResults.textContent = Drupal.t('No configurations found matching "@term"', {'@term': searchTerm});
          noResults.style.display = '';
        }
      } else if (noResults) {
        noResults.style.display = 'none';
      }
    },

    /**
     * Highlights matching text in an element.
     *
     * @param {HTMLElement} element
     *   The element to highlight text in.
     * @param {string} term
     *   The term to highlight.
     */
    highlightText: function (element, term) {
      var codeElement = element.querySelector('code');
      if (!codeElement) {
        return;
      }

      var originalText = codeElement.getAttribute('data-original-text') || codeElement.textContent;
      codeElement.setAttribute('data-original-text', originalText);

      var regex = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
      codeElement.innerHTML = originalText.replace(regex, '<mark style="background: #ffc107; padding: 0 2px;">$1</mark>');
    },

    /**
     * Removes highlight from an element.
     *
     * @param {HTMLElement} element
     *   The element to remove highlight from.
     */
    removeHighlight: function (element) {
      var codeElement = element.querySelector('code');
      if (!codeElement) {
        return;
      }

      var originalText = codeElement.getAttribute('data-original-text');
      if (originalText) {
        codeElement.textContent = originalText;
      }
    },

    /**
     * Updates progress display during batch operations.
     *
     * @param {number} current
     *   Current item number.
     * @param {number} total
     *   Total number of items.
     * @param {string} currentConfig
     *   Current configuration name being processed.
     */
    updateProgress: function (current, total, currentConfig) {
      var progressDescription = document.querySelector('.progress__description');
      if (progressDescription && currentConfig) {
        progressDescription.innerHTML = Drupal.t(
          'Processing @current of @total...<br><strong>Current:</strong> <code>@config</code>',
          {
            '@current': current,
            '@total': total,
            '@config': currentConfig
          }
        );
      }
    }
  };

})(Drupal, drupalSettings, once);
