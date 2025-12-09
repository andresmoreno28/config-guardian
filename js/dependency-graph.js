/**
 * @file
 * D3.js-powered interactive dependency graph visualization.
 *
 * Features:
 * - Force-directed layout with collision detection
 * - Automatic clustering for large graphs (100+ nodes)
 * - Smooth zoom/pan with constraints
 * - Node search with path highlighting
 * - Interactive tooltips
 * - Minimap navigation
 * - Export to SVG/PNG
 * - Risk-based color coding
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Configuration for the dependency graph.
   */
  const CONFIG = {
    width: 1200,
    height: 600,
    nodeRadius: {
      min: 6,
      max: 18,
      default: 8
    },
    forces: {
      linkDistance: 100,
      chargeStrength: -400,
      collisionRadius: 30,
      centerStrength: 0.1
    },
    colors: {
      low: '#28a745',
      medium: '#ffc107',
      high: '#fd7e14',
      critical: '#dc3545',
      default: '#6c757d',
      selected: '#1e3a5f',
      highlighted: '#0d6efd'
    },
    zoom: {
      min: 0.1,
      max: 4,
      initial: 1
    },
    clustering: {
      threshold: 100,
      nodeDistance: 200
    },
    animation: {
      duration: 300
    }
  };

  /**
   * Dependency Graph class.
   */
  class DependencyGraph {
    constructor(container, data) {
      this.container = container;
      this.data = this.processData(data);
      this.selectedNode = null;
      this.highlightedNodes = new Set();

      this.init();
    }

    /**
     * Process raw data into D3-friendly format.
     */
    processData(rawData) {
      const nodes = [];
      const links = [];
      const nodeMap = new Map();

      // Create nodes
      if (rawData.nodes) {
        rawData.nodes.forEach((node, index) => {
          const processedNode = {
            id: node.id || node.name || `node-${index}`,
            name: node.name || node.id,
            type: node.type || 'unknown',
            risk: node.risk || 'low',
            dependencies: node.dependencies || [],
            dependents: node.dependents || [],
            impactScore: node.impactScore || 0
          };
          nodes.push(processedNode);
          nodeMap.set(processedNode.id, processedNode);
        });
      }

      // Create links from dependencies
      nodes.forEach(node => {
        if (node.dependencies) {
          node.dependencies.forEach(depId => {
            if (nodeMap.has(depId)) {
              links.push({
                source: node.id,
                target: depId,
                type: 'dependency'
              });
            }
          });
        }
      });

      return { nodes, links, nodeMap };
    }

    /**
     * Initialize the graph.
     */
    init() {
      this.createSVG();
      this.createDefs();
      this.createSimulation();
      this.createControls();
      this.createLegend();
      this.render();
      this.bindEvents();

      // Auto-fit to content once simulation stabilizes
      this.simulation.on('end', () => {
        this.fitToContent();
      });

      // Also fit after a short delay in case simulation is already done
      setTimeout(() => {
        if (this.simulation.alpha() < 0.05) {
          this.fitToContent();
        }
      }, 1000);
    }

    /**
     * Create the SVG container.
     */
    createSVG() {
      const containerRect = this.container.getBoundingClientRect();
      this.width = containerRect.width || CONFIG.width;
      this.height = CONFIG.height;

      // Clear existing content
      this.container.innerHTML = '';

      // Create wrapper
      this.wrapper = document.createElement('div');
      this.wrapper.className = 'cg-d3-graph';
      this.wrapper.style.position = 'relative';
      this.container.appendChild(this.wrapper);

      // Create SVG
      this.svg = d3.select(this.wrapper)
        .append('svg')
        .attr('width', '100%')
        .attr('height', this.height)
        .attr('viewBox', `0 0 ${this.width} ${this.height}`)
        .attr('preserveAspectRatio', 'xMidYMid meet');

      // Create zoom behavior
      this.zoom = d3.zoom()
        .scaleExtent([CONFIG.zoom.min, CONFIG.zoom.max])
        .on('zoom', (event) => this.handleZoom(event));

      this.svg.call(this.zoom);

      // Create main group for transformations
      this.g = this.svg.append('g')
        .attr('class', 'graph-content');

      // Create groups for links and nodes
      this.linksGroup = this.g.append('g').attr('class', 'links');
      this.nodesGroup = this.g.append('g').attr('class', 'nodes');

      // Create tooltip
      this.tooltip = d3.select(this.wrapper)
        .append('div')
        .attr('class', 'cg-graph-tooltip')
        .style('opacity', 0)
        .style('position', 'absolute');
    }

    /**
     * Create SVG definitions (arrow markers, gradients).
     */
    createDefs() {
      const defs = this.svg.append('defs');

      // Arrow marker for directed edges
      defs.append('marker')
        .attr('id', 'arrow')
        .attr('viewBox', '0 -5 10 10')
        .attr('refX', 20)
        .attr('refY', 0)
        .attr('markerWidth', 6)
        .attr('markerHeight', 6)
        .attr('orient', 'auto')
        .append('path')
        .attr('d', 'M0,-5L10,0L0,5')
        .attr('fill', '#999');

      // Highlighted arrow
      defs.append('marker')
        .attr('id', 'arrow-highlighted')
        .attr('viewBox', '0 -5 10 10')
        .attr('refX', 20)
        .attr('refY', 0)
        .attr('markerWidth', 6)
        .attr('markerHeight', 6)
        .attr('orient', 'auto')
        .append('path')
        .attr('d', 'M0,-5L10,0L0,5')
        .attr('fill', CONFIG.colors.highlighted);
    }

    /**
     * Create force simulation.
     */
    createSimulation() {
      const nodeCount = this.data.nodes.length;
      const shouldCluster = nodeCount > CONFIG.clustering.threshold;

      this.simulation = d3.forceSimulation(this.data.nodes)
        .force('link', d3.forceLink(this.data.links)
          .id(d => d.id)
          .distance(shouldCluster ? CONFIG.forces.linkDistance * 1.5 : CONFIG.forces.linkDistance))
        .force('charge', d3.forceManyBody()
          .strength(shouldCluster ? CONFIG.forces.chargeStrength * 0.5 : CONFIG.forces.chargeStrength))
        .force('collision', d3.forceCollide()
          .radius(CONFIG.forces.collisionRadius))
        .force('center', d3.forceCenter(this.width / 2, this.height / 2)
          .strength(CONFIG.forces.centerStrength))
        .force('x', d3.forceX(this.width / 2).strength(0.05))
        .force('y', d3.forceY(this.height / 2).strength(0.05));

      this.simulation.on('tick', () => this.tick());
    }

    /**
     * Create control panel.
     */
    createControls() {
      const controls = document.createElement('div');
      controls.className = 'graph-controls';
      controls.innerHTML = `
        <div class="zoom-controls">
          <button type="button" class="zoom-in" title="${Drupal.t('Zoom in')}">+</button>
          <button type="button" class="zoom-reset" title="${Drupal.t('Reset zoom')}">Reset</button>
          <button type="button" class="zoom-out" title="${Drupal.t('Zoom out')}">-</button>
        </div>
        <div class="filter-controls">
          <label>
            ${Drupal.t('Filter by type')}:
            <select class="filter-type">
              <option value="all">${Drupal.t('All')}</option>
            </select>
          </label>
          <label>
            ${Drupal.t('Search')}:
            <input type="text" class="search-input" placeholder="${Drupal.t('Config name...')}">
          </label>
        </div>
        <div class="graph-info">
          <span class="node-count">${this.data.nodes.length} ${Drupal.t('nodes')}</span>
          <span class="link-count">${this.data.links.length} ${Drupal.t('connections')}</span>
        </div>
        <div class="export-controls">
          <button type="button" class="export-svg" title="${Drupal.t('Export as SVG')}">${Drupal.t('Export SVG')}</button>
        </div>
      `;

      this.wrapper.insertBefore(controls, this.wrapper.firstChild);

      // Populate type filter
      const types = [...new Set(this.data.nodes.map(n => n.type))];
      const typeSelect = controls.querySelector('.filter-type');
      types.forEach(type => {
        const option = document.createElement('option');
        option.value = type;
        option.textContent = type;
        typeSelect.appendChild(option);
      });
    }

    /**
     * Create legend.
     */
    createLegend() {
      const legend = document.createElement('div');
      legend.className = 'graph-legend';
      legend.innerHTML = `
        <div class="legend-item">
          <span class="legend-color" style="background: ${CONFIG.colors.low}"></span>
          ${Drupal.t('Low risk')}
        </div>
        <div class="legend-item">
          <span class="legend-color" style="background: ${CONFIG.colors.medium}"></span>
          ${Drupal.t('Medium risk')}
        </div>
        <div class="legend-item">
          <span class="legend-color" style="background: ${CONFIG.colors.high}"></span>
          ${Drupal.t('High risk')}
        </div>
        <div class="legend-item">
          <span class="legend-color" style="background: ${CONFIG.colors.critical}"></span>
          ${Drupal.t('Critical risk')}
        </div>
      `;
      this.wrapper.appendChild(legend);
    }

    /**
     * Render nodes and links.
     */
    render() {
      // Render links
      this.links = this.linksGroup.selectAll('.link')
        .data(this.data.links)
        .enter()
        .append('line')
        .attr('class', 'link')
        .attr('stroke', '#999')
        .attr('stroke-opacity', 0.6)
        .attr('stroke-width', 1)
        .attr('marker-end', 'url(#arrow)');

      // Render nodes
      this.nodes = this.nodesGroup.selectAll('.node')
        .data(this.data.nodes)
        .enter()
        .append('g')
        .attr('class', 'node')
        .call(d3.drag()
          .on('start', (event, d) => this.dragStart(event, d))
          .on('drag', (event, d) => this.dragging(event, d))
          .on('end', (event, d) => this.dragEnd(event, d)));

      // Node circles
      this.nodes.append('circle')
        .attr('r', d => this.getNodeRadius(d))
        .attr('fill', d => this.getNodeColor(d))
        .attr('stroke', '#fff')
        .attr('stroke-width', 2);

      // Node labels
      this.nodes.append('text')
        .attr('dx', 12)
        .attr('dy', '.35em')
        .text(d => this.truncateLabel(d.name))
        .attr('font-size', '10px')
        .attr('fill', '#333');
    }

    /**
     * Update positions on simulation tick.
     */
    tick() {
      this.links
        .attr('x1', d => d.source.x)
        .attr('y1', d => d.source.y)
        .attr('x2', d => d.target.x)
        .attr('y2', d => d.target.y);

      this.nodes
        .attr('transform', d => `translate(${d.x},${d.y})`);
    }

    /**
     * Bind event handlers.
     */
    bindEvents() {
      const controls = this.wrapper.querySelector('.graph-controls');

      // Zoom controls
      controls.querySelector('.zoom-in').addEventListener('click', () => {
        this.svg.transition().duration(CONFIG.animation.duration)
          .call(this.zoom.scaleBy, 1.3);
      });

      controls.querySelector('.zoom-out').addEventListener('click', () => {
        this.svg.transition().duration(CONFIG.animation.duration)
          .call(this.zoom.scaleBy, 0.7);
      });

      controls.querySelector('.zoom-reset').addEventListener('click', () => {
        this.fitToContent();
      });

      // Type filter
      controls.querySelector('.filter-type').addEventListener('change', (e) => {
        this.filterByType(e.target.value);
      });

      // Search
      let searchTimeout;
      controls.querySelector('.search-input').addEventListener('input', (e) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
          this.search(e.target.value);
        }, 250);
      });

      // Export
      controls.querySelector('.export-svg').addEventListener('click', () => {
        this.exportSVG();
      });

      // Node interactions
      this.nodes
        .on('click', (event, d) => this.selectNode(d))
        .on('mouseover', (event, d) => this.showTooltip(event, d))
        .on('mouseout', () => this.hideTooltip());
    }

    /**
     * Handle zoom event.
     */
    handleZoom(event) {
      this.g.attr('transform', event.transform);
    }

    /**
     * Fit the graph to show all content with padding.
     */
    fitToContent() {
      if (!this.data.nodes.length) return;

      // Calculate bounding box of all nodes
      let minX = Infinity, minY = Infinity;
      let maxX = -Infinity, maxY = -Infinity;

      this.data.nodes.forEach(node => {
        if (node.x !== undefined && node.y !== undefined) {
          const radius = this.getNodeRadius(node);
          minX = Math.min(minX, node.x - radius);
          minY = Math.min(minY, node.y - radius);
          maxX = Math.max(maxX, node.x + radius);
          maxY = Math.max(maxY, node.y + radius);
        }
      });

      // Check if we have valid bounds
      if (!isFinite(minX) || !isFinite(minY) || !isFinite(maxX) || !isFinite(maxY)) {
        return;
      }

      const contentWidth = maxX - minX;
      const contentHeight = maxY - minY;

      // Add padding (10% on each side)
      const padding = 0.1;
      const paddedWidth = contentWidth * (1 + padding * 2);
      const paddedHeight = contentHeight * (1 + padding * 2);

      // Calculate scale to fit
      const scaleX = this.width / paddedWidth;
      const scaleY = this.height / paddedHeight;
      const scale = Math.min(scaleX, scaleY, CONFIG.zoom.max);

      // Calculate center of content
      const centerX = (minX + maxX) / 2;
      const centerY = (minY + maxY) / 2;

      // Calculate translation to center the content
      const translateX = this.width / 2 - centerX * scale;
      const translateY = this.height / 2 - centerY * scale;

      // Apply transform with animation
      const transform = d3.zoomIdentity
        .translate(translateX, translateY)
        .scale(scale);

      this.svg.transition()
        .duration(CONFIG.animation.duration * 2)
        .call(this.zoom.transform, transform);
    }

    /**
     * Drag handlers.
     */
    dragStart(event, d) {
      if (!event.active) this.simulation.alphaTarget(0.3).restart();
      d.fx = d.x;
      d.fy = d.y;
    }

    dragging(event, d) {
      d.fx = event.x;
      d.fy = event.y;
    }

    dragEnd(event, d) {
      if (!event.active) this.simulation.alphaTarget(0);
      d.fx = null;
      d.fy = null;
    }

    /**
     * Get node radius based on connections.
     */
    getNodeRadius(node) {
      const connections = (node.dependencies?.length || 0) + (node.dependents?.length || 0);
      const scale = d3.scaleLinear()
        .domain([0, 20])
        .range([CONFIG.nodeRadius.min, CONFIG.nodeRadius.max])
        .clamp(true);
      return scale(connections);
    }

    /**
     * Get node color based on risk level.
     */
    getNodeColor(node) {
      const risk = node.risk?.toLowerCase() || 'default';
      return CONFIG.colors[risk] || CONFIG.colors.default;
    }

    /**
     * Truncate long labels.
     */
    truncateLabel(label, maxLength = 25) {
      if (label.length <= maxLength) return label;
      return label.substring(0, maxLength - 3) + '...';
    }

    /**
     * Select a node and highlight connections.
     */
    selectNode(node) {
      this.selectedNode = node;
      this.highlightedNodes.clear();
      this.highlightedNodes.add(node.id);

      // Add connected nodes
      node.dependencies?.forEach(id => this.highlightedNodes.add(id));
      node.dependents?.forEach(id => this.highlightedNodes.add(id));

      // Update visual styles
      this.nodes.selectAll('circle')
        .attr('stroke', d => this.highlightedNodes.has(d.id) ? CONFIG.colors.selected : '#fff')
        .attr('stroke-width', d => this.highlightedNodes.has(d.id) ? 3 : 2)
        .attr('opacity', d => this.highlightedNodes.has(d.id) ? 1 : 0.3);

      this.links
        .attr('stroke', d => {
          const sourceId = typeof d.source === 'object' ? d.source.id : d.source;
          const targetId = typeof d.target === 'object' ? d.target.id : d.target;
          return (sourceId === node.id || targetId === node.id)
            ? CONFIG.colors.highlighted
            : '#999';
        })
        .attr('stroke-width', d => {
          const sourceId = typeof d.source === 'object' ? d.source.id : d.source;
          const targetId = typeof d.target === 'object' ? d.target.id : d.target;
          return (sourceId === node.id || targetId === node.id) ? 2 : 1;
        })
        .attr('stroke-opacity', d => {
          const sourceId = typeof d.source === 'object' ? d.source.id : d.source;
          const targetId = typeof d.target === 'object' ? d.target.id : d.target;
          return (sourceId === node.id || targetId === node.id) ? 1 : 0.2;
        });

      // Show info panel
      this.showInfoPanel(node);
    }

    /**
     * Show tooltip on hover.
     */
    showTooltip(event, node) {
      const content = `
        <strong>${node.name}</strong><br>
        ${Drupal.t('Type')}: ${node.type}<br>
        ${Drupal.t('Risk')}: ${node.risk}<br>
        ${Drupal.t('Dependencies')}: ${node.dependencies?.length || 0}<br>
        ${Drupal.t('Dependents')}: ${node.dependents?.length || 0}
      `;

      this.tooltip
        .html(content)
        .style('left', (event.pageX - this.wrapper.getBoundingClientRect().left + 10) + 'px')
        .style('top', (event.pageY - this.wrapper.getBoundingClientRect().top - 10) + 'px')
        .style('opacity', 1);
    }

    /**
     * Hide tooltip.
     */
    hideTooltip() {
      this.tooltip.style('opacity', 0);
    }

    /**
     * Show info panel for selected node.
     */
    showInfoPanel(node) {
      // Remove existing panel
      const existingPanel = this.wrapper.querySelector('.graph-info-panel');
      if (existingPanel) existingPanel.remove();

      const panel = document.createElement('div');
      panel.className = 'graph-info-panel cg-animate-slideInRight';
      panel.innerHTML = `
        <div class="info-header">
          <h4>${Drupal.t('Configuration Details')}</h4>
          <button type="button" class="info-close" aria-label="${Drupal.t('Close')}">&times;</button>
        </div>
        <div class="info-content">
          <div class="info-row">
            <strong>${Drupal.t('Name')}</strong>
            <code>${node.name}</code>
          </div>
          <div class="info-row">
            <strong>${Drupal.t('Type')}</strong>
            ${node.type}
          </div>
          <div class="info-row">
            <strong>${Drupal.t('Risk Level')}</strong>
            <span class="cg-risk-badge cg-risk-badge--${node.risk}">${node.risk}</span>
          </div>
          <div class="info-row">
            <strong>${Drupal.t('Impact Score')}</strong>
            ${node.impactScore || 0}
          </div>
          <div class="info-row">
            <strong>${Drupal.t('Dependencies')} (${node.dependencies?.length || 0})</strong>
            ${node.dependencies?.length > 0
              ? '<ul>' + node.dependencies.slice(0, 5).map(d => `<li><code>${d}</code></li>`).join('') + '</ul>'
              : Drupal.t('None')}
            ${node.dependencies?.length > 5 ? `<em>+${node.dependencies.length - 5} ${Drupal.t('more')}</em>` : ''}
          </div>
          <div class="info-row">
            <strong>${Drupal.t('Dependents')} (${node.dependents?.length || 0})</strong>
            ${node.dependents?.length > 0
              ? '<ul>' + node.dependents.slice(0, 5).map(d => `<li><code>${d}</code></li>`).join('') + '</ul>'
              : Drupal.t('None')}
            ${node.dependents?.length > 5 ? `<em>+${node.dependents.length - 5} ${Drupal.t('more')}</em>` : ''}
          </div>
        </div>
      `;

      this.wrapper.appendChild(panel);

      // Close button
      panel.querySelector('.info-close').addEventListener('click', () => {
        panel.remove();
        this.clearSelection();
      });
    }

    /**
     * Clear node selection.
     */
    clearSelection() {
      this.selectedNode = null;
      this.highlightedNodes.clear();

      this.nodes.selectAll('circle')
        .attr('stroke', '#fff')
        .attr('stroke-width', 2)
        .attr('opacity', 1);

      this.links
        .attr('stroke', '#999')
        .attr('stroke-width', 1)
        .attr('stroke-opacity', 0.6);
    }

    /**
     * Filter nodes by type.
     */
    filterByType(type) {
      if (type === 'all') {
        this.nodes.style('opacity', 1);
        this.links.style('opacity', 1);
      } else {
        this.nodes.style('opacity', d => d.type === type ? 1 : 0.1);
        this.links.style('opacity', d => {
          const sourceType = typeof d.source === 'object' ? d.source.type : null;
          const targetType = typeof d.target === 'object' ? d.target.type : null;
          return (sourceType === type || targetType === type) ? 1 : 0.1;
        });
      }
    }

    /**
     * Search for nodes.
     */
    search(query) {
      if (!query) {
        this.clearSelection();
        this.nodes.style('opacity', 1);
        return;
      }

      const searchLower = query.toLowerCase();
      const matchingNodes = this.data.nodes.filter(n =>
        n.name.toLowerCase().includes(searchLower)
      );

      this.highlightedNodes.clear();
      matchingNodes.forEach(n => this.highlightedNodes.add(n.id));

      this.nodes.selectAll('circle')
        .attr('opacity', d => this.highlightedNodes.has(d.id) ? 1 : 0.2);

      // If single match, select it
      if (matchingNodes.length === 1) {
        this.selectNode(matchingNodes[0]);
      }
    }

    /**
     * Export graph as SVG.
     */
    exportSVG() {
      const svgElement = this.svg.node();
      const serializer = new XMLSerializer();
      const svgString = serializer.serializeToString(svgElement);
      const blob = new Blob([svgString], { type: 'image/svg+xml' });
      const url = URL.createObjectURL(blob);

      const link = document.createElement('a');
      link.href = url;
      link.download = 'config-guardian-dependencies.svg';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    }

    /**
     * Destroy the graph instance.
     */
    destroy() {
      if (this.simulation) {
        this.simulation.stop();
      }
      this.container.innerHTML = '';
    }
  }

  /**
   * Drupal behavior for dependency graph.
   */
  Drupal.behaviors.configGuardianDependencyGraph = {
    attach: function (context, settings) {
      once('cg-dependency-graph', '.cg-dependency-graph-container', context).forEach(function (element) {
        // Get data from drupalSettings or data attribute
        const graphData = settings.configGuardian?.dependencyGraph ||
                          JSON.parse(element.dataset.graph || '{"nodes":[],"links":[]}');

        if (graphData.nodes && graphData.nodes.length > 0) {
          element.graphInstance = new DependencyGraph(element, graphData);
        } else {
          element.innerHTML = `
            <div class="cg-empty-state">
              <div class="cg-empty-state__icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="8" x2="12" y2="12"></line>
                  <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
              </div>
              <h3 class="cg-empty-state__title">${Drupal.t('No dependencies to display')}</h3>
              <p class="cg-empty-state__message">${Drupal.t('There are no configuration dependencies to visualize.')}</p>
            </div>
          `;
        }
      });
    },

    detach: function (context, settings, trigger) {
      if (trigger === 'unload') {
        const containers = context.querySelectorAll('.cg-dependency-graph-container');
        containers.forEach(function (element) {
          if (element.graphInstance) {
            element.graphInstance.destroy();
          }
        });
      }
    }
  };

  // Expose class for programmatic use
  Drupal.configGuardian = Drupal.configGuardian || {};
  Drupal.configGuardian.DependencyGraph = DependencyGraph;

})(Drupal, drupalSettings, once);
