/**
 * @file
 * Config Guardian Interactive Dependency Graph.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.configGuardianDashboard = {
    attach: function (context, settings) {
      once('config-guardian-dashboard', '.config-guardian-dependency-graph', context).forEach(function (container) {
        if (settings.configGuardian && settings.configGuardian.graphData) {
          Drupal.configGuardianGraph.init(container, settings.configGuardian.graphData);
        }
      });
    }
  };

  Drupal.configGuardianGraph = {
    container: null,
    svg: null,
    graphData: null,
    nodes: [],
    links: [],
    zoom: 1,
    panX: 0,
    panY: 0,
    isDragging: false,
    dragStart: { x: 0, y: 0 },
    selectedNode: null,
    filterType: 'all',
    searchTerm: '',
    width: 800,
    height: 500,

    init: function (container, graphData) {
      this.container = container;
      this.graphData = graphData;
      this.width = container.clientWidth || 800;
      this.height = 500;

      // Parse graph data
      this.parseGraphData();

      // Create UI elements
      this.createControls();
      this.createInfoPanel();
      this.createLegend();

      // Create SVG
      this.createSVG();

      // Apply force-directed layout
      this.forceDirectedLayout();

      // Render graph
      this.renderGraph();

      // Setup events
      this.setupEvents();
    },

    parseGraphData: function () {
      var self = this;
      this.nodes = [];
      this.links = [];

      if (!this.graphData || !this.graphData.nodes) {
        return;
      }

      // Create nodes
      Object.keys(this.graphData.nodes).forEach(function (key) {
        var node = self.graphData.nodes[key];
        self.nodes.push({
          id: key,
          name: node.name || key,
          type: node.type || 'config',
          module: node.module || self.extractModule(key),
          provider: node.provider || 'unknown',
          risk: node.risk || 'low',
          dependencies: node.dependencies || [],
          dependents: node.dependents || [],
          x: 0,
          y: 0,
          vx: 0,
          vy: 0
        });
      });

      // Create links from dependencies
      this.nodes.forEach(function (node) {
        if (node.dependencies && node.dependencies.length > 0) {
          node.dependencies.forEach(function (dep) {
            var targetNode = self.nodes.find(function (n) { return n.id === dep; });
            if (targetNode) {
              self.links.push({
                source: node,
                target: targetNode
              });
            }
          });
        }
      });
    },

    extractModule: function (configName) {
      var parts = configName.split('.');
      if (parts.length >= 2) {
        return parts[0] + '.' + parts[1];
      }
      return parts[0] || 'core';
    },

    createControls: function () {
      var self = this;
      var controls = document.createElement('div');
      controls.className = 'graph-controls';
      controls.innerHTML =
        '<div class="zoom-controls">' +
          '<button type="button" class="zoom-in" title="Zoom In">+</button>' +
          '<button type="button" class="zoom-out" title="Zoom Out">-</button>' +
          '<button type="button" class="zoom-reset" title="Reset View">Reset</button>' +
          '<button type="button" class="zoom-fit" title="Fit to View">Fit</button>' +
        '</div>' +
        '<div class="filter-controls">' +
          '<label>Filter: ' +
            '<select class="filter-type">' +
              '<option value="all">All Types</option>' +
              '<option value="system">System</option>' +
              '<option value="field">Fields</option>' +
              '<option value="node">Content Types</option>' +
              '<option value="views">Views</option>' +
              '<option value="block">Blocks</option>' +
              '<option value="user">User</option>' +
            '</select>' +
          '</label>' +
          '<label>Search: ' +
            '<input type="text" class="search-input" placeholder="Search configs...">' +
          '</label>' +
        '</div>' +
        '<div class="graph-info">' +
          '<span class="node-count">Nodes: 0</span> | ' +
          '<span class="link-count">Links: 0</span>' +
        '</div>';

      this.container.insertBefore(controls, this.container.firstChild);

      // Event listeners
      controls.querySelector('.zoom-in').addEventListener('click', function () {
        self.setZoom(self.zoom * 1.2);
      });
      controls.querySelector('.zoom-out').addEventListener('click', function () {
        self.setZoom(self.zoom / 1.2);
      });
      controls.querySelector('.zoom-reset').addEventListener('click', function () {
        self.zoom = 1;
        self.panX = 0;
        self.panY = 0;
        self.updateTransform();
      });
      controls.querySelector('.zoom-fit').addEventListener('click', function () {
        self.fitToView();
      });
      controls.querySelector('.filter-type').addEventListener('change', function (e) {
        self.filterType = e.target.value;
        self.renderGraph();
      });
      controls.querySelector('.search-input').addEventListener('input', function (e) {
        self.searchTerm = e.target.value.toLowerCase();
        self.renderGraph();
      });
    },

    createInfoPanel: function () {
      var panel = document.createElement('div');
      panel.className = 'graph-info-panel';
      panel.style.display = 'none';
      panel.innerHTML =
        '<div class="info-header">' +
          '<h4 class="info-title">Config Details</h4>' +
          '<button type="button" class="info-close">&times;</button>' +
        '</div>' +
        '<div class="info-content">' +
          '<div class="info-row"><strong>Name:</strong> <span class="info-name"></span></div>' +
          '<div class="info-row"><strong>Type:</strong> <span class="info-type"></span></div>' +
          '<div class="info-row"><strong>Module:</strong> <span class="info-module"></span></div>' +
          '<div class="info-row"><strong>Provider:</strong> <span class="info-provider"></span></div>' +
          '<div class="info-row"><strong>Risk Level:</strong> <span class="info-risk"></span></div>' +
          '<div class="info-row"><strong>Dependencies:</strong> <span class="info-deps"></span></div>' +
          '<div class="info-row"><strong>Dependents:</strong> <span class="info-dependents"></span></div>' +
        '</div>';

      this.container.appendChild(panel);

      var self = this;
      panel.querySelector('.info-close').addEventListener('click', function () {
        self.hideInfoPanel();
      });
    },

    createLegend: function () {
      var legend = document.createElement('div');
      legend.className = 'graph-legend';
      legend.innerHTML =
        '<strong>Risk Level:</strong> ' +
        '<span class="legend-item"><span class="legend-color" style="background:#28a745"></span> Low</span> ' +
        '<span class="legend-item"><span class="legend-color" style="background:#ffc107"></span> Medium</span> ' +
        '<span class="legend-item"><span class="legend-color" style="background:#fd7e14"></span> High</span> ' +
        '<span class="legend-item"><span class="legend-color" style="background:#dc3545"></span> Critical</span>';

      this.container.appendChild(legend);
    },

    createSVG: function () {
      var svgContainer = document.createElement('div');
      svgContainer.className = 'graph-svg-container';
      svgContainer.style.width = '100%';
      svgContainer.style.height = this.height + 'px';
      svgContainer.style.overflow = 'hidden';
      svgContainer.style.border = '1px solid #ddd';
      svgContainer.style.borderRadius = '4px';
      svgContainer.style.background = '#fafafa';
      svgContainer.style.cursor = 'grab';

      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('width', '100%');
      svg.setAttribute('height', '100%');
      svg.setAttribute('viewBox', '0 0 ' + this.width + ' ' + this.height);

      // Main group for transformations
      var mainGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      mainGroup.setAttribute('class', 'main-group');

      // Links group
      var linksGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      linksGroup.setAttribute('class', 'links-group');
      mainGroup.appendChild(linksGroup);

      // Nodes group
      var nodesGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      nodesGroup.setAttribute('class', 'nodes-group');
      mainGroup.appendChild(nodesGroup);

      svg.appendChild(mainGroup);
      svgContainer.appendChild(svg);
      this.container.appendChild(svgContainer);

      this.svg = svg;
      this.svgContainer = svgContainer;
    },

    forceDirectedLayout: function () {
      var self = this;
      var iterations = 100;
      var repulsion = 5000;
      var attraction = 0.01;
      var damping = 0.85;
      var centerX = this.width / 2;
      var centerY = this.height / 2;

      // Initialize random positions
      this.nodes.forEach(function (node) {
        node.x = centerX + (Math.random() - 0.5) * self.width * 0.8;
        node.y = centerY + (Math.random() - 0.5) * self.height * 0.8;
        node.vx = 0;
        node.vy = 0;
      });

      // Run simulation
      for (var iter = 0; iter < iterations; iter++) {
        // Repulsion between nodes
        for (var i = 0; i < this.nodes.length; i++) {
          for (var j = i + 1; j < this.nodes.length; j++) {
            var nodeA = this.nodes[i];
            var nodeB = this.nodes[j];
            var dx = nodeB.x - nodeA.x;
            var dy = nodeB.y - nodeA.y;
            var dist = Math.sqrt(dx * dx + dy * dy) || 1;
            var force = repulsion / (dist * dist);
            var fx = (dx / dist) * force;
            var fy = (dy / dist) * force;
            nodeA.vx -= fx;
            nodeA.vy -= fy;
            nodeB.vx += fx;
            nodeB.vy += fy;
          }
        }

        // Attraction along links
        this.links.forEach(function (link) {
          var dx = link.target.x - link.source.x;
          var dy = link.target.y - link.source.y;
          var dist = Math.sqrt(dx * dx + dy * dy) || 1;
          var force = dist * attraction;
          var fx = (dx / dist) * force;
          var fy = (dy / dist) * force;
          link.source.vx += fx;
          link.source.vy += fy;
          link.target.vx -= fx;
          link.target.vy -= fy;
        });

        // Center gravity
        this.nodes.forEach(function (node) {
          node.vx += (centerX - node.x) * 0.001;
          node.vy += (centerY - node.y) * 0.001;
        });

        // Apply velocities with damping
        this.nodes.forEach(function (node) {
          node.vx *= damping;
          node.vy *= damping;
          node.x += node.vx;
          node.y += node.vy;

          // Keep in bounds
          node.x = Math.max(50, Math.min(self.width - 50, node.x));
          node.y = Math.max(50, Math.min(self.height - 50, node.y));
        });
      }
    },

    renderGraph: function () {
      var self = this;
      var linksGroup = this.svg.querySelector('.links-group');
      var nodesGroup = this.svg.querySelector('.nodes-group');

      // Clear existing
      linksGroup.innerHTML = '';
      nodesGroup.innerHTML = '';

      // Filter nodes
      var filteredNodes = this.nodes.filter(function (node) {
        var typeMatch = self.filterType === 'all' || node.id.indexOf(self.filterType) !== -1;
        var searchMatch = !self.searchTerm || node.id.toLowerCase().indexOf(self.searchTerm) !== -1;
        return typeMatch && searchMatch;
      });

      var filteredNodeIds = filteredNodes.map(function (n) { return n.id; });

      // Filter links
      var filteredLinks = this.links.filter(function (link) {
        return filteredNodeIds.indexOf(link.source.id) !== -1 &&
               filteredNodeIds.indexOf(link.target.id) !== -1;
      });

      // Update counts
      var nodeCount = this.container.querySelector('.node-count');
      var linkCount = this.container.querySelector('.link-count');
      if (nodeCount) nodeCount.textContent = 'Nodes: ' + filteredNodes.length;
      if (linkCount) linkCount.textContent = 'Links: ' + filteredLinks.length;

      // Draw links
      filteredLinks.forEach(function (link) {
        var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', link.source.x);
        line.setAttribute('y1', link.source.y);
        line.setAttribute('x2', link.target.x);
        line.setAttribute('y2', link.target.y);
        line.setAttribute('stroke', '#999');
        line.setAttribute('stroke-width', '1');
        line.setAttribute('stroke-opacity', '0.6');
        line.setAttribute('class', 'graph-link');
        line.setAttribute('data-source', link.source.id);
        line.setAttribute('data-target', link.target.id);
        linksGroup.appendChild(line);
      });

      // Draw nodes
      filteredNodes.forEach(function (node) {
        var group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        group.setAttribute('class', 'graph-node');
        group.setAttribute('data-id', node.id);
        group.setAttribute('transform', 'translate(' + node.x + ',' + node.y + ')');
        group.style.cursor = 'pointer';

        // Node circle
        var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        var radius = 8 + Math.min(node.dependents.length * 2, 12);
        circle.setAttribute('r', radius);
        circle.setAttribute('fill', self.getRiskColor(node.risk));
        circle.setAttribute('stroke', '#fff');
        circle.setAttribute('stroke-width', '2');
        group.appendChild(circle);

        // Node label (shortened)
        var label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        var shortName = node.id.length > 20 ? node.id.substring(0, 17) + '...' : node.id;
        label.textContent = shortName;
        label.setAttribute('x', radius + 5);
        label.setAttribute('y', 4);
        label.setAttribute('font-size', '10');
        label.setAttribute('fill', '#333');
        label.style.pointerEvents = 'none';
        group.appendChild(label);

        // Event handlers
        group.addEventListener('click', function (e) {
          e.stopPropagation();
          self.selectNode(node);
        });

        group.addEventListener('mouseenter', function () {
          self.highlightConnections(node, true);
        });

        group.addEventListener('mouseleave', function () {
          if (self.selectedNode !== node) {
            self.highlightConnections(node, false);
          }
        });

        nodesGroup.appendChild(group);
      });
    },

    selectNode: function (node) {
      // Deselect previous
      if (this.selectedNode) {
        this.highlightConnections(this.selectedNode, false);
      }

      if (this.selectedNode === node) {
        this.selectedNode = null;
        this.hideInfoPanel();
      } else {
        this.selectedNode = node;
        this.highlightConnections(node, true);
        this.showInfoPanel(node);
      }
    },

    highlightConnections: function (node, highlight) {
      var self = this;
      var linksGroup = this.svg.querySelector('.links-group');
      var nodesGroup = this.svg.querySelector('.nodes-group');

      // Get connected node IDs
      var connectedIds = [node.id];
      node.dependencies.forEach(function (dep) { connectedIds.push(dep); });
      node.dependents.forEach(function (dep) { connectedIds.push(dep); });

      // Update links
      linksGroup.querySelectorAll('.graph-link').forEach(function (link) {
        var source = link.getAttribute('data-source');
        var target = link.getAttribute('data-target');
        var isConnected = (source === node.id || target === node.id);

        if (highlight) {
          link.setAttribute('stroke-opacity', isConnected ? '1' : '0.1');
          link.setAttribute('stroke-width', isConnected ? '2' : '1');
          link.setAttribute('stroke', isConnected ? '#007bff' : '#999');
        } else {
          link.setAttribute('stroke-opacity', '0.6');
          link.setAttribute('stroke-width', '1');
          link.setAttribute('stroke', '#999');
        }
      });

      // Update nodes
      nodesGroup.querySelectorAll('.graph-node').forEach(function (nodeEl) {
        var nodeId = nodeEl.getAttribute('data-id');
        var isConnected = connectedIds.indexOf(nodeId) !== -1;

        if (highlight) {
          nodeEl.style.opacity = isConnected ? '1' : '0.3';
          if (nodeId === node.id) {
            nodeEl.querySelector('circle').setAttribute('stroke', '#007bff');
            nodeEl.querySelector('circle').setAttribute('stroke-width', '3');
          }
        } else {
          nodeEl.style.opacity = '1';
          nodeEl.querySelector('circle').setAttribute('stroke', '#fff');
          nodeEl.querySelector('circle').setAttribute('stroke-width', '2');
        }
      });
    },

    showInfoPanel: function (node) {
      var panel = this.container.querySelector('.graph-info-panel');
      panel.querySelector('.info-name').textContent = node.id;
      panel.querySelector('.info-type').textContent = node.type;
      panel.querySelector('.info-module').textContent = node.module;
      panel.querySelector('.info-provider').textContent = node.provider;

      var riskSpan = panel.querySelector('.info-risk');
      riskSpan.textContent = node.risk.charAt(0).toUpperCase() + node.risk.slice(1);
      riskSpan.style.color = this.getRiskColor(node.risk);
      riskSpan.style.fontWeight = 'bold';

      panel.querySelector('.info-deps').textContent =
        node.dependencies.length > 0 ? node.dependencies.join(', ') : 'None';
      panel.querySelector('.info-dependents').textContent =
        node.dependents.length > 0 ? node.dependents.join(', ') : 'None';

      panel.style.display = 'block';
    },

    hideInfoPanel: function () {
      var panel = this.container.querySelector('.graph-info-panel');
      panel.style.display = 'none';
    },

    setupEvents: function () {
      var self = this;

      // Mouse wheel zoom
      this.svgContainer.addEventListener('wheel', function (e) {
        e.preventDefault();
        var delta = e.deltaY > 0 ? 0.9 : 1.1;
        self.setZoom(self.zoom * delta);
      });

      // Pan with mouse drag
      this.svgContainer.addEventListener('mousedown', function (e) {
        if (e.target === self.svgContainer || e.target === self.svg) {
          self.isDragging = true;
          self.dragStart = { x: e.clientX - self.panX, y: e.clientY - self.panY };
          self.svgContainer.style.cursor = 'grabbing';
        }
      });

      document.addEventListener('mousemove', function (e) {
        if (self.isDragging) {
          self.panX = e.clientX - self.dragStart.x;
          self.panY = e.clientY - self.dragStart.y;
          self.updateTransform();
        }
      });

      document.addEventListener('mouseup', function () {
        self.isDragging = false;
        self.svgContainer.style.cursor = 'grab';
      });

      // Click outside nodes to deselect
      this.svg.addEventListener('click', function (e) {
        if (e.target === self.svg || e.target.classList.contains('links-group')) {
          if (self.selectedNode) {
            self.highlightConnections(self.selectedNode, false);
            self.selectedNode = null;
            self.hideInfoPanel();
          }
        }
      });
    },

    setZoom: function (newZoom) {
      this.zoom = Math.max(0.1, Math.min(5, newZoom));
      this.updateTransform();
    },

    updateTransform: function () {
      var mainGroup = this.svg.querySelector('.main-group');
      mainGroup.setAttribute('transform',
        'translate(' + this.panX + ',' + this.panY + ') scale(' + this.zoom + ')');
    },

    fitToView: function () {
      if (this.nodes.length === 0) return;

      var minX = Infinity, maxX = -Infinity;
      var minY = Infinity, maxY = -Infinity;

      this.nodes.forEach(function (node) {
        minX = Math.min(minX, node.x);
        maxX = Math.max(maxX, node.x);
        minY = Math.min(minY, node.y);
        maxY = Math.max(maxY, node.y);
      });

      var graphWidth = maxX - minX + 100;
      var graphHeight = maxY - minY + 100;

      this.zoom = Math.min(this.width / graphWidth, this.height / graphHeight, 2);
      this.panX = (this.width - graphWidth * this.zoom) / 2 - minX * this.zoom + 50;
      this.panY = (this.height - graphHeight * this.zoom) / 2 - minY * this.zoom + 50;

      this.updateTransform();
    },

    getRiskColor: function (risk) {
      var colors = {
        low: '#28a745',
        medium: '#ffc107',
        high: '#fd7e14',
        critical: '#dc3545'
      };
      return colors[risk] || '#6c757d';
    }
  };

})(Drupal, drupalSettings, once);
