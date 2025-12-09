/**
 * @file
 * Toast notification system for Config Guardian.
 *
 * Provides a non-intrusive notification system for user feedback.
 * Supports success, error, warning, and info message types.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Notification manager.
   */
  const NotificationManager = {
    container: null,
    notifications: [],
    defaultDuration: 5000,
    maxNotifications: 5,

    /**
     * Initialize the notification system.
     */
    init: function () {
      if (this.container) return;

      this.container = document.createElement('div');
      this.container.className = 'cg-toast-container';
      this.container.setAttribute('role', 'alert');
      this.container.setAttribute('aria-live', 'polite');
      this.container.setAttribute('aria-atomic', 'true');
      document.body.appendChild(this.container);
    },

    /**
     * Show a notification.
     *
     * @param {Object} options - Notification options
     * @param {string} options.type - Type: success, error, warning, info
     * @param {string} options.title - Notification title
     * @param {string} options.message - Notification message
     * @param {number} options.duration - Auto-dismiss time in ms (0 = no auto-dismiss)
     * @param {boolean} options.dismissible - Show close button
     */
    show: function (options) {
      this.init();

      const defaults = {
        type: 'info',
        title: '',
        message: '',
        duration: this.defaultDuration,
        dismissible: true
      };

      const settings = { ...defaults, ...options };
      const id = 'toast-' + Date.now();

      // Remove oldest if at max
      if (this.notifications.length >= this.maxNotifications) {
        this.dismiss(this.notifications[0].id);
      }

      // Create toast element
      const toast = document.createElement('div');
      toast.id = id;
      toast.className = `cg-toast cg-toast--${settings.type}`;
      toast.innerHTML = this.getToastHTML(settings);

      // Add to DOM
      this.container.appendChild(toast);
      this.notifications.push({ id, element: toast, timeout: null });

      // Bind close button
      if (settings.dismissible) {
        const closeBtn = toast.querySelector('.cg-toast__close');
        if (closeBtn) {
          closeBtn.addEventListener('click', () => this.dismiss(id));
        }
      }

      // Auto-dismiss
      if (settings.duration > 0) {
        const timeout = setTimeout(() => this.dismiss(id), settings.duration);
        const notification = this.notifications.find(n => n.id === id);
        if (notification) notification.timeout = timeout;
      }

      return id;
    },

    /**
     * Generate toast HTML.
     */
    getToastHTML: function (settings) {
      const icons = {
        success: '<svg class="cg-toast__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>',
        error: '<svg class="cg-toast__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
        warning: '<svg class="cg-toast__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
        info: '<svg class="cg-toast__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
      };

      return `
        ${icons[settings.type] || icons.info}
        <div class="cg-toast__content">
          ${settings.title ? `<div class="cg-toast__title">${settings.title}</div>` : ''}
          ${settings.message ? `<div class="cg-toast__message">${settings.message}</div>` : ''}
        </div>
        ${settings.dismissible ? `
          <button type="button" class="cg-toast__close" aria-label="${Drupal.t('Close')}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        ` : ''}
      `;
    },

    /**
     * Dismiss a notification.
     */
    dismiss: function (id) {
      const index = this.notifications.findIndex(n => n.id === id);
      if (index === -1) return;

      const notification = this.notifications[index];

      // Clear timeout
      if (notification.timeout) {
        clearTimeout(notification.timeout);
      }

      // Animate out
      notification.element.classList.add('cg-toast--exiting');

      // Remove after animation
      setTimeout(() => {
        if (notification.element.parentNode) {
          notification.element.parentNode.removeChild(notification.element);
        }
        this.notifications.splice(index, 1);
      }, 200);
    },

    /**
     * Dismiss all notifications.
     */
    dismissAll: function () {
      [...this.notifications].forEach(n => this.dismiss(n.id));
    },

    // Convenience methods
    success: function (title, message, duration) {
      return this.show({ type: 'success', title, message, duration });
    },

    error: function (title, message, duration) {
      return this.show({ type: 'error', title, message, duration: duration || 0 });
    },

    warning: function (title, message, duration) {
      return this.show({ type: 'warning', title, message, duration });
    },

    info: function (title, message, duration) {
      return this.show({ type: 'info', title, message, duration });
    }
  };

  /**
   * Drupal behavior to integrate notifications with Drupal messages.
   */
  Drupal.behaviors.configGuardianNotifications = {
    attach: function (context, settings) {
      // Initialize notification manager
      NotificationManager.init();

      // Convert Drupal status messages to toasts (optional enhancement)
      once('cg-messages', '.messages--config-guardian', context).forEach(function (element) {
        const type = element.classList.contains('messages--error') ? 'error' :
                     element.classList.contains('messages--warning') ? 'warning' :
                     element.classList.contains('messages--status') ? 'success' : 'info';

        const message = element.textContent.trim();
        if (message) {
          NotificationManager.show({
            type: type,
            message: message
          });

          // Optionally hide the original message
          element.style.display = 'none';
        }
      });
    }
  };

  // Expose to global Drupal object
  Drupal.configGuardian = Drupal.configGuardian || {};
  Drupal.configGuardian.notify = NotificationManager;

})(Drupal, once);
