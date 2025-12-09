# Config Guardian

[![Drupal 10.2+](https://img.shields.io/badge/Drupal-10.2%2B-blue.svg)](https://www.drupal.org/project/drupal)
[![Drupal 11](https://img.shields.io/badge/Drupal-11-blue.svg)](https://www.drupal.org/project/drupal)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

> **Gestión de configuración de nivel empresarial para Drupal** con snapshots puntuales, capacidades de rollback seguro, análisis de dependencias, evaluación de riesgos y auditoría completa.

Config Guardian proporciona tranquilidad al gestionar la configuración de Drupal. Crea snapshots antes de cambios arriesgados, analiza el impacto de importaciones pendientes y revierte de forma segura cuando algo sale mal - todo con una interfaz hermosa e intuitiva.

---

## Tabla de Contenidos

- [Características](#características)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Configuración](#configuración)
- [Guía de Uso](#guía-de-uso)
  - [Panel de Control](#panel-de-control)
  - [Creación de Snapshots](#creación-de-snapshots)
  - [Visualización y Comparación de Snapshots](#visualización-y-comparación-de-snapshots)
  - [Reversión de Configuración](#reversión-de-configuración)
  - [Análisis de Impacto](#análisis-de-impacto)
  - [Sincronización de Configuración](#sincronización-de-configuración)
  - [Registro de Actividad](#registro-de-actividad)
- [Comandos Drush](#comandos-drush)
- [Documentación de la API](#documentación-de-la-api)
- [Personalización Visual](#personalización-visual)
- [Solución de Problemas](#solución-de-problemas)
- [Contribuir](#contribuir)
- [Licencia](#licencia)

---

## Características

### Gestión de Snapshots
- **Snapshots puntuales** - Captura el estado completo de tu configuración en cualquier momento
- **Captura dual de almacenamiento** - Los snapshots v2 capturan tanto la configuración activa COMO el directorio sync
- **Snapshots automáticos** - Programa backups automáticos cada hora, día o semana
- **Snapshots pre-importación** - Crea automáticamente un backup antes de cualquier importación
- **Almacenamiento comprimido** - Los snapshots se comprimen con gzip para minimizar el espacio
- **Verificación de integridad** - Hash SHA-256 garantiza la integridad de los datos
- **Políticas de retención** - Limpieza automática de snapshots antiguos

### Rollback Seguro
- **Modo simulación** - Previsualiza exactamente qué cambiará antes de ejecutar
- **Detección de conflictos** - Identifica problemas potenciales antes de que causen errores
- **Backup pre-rollback opcional** - Crea un snapshot de seguridad antes de revertir (habilitado por defecto, se puede desactivar)
- **Restauración completa del entorno** - Restaura tanto la configuración activa COMO el directorio sync (snapshots v2)
- **Rollback selectivo** - Revierte configuraciones específicas (próximamente)

### Análisis de Impacto
- **Mapeo de dependencias** - Visualiza cómo las configuraciones dependen entre sí
- **Grafo interactivo** - Visualización con D3.js con zoom, pan y búsqueda
- **Evaluación de riesgos** - Puntuación automática (0-100) con clasificación de nivel
- **Detección de conflictos** - Encuentra dependencias circulares y requisitos faltantes
- **Vista previa de cambios** - Ve qué se creará, actualizará o eliminará

### Registro de Actividad
- **Auditoría completa** - Rastrea quién hizo qué y cuándo
- **Seguimiento de IP** - Registra el origen de todos los cambios
- **Logging detallado** - Almacena nombres de configuración afectados y detalles
- **Seguimiento de estado** - Monitorea éxitos, advertencias y errores

### Sincronización de Configuración
- **Gestión de exportación** - Exporta la configuración activa al almacenamiento sync
- **Vista previa de importación** - Revisa todos los cambios antes de importar
- **Procesamiento por lotes** - Maneja grandes conjuntos de configuración eficientemente
- **Categorización de cambios** - Ve configuraciones nuevas, modificadas y eliminadas

---

## Requisitos

| Requisito | Versión |
|-----------|---------|
| Drupal | ^10.2 \|\| ^11 |
| PHP | 8.1+ |
| Módulos core | config, system, user |

### Opcional pero Recomendado
- **Drush 12+** - Para operaciones desde línea de comandos
- **Gin Admin Theme** - Para la mejor experiencia visual con soporte de modo oscuro

---

## Instalación

### Vía Composer (Recomendado)

```bash
composer require drupal/config_guardian
```

Luego habilita el módulo:

```bash
drush en config_guardian -y
```

### Instalación Manual

1. Descarga y extrae el módulo en `web/modules/contrib/config_guardian` (o `web/modules/custom/` para instalaciones personalizadas)
2. Navega a **Ampliar** (`/admin/modules`)
3. Habilita "Config Guardian"
4. Limpia cachés: `drush cr`

### Verificar Instalación

Después de la instalación, navega a `/admin/config/development/config-guardian` para acceder al panel de control.

---

## Configuración

### Ajustes del Módulo

Navega a **Configuración > Desarrollo > Config Guardian > Ajustes** (`/admin/config/development/config-guardian/settings`)

| Ajuste | Por Defecto | Descripción |
|--------|-------------|-------------|
| **Snapshot automático habilitado** | `true` | Habilita snapshots programados automáticos |
| **Snapshot pre-importación** | `true` | Crea snapshot antes de importaciones |
| **Intervalo de snapshot** | `daily` | Frecuencia: `hourly`, `daily` o `weekly` |
| **Máximo de snapshots** | `50` | Número máximo de snapshots a retener |
| **Días de retención** | `90` | Elimina snapshots más antiguos que esto |
| **Compresión** | `gzip` | Método de compresión para datos de snapshot |
| **Patrones de exclusión** | `system.cron`, `core.extension` | Nombres de config a excluir |

### Permisos

Config Guardian define 11 permisos para control de acceso granular:

| Permiso | Descripción |
|---------|-------------|
| `administer config guardian` | Acceso completo a toda la funcionalidad |
| `create config snapshots` | Crear snapshots manuales |
| `view config snapshots` | Ver lista y detalles de snapshots |
| `restore config snapshots` | Ejecutar operaciones de rollback |
| `delete config snapshots` | Eliminar snapshots |
| `export config snapshots` | Descargar snapshots como archivos |
| `import config snapshots` | Subir archivos de snapshot |
| `synchronize configuration` | Acceso a página de sincronización |
| `export configuration` | Exportar config activa a sync |
| `import configuration` | Importar config desde sync |
| `analyze config impact` | Ver análisis de impacto |

---

## Guía de Uso

### Panel de Control

El panel principal (`/admin/config/development/config-guardian`) proporciona:

- **Banner de Estado** - Estado actual de sincronización de un vistazo
- **Estadísticas** - Cambios pendientes, total de snapshots, actividad reciente
- **Cambios Pendientes** - Vista rápida de configuraciones esperando sync
- **Actividad Reciente** - Últimas operaciones realizadas
- **Tabla de Snapshots** - Lista de snapshots recientes con acciones rápidas

### Creación de Snapshots

#### Vía Interfaz

1. Navega a la pestaña **Snapshots**
2. Haz clic en **Crear Snapshot**
3. Introduce un nombre descriptivo (ej. "Antes de actualizar tema")
4. Opcionalmente añade una descripción
5. Haz clic en **Guardar**

#### Vía Drush

```bash
# Crear un snapshot con tipo por defecto (manual)
drush config-guardian:snapshot "Antes de actualización mayor"

# Crear con descripción
drush cg-snap "Pre-migración" --description="Antes de migración de contenido"
```

### Visualización y Comparación de Snapshots

#### Ver un Snapshot

1. Ve a la pestaña **Snapshots**
2. Haz clic en **Ver** en cualquier snapshot
3. Ve todas las configuraciones capturadas con sus valores

#### Comparar Dos Snapshots

1. Desde la vista de un snapshot, haz clic en **Comparar**
2. Selecciona otro snapshot para comparar
3. Ve las diferencias: añadidas, eliminadas y modificadas

### Reversión de Configuración

**Advertencia:** El rollback modifica tu configuración activa. Siempre revisa la simulación primero.

#### Vía Interfaz

1. Desde la lista de snapshots, haz clic en **Rollback** en el snapshot deseado
2. Revisa la simulación mostrando:
   - Configuraciones a crear
   - Configuraciones a actualizar
   - Configuraciones a eliminar
   - Puntuación y factores de riesgo
3. Opcionalmente habilita "Crear snapshot de backup antes del rollback"
4. Haz clic en **Rollback** para ejecutar

#### Vía Drush

```bash
# Simular rollback (dry-run)
drush config-guardian:rollback 42 --dry-run

# Ejecutar rollback con confirmación
drush cg-rollback 42

# Forzar rollback sin confirmación
drush cg-rollback 42 --force

# Rollback sin crear snapshot de backup
drush cg-rollback 42 --no-backup
```

### Análisis de Impacto

La página de análisis de impacto (`/admin/config/development/config-guardian/analyze`) te ayuda a entender las consecuencias de cambios de configuración pendientes:

1. **Puntuación de Riesgo** - Escala 0-100 con niveles:
   - **Bajo (0-25)** - Seguro proceder
   - **Medio (26-50)** - Revisión recomendada
   - **Alto (51-75)** - Revisión cuidadosa requerida
   - **Crítico (76-100)** - Alto riesgo, proceder con precaución

2. **Grafo de Dependencias** - Visualización interactiva mostrando:
   - Relaciones entre configuraciones
   - Nodos coloreados según riesgo
   - Capacidades de búsqueda y filtro
   - Controles de zoom y pan

3. **Detección de Conflictos** - Identifica:
   - Dependencias faltantes
   - Referencias circulares
   - Incompatibilidades de tipo

### Sincronización de Configuración

#### Exportar Configuración

1. Navega a **Sync > Exportar**
2. Revisa las configuraciones a exportar
3. Haz clic en **Exportar** para escribir al almacenamiento sync

#### Importar Configuración

1. Navega a **Sync > Importar**
2. Revisa los cambios pendientes con evaluación de riesgo
3. Expande secciones para ver cambios detallados
4. Haz clic en **Importar** para aplicar cambios

### Registro de Actividad

Ve todas las operaciones en `/admin/config/development/config-guardian/activity`:

- Filtra por tipo de acción, usuario o rango de fechas
- Ve información detallada de cambios
- Exporta logs para auditoría

---

## Comandos Drush

Config Guardian proporciona integración completa con Drush:

| Comando | Alias | Descripción |
|---------|-------|-------------|
| `config-guardian:snapshot <nombre>` | `cg-snap` | Crear un nuevo snapshot |
| `config-guardian:list` | `cg-list` | Listar todos los snapshots |
| `config-guardian:rollback <id>` | `cg-rollback` | Revertir a un snapshot |
| `config-guardian:analyze` | `cg-analyze` | Analizar cambios pendientes |
| `config-guardian:diff <id1> <id2>` | `cg-diff` | Comparar dos snapshots |
| `config-guardian:export <id> <ruta>` | `cg-export` | Exportar snapshot a archivo |
| `config-guardian:delete <id>` | `cg-delete` | Eliminar un snapshot |

### Opciones del Comando Rollback

| Opción | Descripción |
|--------|-------------|
| `--dry-run` | Simular el rollback sin hacer cambios |
| `--force` | Omitir confirmación |
| `--no-backup` | No crear snapshot de backup antes del rollback |

### Ejemplos

```bash
# Crear un snapshot antes de despliegue
drush cg-snap "Pre-despliegue $(date +%Y%m%d)"

# Listar snapshots recientes
drush cg-list --limit=10

# Listar solo snapshots automáticos
drush cg-list --type=auto

# Analizar cambios pendientes
drush cg-analyze

# Comparar snapshots
drush cg-diff 41 42

# Exportar snapshot para backup
drush cg-export 42 /backups/snapshot-42.json

# Rollback con dry-run primero
drush cg-rollback 42 --dry-run
drush cg-rollback 42 --force

# Rollback sin backup (usar con precaución)
drush cg-rollback 42 --force --no-backup
```

---

## Documentación de la API

Config Guardian expone varios servicios para uso programático:

### SnapshotManagerService

```php
// Obtener el servicio
$snapshotManager = \Drupal::service('config_guardian.snapshot_manager');

// Crear un snapshot
$snapshot = $snapshotManager->createSnapshot(
  'Mi Snapshot',
  'manual',
  ['description' => 'Creado vía API']
);

// Listar snapshots
$snapshots = $snapshotManager->getSnapshotList(['type' => 'manual'], 10);

// Cargar un snapshot específico
$snapshot = $snapshotManager->loadSnapshot(42);

// Comparar snapshots
$diff = $snapshotManager->compareSnapshots(41, 42);

// Eliminar un snapshot
$snapshotManager->deleteSnapshot(42);
```

### ConfigAnalyzerService

```php
$analyzer = \Drupal::service('config_guardian.config_analyzer');

// Obtener cambios pendientes
$pending = $analyzer->getPendingChanges();
// Retorna: ['create' => [...], 'update' => [...], 'delete' => [...]]

// Calcular puntuación de riesgo
$risk = $analyzer->calculateRiskScore($configNames);
// Retorna objeto RiskAssessment con score, level, riskFactors

// Analizar una configuración específica
$analysis = $analyzer->analyzeConfig('system.site');
// Retorna ConfigAnalysis con dependencies, dependents, impactScore

// Encontrar conflictos
$conflicts = $analyzer->findConflicts($configNames);

// Construir grafo de dependencias
$graph = $analyzer->buildDependencyGraph($configNames);
```

### RollbackEngineService

```php
$rollbackEngine = \Drupal::service('config_guardian.rollback_engine');

// Simular un rollback (dry-run)
$simulation = $rollbackEngine->simulateRollback(42);
// Retorna RollbackSimulation con toCreate, toUpdate, toDelete, riskAssessment
// También incluye syncToCreate, syncToUpdate, syncToDelete para cambios del directorio sync

// Ejecutar rollback con backup (por defecto)
$result = $rollbackEngine->rollbackToSnapshot(42);
// Retorna RollbackResult con success, duration, changes, errors

// Ejecutar rollback sin backup
$result = $rollbackEngine->rollbackToSnapshot(42, ['create_backup' => false]);
```

### ActivityLoggerService

```php
$logger = \Drupal::service('config_guardian.activity_logger');

// Registrar una acción personalizada
$logger->log('custom_action', [
  'description' => 'Operación personalizada realizada',
  'config_names' => ['system.site'],
  'snapshot_id' => 42,
]);

// Obtener registro de actividad
$activities = $logger->getActivityLog(['action' => 'rollback'], 50);
```

---

## Personalización Visual

### Variables CSS

Config Guardian usa propiedades personalizadas CSS para fácil personalización. Sobrescríbelas en tu tema de administración:

```css
:root {
  /* Colores primarios */
  --cg-primary: #0d6efd;
  --cg-primary-dark: #0b5ed7;

  /* Colores de estado */
  --cg-success: #198754;
  --cg-warning: #ffc107;
  --cg-danger: #dc3545;
  --cg-info: #0dcaf0;

  /* Colores de nivel de riesgo */
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

### Ubicación de Templates

Sobrescribe templates copiándolos al directorio `templates/` de tu tema:

| Template | Propósito |
|----------|-----------|
| `config-guardian-dashboard.html.twig` | Panel principal |
| `config-guardian-snapshot-view.html.twig` | Vista de snapshot individual |
| `config-guardian-compare.html.twig` | Comparación de snapshots |
| `config-guardian-impact-analysis.html.twig` | Página de análisis de impacto |
| `config-guardian-activity-log.html.twig` | Registro de actividad |
| `config-guardian-sync.html.twig` | Vista general de sincronización |

### Modo Oscuro

Config Guardian soporta automáticamente modo oscuro con temas de admin compatibles:

- **Gin**: Detectado vía clase `.gin--dark-mode`
- **Claro**: Detectado vía `[data-color-scheme="dark"]`
- **Preferencia del sistema**: Fallback a `prefers-color-scheme: dark`

---

## Solución de Problemas

### Los snapshots no se crean automáticamente

1. Asegúrate de que cron está ejecutándose: `drush cron`
2. Verifica que "Snapshot automático habilitado" está activado en ajustes
3. Comprueba que no se ha alcanzado el intervalo recientemente
4. Revisa el log de watchdog: `drush watchdog:show --type=config_guardian`

### El rollback falla con "No se pudo adquirir el bloqueo"

Otro proceso está modificando la configuración. Espera un momento y vuelve a intentar, o:

```bash
drush state:delete system.cron_last
drush cron
```

### Snapshots grandes causan problemas de memoria

1. Aumenta el límite de memoria de PHP
2. Añade configuraciones grandes a los patrones de exclusión
3. Considera usar exportación por lotes para sitios muy grandes

### El grafo de dependencias no carga

1. Limpia caché de Drupal: `drush cr`
2. Revisa la consola del navegador por errores JavaScript
3. Asegúrate de que D3.js está cargando desde el CDN
4. Prueba con otro navegador

### Errores de permiso denegado

Asegúrate de que tu rol de usuario tiene los permisos necesarios. El permiso `administer config guardian` proporciona acceso completo.

---

## Contribuir

¡Damos la bienvenida a contribuciones! Así puedes ayudar:

1. **Reportar bugs** - Usa la [cola de issues en Drupal.org](https://www.drupal.org/project/issues/config_guardian)
2. **Sugerir características** - Abre una solicitud de característica en la cola de issues
3. **Enviar parches** - Crea un parche o merge request siguiendo las [guías de contribución de Drupal](https://www.drupal.org/docs/develop/git/using-git-to-contribute-to-drupal)
4. **Mejorar documentación** - Ayuda a mejorar los docs
5. **Traducir** - Ayuda a traducir vía [localize.drupal.org](https://localize.drupal.org/)

### Configuración de Desarrollo

```bash
# Clonar el repositorio
git clone https://git.drupalcode.org/project/config_guardian.git

# Instalar dependencias
composer install

# Ejecutar tests
./vendor/bin/phpunit modules/contrib/config_guardian/tests

# Verificar estándares de código
./vendor/bin/phpcs --standard=Drupal,DrupalPractice modules/contrib/config_guardian
```

### Estándares de Código

Este proyecto sigue todos los [estándares de código de Drupal](https://www.drupal.org/docs/develop/standards):

- Sigue los [estándares PHP de Drupal](https://www.drupal.org/docs/develop/standards/coding-standards)
- Usa tipos estrictos: `declare(strict_types=1);`
- Escribe PHPDoc para todos los métodos públicos
- Incluye tests para nuevas características
- Todo el código debe pasar PHPCS con los estándares Drupal y DrupalPractice

### Seguridad

Si descubres una vulnerabilidad de seguridad, repórtala a través del [equipo de seguridad de Drupal](https://www.drupal.org/drupal-security-team) siguiendo el [proceso de avisos de seguridad](https://www.drupal.org/drupal-security-team/security-advisory-process). NO crees un issue público.

---

## Licencia

Este proyecto está licenciado bajo la **GNU General Public License v2.0 o posterior** (GPL-2.0-or-later).

Consulta la [LICENCIA](https://www.gnu.org/licenses/gpl-2.0.html) para detalles completos.

---

## Créditos

Config Guardian es desarrollado y mantenido por la comunidad Drupal.

**Contribuidores Clave:**
- Desarrollo inicial y arquitectura
- Diseño e implementación de UI/UX
- Documentación y testing

---

*Hecho con cariño para la comunidad Drupal*
