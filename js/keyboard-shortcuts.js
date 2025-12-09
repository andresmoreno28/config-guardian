/**
 * @file
 * Keyboard shortcuts for Config Guardian.
 *
 * Provides keyboard navigation for power users.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Keyboard shortcuts manager.
   */
  const KeyboardShortcuts = {
    shortcuts: [],
    modal: null,
    isModalOpen: false,

    /**
     * Initialize keyboard shortcuts.
     */
    init: function () {
      this.registerDefaults();
      this.bindGlobalHandler();
      this.createModal();
    },

    /**
     * Register default shortcuts.
     */
    registerDefaults: function () {
      const basePath = drupalSettings.path?.baseUrl || '/';
      const modulePath = basePath + 'admin/config/development/config-guardian';

      this.shortcuts = [
        {
          key: 'd',
          description: Drupal.t('Go to Dashboard'),
          action: () => window.location.href = modulePath
        },
        {
          key: 'n',
          description: Drupal.t('Create new snapshot'),
          action: () => window.location.href = modulePath + '/snapshots/add'
        },
        {
          key: 'l',
          description: Drupal.t('View snapshots list'),
          action: () => window.location.href = modulePath + '/snapshots'
        },
        {
          key: 'a',
          description: Drupal.t('Impact analysis'),
          action: () => window.location.href = modulePath + '/analyze'
        },
        {
          key: 'y',
          description: Drupal.t('Sync overview'),
          action: () => window.location.href = modulePath + '/sync'
        },
        {
          key: 's',
          description: Drupal.t('Focus search'),
          action: () => this.focusSearch()
        },
        {
          key: '?',
          description: Drupal.t('Show keyboard shortcuts'),
          action: () => this.toggleModal()
        },
        {
          key: 'Escape',
          description: Drupal.t('Close modal/panel'),
          action: () => this.closeModals(),
          hidden: true
        }
      ];
    },

    /**
     * Register a custom shortcut.
     */
    register: function (key, description, action, hidden = false) {
      // Check for duplicates
      const existing = this.shortcuts.findIndex(s => s.key === key);
      if (existing !== -1) {
        this.shortcuts[existing] = { key, description, action, hidden };
      } else {
        this.shortcuts.push({ key, description, action, hidden });
      }
    },

    /**
     * Bind global keyboard handler.
     */
    bindGlobalHandler: function () {
      document.addEventListener('keydown', (event) => {
        // Ignore if typing in an input
        if (this.isInputFocused()) return;

        // Ignore if modifier keys are pressed (except for ?)
        if (event.ctrlKey || event.altKey || event.metaKey) return;

        const key = event.key;
        const shortcut = this.shortcuts.find(s => s.key === key);

        if (shortcut) {
          event.preventDefault();
          shortcut.action();
        }
      });
    },

    /**
     * Check if an input element is focused.
     */
    isInputFocused: function () {
      const activeElement = document.activeElement;
      const tagName = activeElement?.tagName?.toLowerCase();
      return ['input', 'textarea', 'select'].includes(tagName) ||
             activeElement?.isContentEditable;
    },

    /**
     * Focus the search input.
     */
    focusSearch: function () {
      const searchInputs = document.querySelectorAll(
        '.cg-search-input, .search-input, input[type="search"], input[name="search"]'
      );

      if (searchInputs.length > 0) {
        searchInputs[0].focus();
        searchInputs[0].select();
      }
    },

    /**
     * Close any open modals or panels.
     */
    closeModals: function () {
      // Close shortcuts modal
      if (this.isModalOpen) {
        this.toggleModal();
        return;
      }

      // Close info panels
      const infoPanels = document.querySelectorAll('.graph-info-panel, .cg-info-panel');
      infoPanels.forEach(panel => panel.remove());

      // Close any Drupal dialogs
      const dialogs = document.querySelectorAll('.ui-dialog');
      dialogs.forEach(dialog => {
        const closeBtn = dialog.querySelector('.ui-dialog-titlebar-close');
        if (closeBtn) closeBtn.click();
      });
    },

    /**
     * Create the shortcuts modal.
     */
    createModal: function () {
      this.modal = document.createElement('div');
      this.modal.className = 'cg-shortcuts-modal';
      this.modal.hidden = true;
      this.modal.setAttribute('role', 'dialog');
      this.modal.setAttribute('aria-labelledby', 'shortcuts-title');
      this.modal.setAttribute('aria-modal', 'true');

      const visibleShortcuts = this.shortcuts.filter(s => !s.hidden);

      this.modal.innerHTML = `
        <div class="cg-shortcuts-content">
          <div class="cg-shortcuts-header">
            <h3 id="shortcuts-title">${Drupal.t('Keyboard Shortcuts')}</h3>
            <button type="button" class="cg-shortcuts-close" aria-label="${Drupal.t('Close')}">&times;</button>
          </div>
          <div class="cg-shortcuts-list">
            ${visibleShortcuts.map(s => `
              <div class="cg-shortcut-item">
                <span class="cg-shortcut-description">${s.description}</span>
                <kbd class="cg-shortcut-key">${this.formatKey(s.key)}</kbd>
              </div>
            `).join('')}
          </div>
        </div>
      `;

      document.body.appendChild(this.modal);

      // Bind close handlers
      this.modal.querySelector('.cg-shortcuts-close').addEventListener('click', () => {
        this.toggleModal();
      });

      this.modal.addEventListener('click', (event) => {
        if (event.target === this.modal) {
          this.toggleModal();
        }
      });
    },

    /**
     * Format key for display.
     */
    formatKey: function (key) {
      const keyMap = {
        'Escape': 'Esc',
        ' ': 'Space',
        'ArrowUp': '↑',
        'ArrowDown': '↓',
        'ArrowLeft': '←',
        'ArrowRight': '→'
      };
      return keyMap[key] || key.toUpperCase();
    },

    /**
     * Toggle the shortcuts modal.
     */
    toggleModal: function () {
      this.isModalOpen = !this.isModalOpen;
      this.modal.hidden = !this.isModalOpen;

      if (this.isModalOpen) {
        // Focus the close button for accessibility
        this.modal.querySelector('.cg-shortcuts-close').focus();
        // Trap focus within modal
        this.trapFocus();
      }
    },

    /**
     * Trap focus within the modal.
     */
    trapFocus: function () {
      const focusableElements = this.modal.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];

      const handleTab = (event) => {
        if (!this.isModalOpen) {
          document.removeEventListener('keydown', handleTab);
          return;
        }

        if (event.key === 'Tab') {
          if (event.shiftKey && document.activeElement === firstElement) {
            event.preventDefault();
            lastElement.focus();
          } else if (!event.shiftKey && document.activeElement === lastElement) {
            event.preventDefault();
            firstElement.focus();
          }
        }
      };

      document.addEventListener('keydown', handleTab);
    }
  };

  /**
   * Drupal behavior.
   */
  Drupal.behaviors.configGuardianKeyboardShortcuts = {
    attach: function (context, settings) {
      once('cg-keyboard-shortcuts', 'body', context).forEach(function () {
        KeyboardShortcuts.init();
      });
    }
  };

  // Expose to global Drupal object
  Drupal.configGuardian = Drupal.configGuardian || {};
  Drupal.configGuardian.shortcuts = KeyboardShortcuts;

})(Drupal, drupalSettings, once);
