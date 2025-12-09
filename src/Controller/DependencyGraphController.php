<?php

declare(strict_types=1);

namespace Drupal\config_guardian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\config_guardian\Service\ConfigAnalyzerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the interactive dependency graph iframe.
 */
class DependencyGraphController extends ControllerBase {

  /**
   * The config analyzer.
   */
  protected ConfigAnalyzerService $configAnalyzer;

  /**
   * Constructs a DependencyGraphController object.
   */
  public function __construct(ConfigAnalyzerService $config_analyzer) {
    $this->configAnalyzer = $config_analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config_guardian.config_analyzer')
    );
  }

  /**
   * Gets provider name from config name.
   *
   * @param string $config_name
   *   The configuration name.
   *
   * @return string
   *   The provider name.
   */
  protected function getProviderFromConfigName(string $config_name): string {
    $parts = explode('.', $config_name);
    $prefix = $parts[0] ?? 'unknown';

    $core_modules = ['core', 'system', 'field', 'node', 'user', 'taxonomy', 'views', 'block', 'filter', 'image'];
    if (in_array($prefix, $core_modules)) {
      return 'core';
    }

    return \Drupal::moduleHandler()->moduleExists($prefix) ? $prefix : 'unknown';
  }

  /**
   * Renders the dependency graph as a standalone HTML page for iframe embedding.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A complete HTML page response.
   */
  public function iframe(): Response {
    // Get pending changes and build dependency graph.
    $pending_changes = $this->configAnalyzer->getPendingChanges();
    $all_changes = array_merge(
      $pending_changes['create'],
      $pending_changes['update'],
      $pending_changes['delete']
    );

    $dependency_graph = $this->configAnalyzer->buildDependencyGraph($all_changes);

    // Transform nodes array to use config name as key for proper identification.
    $nodes_by_name = [];
    foreach ($dependency_graph['nodes'] as $node) {
      $nodes_by_name[$node['name']] = [
        'name' => $node['name'],
        'type' => $node['type'],
        'risk' => $node['risk'],
        'provider' => $this->getProviderFromConfigName($node['name']),
        'dependencies' => [],
        'dependents' => [],
      ];
    }

    // Build proper dependency/dependent relationships.
    foreach ($dependency_graph['links'] as $link) {
      $source_name = $dependency_graph['nodes'][$link['source']]['name'] ?? NULL;
      $target_name = $dependency_graph['nodes'][$link['target']]['name'] ?? NULL;
      if ($source_name && $target_name && isset($nodes_by_name[$source_name]) && isset($nodes_by_name[$target_name])) {
        $nodes_by_name[$source_name]['dependents'][] = $target_name;
        $nodes_by_name[$target_name]['dependencies'][] = $source_name;
      }
    }

    // Build links using config names as identifiers.
    $links_by_name = [];
    foreach ($dependency_graph['links'] as $link) {
      $source_name = $dependency_graph['nodes'][$link['source']]['name'] ?? NULL;
      $target_name = $dependency_graph['nodes'][$link['target']]['name'] ?? NULL;
      if ($source_name && $target_name) {
        $links_by_name[] = [
          'source' => $source_name,
          'target' => $target_name,
        ];
      }
    }

    $graph_data = [
      'nodes' => $nodes_by_name,
      'links' => $links_by_name,
    ];

    $graph_json = json_encode($graph_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    // Get current language for translations.
    $lang = \Drupal::languageManager()->getCurrentLanguage()->getId();

    // Translations for the iframe (since it's outside Drupal's normal rendering).
    $translations = $this->getTranslations();
    $translations_json = json_encode($translations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Config Guardian - Dependency Graph</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
      background: #f8f9fa;
      overflow: hidden;
      width: 100vw;
      height: 100vh;
    }

    .graph-container {
      width: 100%;
      height: 100%;
      display: flex;
      flex-direction: column;
    }

    .graph-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      padding: 10px;
      background: #fff;
      border-bottom: 1px solid #ddd;
      align-items: center;
    }

    .toolbar-group {
      display: flex;
      gap: 5px;
      align-items: center;
    }

    .toolbar-group label {
      font-size: 12px;
      color: #666;
      margin-right: 5px;
    }

    .toolbar-btn {
      padding: 6px 12px;
      border: 1px solid #ddd;
      background: #fff;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      transition: all 0.2s;
    }

    .toolbar-btn:hover {
      background: #e9ecef;
      border-color: #adb5bd;
    }

    .toolbar-btn.active {
      background: #007bff;
      color: #fff;
      border-color: #007bff;
    }

    .toolbar-select, .toolbar-input {
      padding: 6px 10px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 13px;
    }

    .toolbar-input {
      width: 180px;
    }

    .graph-stats {
      font-size: 12px;
      color: #666;
      margin-left: auto;
    }

    .graph-canvas {
      flex: 1;
      position: relative;
      overflow: hidden;
      background: linear-gradient(90deg, #f0f0f0 1px, transparent 1px) 0 0 / 20px 20px,
                  linear-gradient(#f0f0f0 1px, transparent 1px) 0 0 / 20px 20px,
                  #fafafa;
      cursor: grab;
    }

    .graph-canvas.dragging {
      cursor: grabbing;
    }

    .graph-canvas svg {
      position: absolute;
      top: 0;
      left: 0;
    }

    .node {
      cursor: pointer;
      transition: opacity 0.2s;
    }

    .node:hover circle {
      stroke-width: 3px;
      filter: brightness(1.1);
    }

    .node.dimmed {
      opacity: 0.2;
    }

    .node.selected circle {
      stroke: #007bff;
      stroke-width: 4px;
    }

    .node-label {
      font-size: 11px;
      fill: #333;
      pointer-events: none;
      user-select: none;
    }

    .link {
      stroke: #999;
      stroke-opacity: 0.5;
      stroke-width: 1.5;
      fill: none;
      transition: stroke-opacity 0.2s, stroke 0.2s, stroke-width 0.2s;
    }

    .link.dimmed {
      stroke-opacity: 0.1;
    }

    .link.highlighted {
      stroke: #007bff;
      stroke-opacity: 1;
      stroke-width: 2.5;
    }

    .link-arrow {
      fill: #999;
    }

    .link.highlighted .link-arrow {
      fill: #007bff;
    }

    .info-panel {
      position: absolute;
      top: 10px;
      right: 10px;
      width: 320px;
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      display: none;
      max-height: calc(100% - 20px);
      overflow: auto;
    }

    .info-panel.visible {
      display: block;
    }

    .info-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 15px;
      background: #f8f9fa;
      border-bottom: 1px solid #e9ecef;
      border-radius: 8px 8px 0 0;
    }

    .info-header h4 {
      margin: 0;
      font-size: 14px;
      color: #333;
    }

    .info-close {
      background: none;
      border: none;
      font-size: 20px;
      cursor: pointer;
      color: #666;
      line-height: 1;
    }

    .info-close:hover {
      color: #333;
    }

    .info-content {
      padding: 15px;
    }

    .info-row {
      margin-bottom: 10px;
      font-size: 13px;
    }

    .info-row strong {
      display: block;
      color: #666;
      font-size: 11px;
      text-transform: uppercase;
      margin-bottom: 3px;
    }

    .info-row code {
      background: #f1f3f5;
      padding: 2px 6px;
      border-radius: 3px;
      font-size: 12px;
      word-break: break-all;
    }

    .risk-badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .risk-badge.low { background: #d4edda; color: #155724; }
    .risk-badge.medium { background: #fff3cd; color: #856404; }
    .risk-badge.high { background: #ffe5d0; color: #d63384; }
    .risk-badge.critical { background: #f8d7da; color: #721c24; }

    .deps-list {
      margin-top: 5px;
      font-size: 12px;
      max-height: 100px;
      overflow-y: auto;
    }

    .deps-list div {
      padding: 3px 0;
      border-bottom: 1px solid #f0f0f0;
    }

    .legend {
      position: absolute;
      bottom: 10px;
      left: 10px;
      background: rgba(255,255,255,0.95);
      padding: 10px 15px;
      border-radius: 6px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      font-size: 12px;
    }

    .legend-title {
      font-weight: 600;
      margin-bottom: 8px;
      color: #333;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 4px;
    }

    .legend-color {
      width: 14px;
      height: 14px;
      border-radius: 50%;
      border: 2px solid #fff;
      box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }

    .zoom-indicator {
      position: absolute;
      bottom: 10px;
      right: 10px;
      background: rgba(255,255,255,0.9);
      padding: 5px 10px;
      border-radius: 4px;
      font-size: 12px;
      color: #666;
    }

    .empty-state {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      text-align: center;
      color: #666;
    }

    .empty-state h3 {
      margin-bottom: 10px;
      color: #333;
    }
  </style>
</head>
<body>
  <div class="graph-container">
    <div class="graph-toolbar">
      <div class="toolbar-group">
        <button type="button" class="toolbar-btn" id="zoom-in" title="Zoom In">+</button>
        <button type="button" class="toolbar-btn" id="zoom-out" title="Zoom Out">−</button>
        <button type="button" class="toolbar-btn" id="zoom-reset" data-label="reset"></button>
        <button type="button" class="toolbar-btn" id="zoom-fit" data-label="fit"></button>
      </div>

      <div class="toolbar-group">
        <label data-label="filter"></label>
        <select class="toolbar-select" id="filter-type">
          <option value="all" data-label="all_types"></option>
          <option value="system" data-label="system"></option>
          <option value="field" data-label="fields"></option>
          <option value="node" data-label="content_types"></option>
          <option value="views" data-label="views"></option>
          <option value="block" data-label="blocks"></option>
          <option value="user" data-label="user"></option>
          <option value="taxonomy" data-label="taxonomy"></option>
          <option value="image" data-label="image"></option>
        </select>
      </div>

      <div class="toolbar-group">
        <label data-label="search"></label>
        <input type="text" class="toolbar-input" id="search-input" data-placeholder="search_placeholder">
      </div>

      <div class="graph-stats">
        <span id="node-count">0</span> <span data-label="nodes"></span> &bull;
        <span id="link-count">0</span> <span data-label="connections"></span>
      </div>
    </div>

    <div class="graph-canvas" id="graph-canvas">
      <svg id="graph-svg"></svg>

      <div class="info-panel" id="info-panel">
        <div class="info-header">
          <h4 data-label="config_details"></h4>
          <button type="button" class="info-close" id="info-close">&times;</button>
        </div>
        <div class="info-content">
          <div class="info-row">
            <strong data-label="name"></strong>
            <code id="info-name"></code>
          </div>
          <div class="info-row">
            <strong data-label="type"></strong>
            <span id="info-type"></span>
          </div>
          <div class="info-row">
            <strong data-label="provider"></strong>
            <span id="info-provider"></span>
          </div>
          <div class="info-row">
            <strong data-label="risk_level"></strong>
            <span id="info-risk" class="risk-badge"></span>
          </div>
          <div class="info-row">
            <strong><span data-label="dependencies"></span> (<span id="info-deps-count">0</span>)</strong>
            <div class="deps-list" id="info-deps"></div>
          </div>
          <div class="info-row">
            <strong><span data-label="dependents"></span> (<span id="info-dependents-count">0</span>)</strong>
            <div class="deps-list" id="info-dependents"></div>
          </div>
        </div>
      </div>

      <div class="legend">
        <div class="legend-title" data-label="risk_level"></div>
        <div class="legend-item"><span class="legend-color" style="background:#28a745"></span> <span data-label="low"></span></div>
        <div class="legend-item"><span class="legend-color" style="background:#ffc107"></span> <span data-label="medium"></span></div>
        <div class="legend-item"><span class="legend-color" style="background:#fd7e14"></span> <span data-label="high"></span></div>
        <div class="legend-item"><span class="legend-color" style="background:#dc3545"></span> <span data-label="critical"></span></div>
      </div>

      <div class="zoom-indicator">Zoom: <span id="zoom-level">100%</span></div>
    </div>
  </div>

  <script>
    (function() {
      'use strict';

      const graphData = {$graph_json};
      const T = {$translations_json};

      // Apply translations to DOM elements.
      function applyTranslations() {
        document.querySelectorAll('[data-label]').forEach(el => {
          const key = el.getAttribute('data-label');
          if (T[key]) {
            el.textContent = T[key];
          }
        });
        document.querySelectorAll('[data-placeholder]').forEach(el => {
          const key = el.getAttribute('data-placeholder');
          if (T[key]) {
            el.placeholder = T[key];
          }
        });
      }

      // Get translated risk label.
      function getRiskLabel(risk) {
        return T[risk] || risk;
      }

      // Graph state
      const state = {
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
        width: 0,
        height: 0
      };

      // DOM elements
      const canvas = document.getElementById('graph-canvas');
      const svg = document.getElementById('graph-svg');
      const infoPanel = document.getElementById('info-panel');

      // Initialize
      function init() {
        applyTranslations();
        updateDimensions();
        parseGraphData();

        if (state.nodes.length === 0) {
          showEmptyState();
          return;
        }

        forceDirectedLayout();
        render();
        setupEvents();
        fitToView();
      }

      function updateDimensions() {
        state.width = canvas.clientWidth || 800;
        state.height = canvas.clientHeight || 600;
        svg.setAttribute('width', state.width);
        svg.setAttribute('height', state.height);
      }

      function parseGraphData() {
        if (!graphData || !graphData.nodes) return;

        // Now nodes are keyed by config name, so key IS the config name.
        Object.keys(graphData.nodes).forEach(configName => {
          const node = graphData.nodes[configName];
          state.nodes.push({
            id: configName,
            name: node.name || configName,
            type: node.type || 'config',
            provider: node.provider || T['unknown'] || 'unknown',
            risk: node.risk || 'low',
            dependencies: node.dependencies || [],
            dependents: node.dependents || [],
            x: 0,
            y: 0,
            vx: 0,
            vy: 0
          });
        });

        // Build links from the links array.
        if (graphData.links) {
          graphData.links.forEach(link => {
            const sourceNode = state.nodes.find(n => n.id === link.source);
            const targetNode = state.nodes.find(n => n.id === link.target);
            if (sourceNode && targetNode) {
              state.links.push({
                source: sourceNode,
                target: targetNode
              });
            }
          });
        }
      }

      function showEmptyState() {
        canvas.innerHTML = '<div class="empty-state"><h3>' + (T['no_dependencies'] || 'No Dependencies') + '</h3><p>' + (T['no_changes_to_display'] || 'No pending configuration changes with dependencies to display.') + '</p></div>';
      }

      function forceDirectedLayout() {
        const iterations = 150;
        const repulsion = 8000;
        const attraction = 0.008;
        const damping = 0.9;
        const centerX = state.width / 2;
        const centerY = state.height / 2;

        // Initialize random positions
        state.nodes.forEach(node => {
          node.x = centerX + (Math.random() - 0.5) * state.width * 0.6;
          node.y = centerY + (Math.random() - 0.5) * state.height * 0.6;
          node.vx = 0;
          node.vy = 0;
        });

        // Run simulation
        for (let iter = 0; iter < iterations; iter++) {
          // Repulsion between nodes
          for (let i = 0; i < state.nodes.length; i++) {
            for (let j = i + 1; j < state.nodes.length; j++) {
              const nodeA = state.nodes[i];
              const nodeB = state.nodes[j];
              const dx = nodeB.x - nodeA.x;
              const dy = nodeB.y - nodeA.y;
              const dist = Math.sqrt(dx * dx + dy * dy) || 1;
              const force = repulsion / (dist * dist);
              const fx = (dx / dist) * force;
              const fy = (dy / dist) * force;
              nodeA.vx -= fx;
              nodeA.vy -= fy;
              nodeB.vx += fx;
              nodeB.vy += fy;
            }
          }

          // Attraction along links
          state.links.forEach(link => {
            const dx = link.target.x - link.source.x;
            const dy = link.target.y - link.source.y;
            const dist = Math.sqrt(dx * dx + dy * dy) || 1;
            const force = dist * attraction;
            const fx = (dx / dist) * force;
            const fy = (dy / dist) * force;
            link.source.vx += fx;
            link.source.vy += fy;
            link.target.vx -= fx;
            link.target.vy -= fy;
          });

          // Center gravity
          state.nodes.forEach(node => {
            node.vx += (centerX - node.x) * 0.0005;
            node.vy += (centerY - node.y) * 0.0005;
          });

          // Apply velocities with damping
          state.nodes.forEach(node => {
            node.vx *= damping;
            node.vy *= damping;
            node.x += node.vx;
            node.y += node.vy;
          });
        }
      }

      function render() {
        // Clear SVG
        svg.innerHTML = '';

        // Create defs for arrow markers
        const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        const marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
        marker.setAttribute('id', 'arrowhead');
        marker.setAttribute('markerWidth', '10');
        marker.setAttribute('markerHeight', '7');
        marker.setAttribute('refX', '10');
        marker.setAttribute('refY', '3.5');
        marker.setAttribute('orient', 'auto');
        const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        polygon.setAttribute('points', '0 0, 10 3.5, 0 7');
        polygon.setAttribute('class', 'link-arrow');
        marker.appendChild(polygon);
        defs.appendChild(marker);
        svg.appendChild(defs);

        // Create main group for transformations
        const mainGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        mainGroup.setAttribute('id', 'main-group');

        // Filter nodes
        const filteredNodes = state.nodes.filter(node => {
          const typeMatch = state.filterType === 'all' || node.id.toLowerCase().includes(state.filterType);
          const searchMatch = !state.searchTerm || node.id.toLowerCase().includes(state.searchTerm);
          return typeMatch && searchMatch;
        });

        const filteredNodeIds = new Set(filteredNodes.map(n => n.id));

        // Filter links
        const filteredLinks = state.links.filter(link =>
          filteredNodeIds.has(link.source.id) && filteredNodeIds.has(link.target.id)
        );

        // Update stats
        document.getElementById('node-count').textContent = filteredNodes.length;
        document.getElementById('link-count').textContent = filteredLinks.length;

        // Create links group
        const linksGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        linksGroup.setAttribute('class', 'links-group');

        filteredLinks.forEach(link => {
          const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
          line.setAttribute('x1', link.source.x);
          line.setAttribute('y1', link.source.y);
          line.setAttribute('x2', link.target.x);
          line.setAttribute('y2', link.target.y);
          line.setAttribute('class', 'link');
          line.setAttribute('data-source', link.source.id);
          line.setAttribute('data-target', link.target.id);
          line.setAttribute('marker-end', 'url(#arrowhead)');
          linksGroup.appendChild(line);
        });

        mainGroup.appendChild(linksGroup);

        // Create nodes group
        const nodesGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        nodesGroup.setAttribute('class', 'nodes-group');

        filteredNodes.forEach(node => {
          const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
          group.setAttribute('class', 'node');
          group.setAttribute('data-id', node.id);
          group.setAttribute('transform', 'translate(' + node.x + ',' + node.y + ')');

          // Node circle
          const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
          const radius = 10 + Math.min((node.dependents.length || 0) * 2, 15);
          circle.setAttribute('r', radius);
          circle.setAttribute('fill', getRiskColor(node.risk));
          circle.setAttribute('stroke', '#fff');
          circle.setAttribute('stroke-width', '2');
          group.appendChild(circle);

          // Node label - Use the actual config name (node.id is now the config name).
          const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
          const displayName = node.id;
          const shortName = displayName.length > 30 ? displayName.substring(0, 27) + '...' : displayName;
          label.textContent = shortName;
          label.setAttribute('class', 'node-label');
          label.setAttribute('x', radius + 6);
          label.setAttribute('y', 4);
          group.appendChild(label);

          // Event handlers
          group.addEventListener('click', (e) => {
            e.stopPropagation();
            selectNode(node);
          });

          group.addEventListener('mouseenter', () => {
            if (state.selectedNode !== node) {
              highlightConnections(node, true);
            }
          });

          group.addEventListener('mouseleave', () => {
            if (state.selectedNode !== node) {
              highlightConnections(node, false);
            }
          });

          nodesGroup.appendChild(group);
        });

        mainGroup.appendChild(nodesGroup);
        svg.appendChild(mainGroup);

        updateTransform();
      }

      function selectNode(node) {
        if (state.selectedNode) {
          highlightConnections(state.selectedNode, false);
          const prevEl = svg.querySelector('.node[data-id="' + CSS.escape(state.selectedNode.id) + '"]');
          if (prevEl) prevEl.classList.remove('selected');
        }

        if (state.selectedNode === node) {
          state.selectedNode = null;
          infoPanel.classList.remove('visible');
        } else {
          state.selectedNode = node;
          highlightConnections(node, true);
          const nodeEl = svg.querySelector('.node[data-id="' + CSS.escape(node.id) + '"]');
          if (nodeEl) nodeEl.classList.add('selected');
          showInfoPanel(node);
        }
      }

      function highlightConnections(node, highlight) {
        const connectedIds = new Set([node.id, ...node.dependencies, ...node.dependents]);

        svg.querySelectorAll('.link').forEach(link => {
          const source = link.getAttribute('data-source');
          const target = link.getAttribute('data-target');
          const isConnected = source === node.id || target === node.id;

          if (highlight) {
            link.classList.toggle('highlighted', isConnected);
            link.classList.toggle('dimmed', !isConnected);
          } else {
            link.classList.remove('highlighted', 'dimmed');
          }
        });

        svg.querySelectorAll('.node').forEach(nodeEl => {
          const nodeId = nodeEl.getAttribute('data-id');
          const isConnected = connectedIds.has(nodeId);

          if (highlight) {
            nodeEl.classList.toggle('dimmed', !isConnected);
          } else {
            nodeEl.classList.remove('dimmed');
          }
        });
      }

      function showInfoPanel(node) {
        document.getElementById('info-name').textContent = node.id;
        document.getElementById('info-type').textContent = node.type;
        document.getElementById('info-provider').textContent = node.provider === 'unknown' ? (T['unknown'] || 'unknown') : node.provider;

        const riskEl = document.getElementById('info-risk');
        riskEl.textContent = getRiskLabel(node.risk);
        riskEl.className = 'risk-badge ' + node.risk;

        document.getElementById('info-deps-count').textContent = node.dependencies.length;
        document.getElementById('info-dependents-count').textContent = node.dependents.length;

        const depsEl = document.getElementById('info-deps');
        depsEl.innerHTML = node.dependencies.length > 0
          ? node.dependencies.map(d => '<div><code>' + escapeHtml(d) + '</code></div>').join('')
          : '<div style="color:#999">' + (T['none'] || 'None') + '</div>';

        const dependentsEl = document.getElementById('info-dependents');
        dependentsEl.innerHTML = node.dependents.length > 0
          ? node.dependents.map(d => '<div><code>' + escapeHtml(d) + '</code></div>').join('')
          : '<div style="color:#999">' + (T['none'] || 'None') + '</div>';

        infoPanel.classList.add('visible');
      }

      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }

      function updateTransform() {
        const mainGroup = document.getElementById('main-group');
        if (mainGroup) {
          mainGroup.setAttribute('transform',
            'translate(' + state.panX + ',' + state.panY + ') scale(' + state.zoom + ')');
        }
        document.getElementById('zoom-level').textContent = Math.round(state.zoom * 100) + '%';
      }

      function setZoom(newZoom) {
        state.zoom = Math.max(0.1, Math.min(5, newZoom));
        updateTransform();
      }

      function fitToView() {
        if (state.nodes.length === 0) return;

        let minX = Infinity, maxX = -Infinity;
        let minY = Infinity, maxY = -Infinity;

        state.nodes.forEach(node => {
          minX = Math.min(minX, node.x);
          maxX = Math.max(maxX, node.x);
          minY = Math.min(minY, node.y);
          maxY = Math.max(maxY, node.y);
        });

        const graphWidth = maxX - minX + 150;
        const graphHeight = maxY - minY + 150;

        state.zoom = Math.min(state.width / graphWidth, state.height / graphHeight, 2) * 0.9;
        state.panX = (state.width - graphWidth * state.zoom) / 2 - minX * state.zoom + 75;
        state.panY = (state.height - graphHeight * state.zoom) / 2 - minY * state.zoom + 75;

        updateTransform();
      }

      function getRiskColor(risk) {
        const colors = {
          low: '#28a745',
          medium: '#ffc107',
          high: '#fd7e14',
          critical: '#dc3545'
        };
        return colors[risk] || '#6c757d';
      }

      function setupEvents() {
        // Zoom buttons
        document.getElementById('zoom-in').addEventListener('click', () => setZoom(state.zoom * 1.25));
        document.getElementById('zoom-out').addEventListener('click', () => setZoom(state.zoom / 1.25));
        document.getElementById('zoom-reset').addEventListener('click', () => {
          state.zoom = 1;
          state.panX = 0;
          state.panY = 0;
          updateTransform();
        });
        document.getElementById('zoom-fit').addEventListener('click', fitToView);

        // Filter and search
        document.getElementById('filter-type').addEventListener('change', (e) => {
          state.filterType = e.target.value;
          state.selectedNode = null;
          infoPanel.classList.remove('visible');
          render();
        });

        document.getElementById('search-input').addEventListener('input', (e) => {
          state.searchTerm = e.target.value.toLowerCase();
          state.selectedNode = null;
          infoPanel.classList.remove('visible');
          render();
        });

        // Info panel close
        document.getElementById('info-close').addEventListener('click', () => {
          if (state.selectedNode) {
            highlightConnections(state.selectedNode, false);
            const nodeEl = svg.querySelector('.node[data-id="' + CSS.escape(state.selectedNode.id) + '"]');
            if (nodeEl) nodeEl.classList.remove('selected');
            state.selectedNode = null;
          }
          infoPanel.classList.remove('visible');
        });

        // Mouse wheel zoom
        canvas.addEventListener('wheel', (e) => {
          e.preventDefault();
          const delta = e.deltaY > 0 ? 0.9 : 1.1;

          // Zoom towards mouse position
          const rect = canvas.getBoundingClientRect();
          const mouseX = e.clientX - rect.left;
          const mouseY = e.clientY - rect.top;

          const newZoom = Math.max(0.1, Math.min(5, state.zoom * delta));
          const scale = newZoom / state.zoom;

          state.panX = mouseX - (mouseX - state.panX) * scale;
          state.panY = mouseY - (mouseY - state.panY) * scale;
          state.zoom = newZoom;

          updateTransform();
        }, { passive: false });

        // Pan with mouse drag
        canvas.addEventListener('mousedown', (e) => {
          if (e.target === canvas || e.target === svg || e.target.tagName === 'svg') {
            state.isDragging = true;
            state.dragStart = { x: e.clientX - state.panX, y: e.clientY - state.panY };
            canvas.classList.add('dragging');
          }
        });

        document.addEventListener('mousemove', (e) => {
          if (state.isDragging) {
            state.panX = e.clientX - state.dragStart.x;
            state.panY = e.clientY - state.dragStart.y;
            updateTransform();
          }
        });

        document.addEventListener('mouseup', () => {
          state.isDragging = false;
          canvas.classList.remove('dragging');
        });

        // Click outside to deselect
        svg.addEventListener('click', (e) => {
          if (e.target === svg || e.target.classList.contains('links-group')) {
            if (state.selectedNode) {
              highlightConnections(state.selectedNode, false);
              const nodeEl = svg.querySelector('.node[data-id="' + CSS.escape(state.selectedNode.id) + '"]');
              if (nodeEl) nodeEl.classList.remove('selected');
              state.selectedNode = null;
              infoPanel.classList.remove('visible');
            }
          }
        });

        // Window resize
        window.addEventListener('resize', () => {
          updateDimensions();
          render();
        });
      }

      // Start
      document.addEventListener('DOMContentLoaded', init);
      if (document.readyState !== 'loading') init();
    })();
  </script>
</body>
</html>
HTML;

    return new Response($html, 200, ['Content-Type' => 'text/html']);
  }

  /**
   * Gets translations for the iframe.
   *
   * @return array
   *   Array of translated strings.
   */
  protected function getTranslations(): array {
    return [
      // Toolbar buttons.
      'reset' => (string) $this->t('Reset'),
      'fit' => (string) $this->t('Fit'),
      'filter' => (string) $this->t('Filter:'),
      'search' => (string) $this->t('Search:'),
      'search_placeholder' => (string) $this->t('Search configurations...'),

      // Filter options.
      'all_types' => (string) $this->t('All Types'),
      'system' => (string) $this->t('System'),
      'fields' => (string) $this->t('Fields'),
      'content_types' => (string) $this->t('Content Types'),
      'views' => (string) $this->t('Views'),
      'blocks' => (string) $this->t('Blocks'),
      'user' => (string) $this->t('User'),
      'taxonomy' => (string) $this->t('Taxonomy'),
      'image' => (string) $this->t('Image'),

      // Stats.
      'nodes' => (string) $this->t('nodes'),
      'connections' => (string) $this->t('connections'),

      // Info panel.
      'config_details' => (string) $this->t('Configuration Details'),
      'name' => (string) $this->t('Name'),
      'type' => (string) $this->t('Type'),
      'provider' => (string) $this->t('Provider'),
      'risk_level' => (string) $this->t('Risk Level'),
      'dependencies' => (string) $this->t('Dependencies'),
      'dependents' => (string) $this->t('Dependents'),
      'none' => (string) $this->t('None'),
      'unknown' => (string) $this->t('unknown'),

      // Risk levels.
      'low' => (string) $this->t('Low'),
      'medium' => (string) $this->t('Medium'),
      'high' => (string) $this->t('High'),
      'critical' => (string) $this->t('Critical'),

      // Empty state.
      'no_dependencies' => (string) $this->t('No Dependencies'),
      'no_changes_to_display' => (string) $this->t('No pending configuration changes with dependencies to display.'),
    ];
  }

}
