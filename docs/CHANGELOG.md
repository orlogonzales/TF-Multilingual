# CHANGELOG — TF MULTILINGUAL

Todos los cambios notables de este proyecto serán documentados en este archivo.
El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/) y este proyecto se adhiere a [Semantic Versioning](https://semver.org/lang/es/).

---

## [0.1.0-dev] - 2026-10-05

### Añadido
- **Arquitectura Oficial:** Incorporación del documento técnico aprobado `docs/ARQUITECTURA-TF-MULTILINGUAL.md` (Versión 1.0.0).
- **Gobernanza y Decisión:** Documento de gobernanza `docs/GOBERNANZA.md` y registro consolidado de decisiones `docs/DECISIONES-ARQUITECTONICAS.md` (ADR-001 al ADR-017).
- **Planificación:** Definición del `docs/ROADMAP.md` para las etapas Core/MVP y Post-Core.
- **Estructura Base del Plugin:** Creación del archivo principal `tf-multilingual.php` y guard de desinstalación `uninstall.php`.
- **Núcleo de Bootstrap:** Clase inicial `TF\Multilingual\Core\Plugin` con verificación de entorno y prevención de acceso directo.
- **Tooling de Calidad:** Configuración de `composer.json` con autoloading PSR-4, configuración de PHPCS (`phpcs.xml.dist`) bajo WordPress Coding Standards (WPCS) y suite de pruebas inicial `phpunit.xml.dist`.
- **Persistencia Base y Schema Manager:** Implementación de `TF\Multilingual\Infrastructure\Persistence\SchemaManager` con soporte de versionado de esquema desacoplado (`tfml_schema_version = '1.0.0'`).
- **Tablas Maestras SQL:** DDL e instalación verificada mediante `dbDelta()` de las 5 tablas relacionales (`tfml_groups`, `tfml_group_elements`, `tfml_media_translations`, `tfml_strings`, `tfml_string_translations`).
- **Lifecycle de Activación:** Conexión de `Lifecycle::activate()` con la instalación idempotente del esquema y salvaguarda de activación por sitio en Multisite.
- **Dominio de Idiomas (Fase 1.2):**
  - Entidad inmutable / Value Object `TF\Multilingual\Domain\Language\Language` con normalización canónica de códigos y locales, validación estricta y métodos de evolución inmutable (`with_active`, `with_order`, `with_details`).
  - Jerarquía de excepciones de dominio en `TF\Multilingual\Domain\Language\Exceptions\`: `LanguageDomainException`, `InvalidLanguageException`, `LanguageNotFoundException`, `DefaultLanguageException` y `DuplicateLanguageException`.
  - Repositorio de configuración `TF\Multilingual\Domain\Language\SettingsRepository` para almacenamiento en Options API (`tfml_settings`) con autoload habilitado (`autoload = true / 'on'`) garantizando 0 queries SQL en runtime frontend, y tolerancia robusta ante datos corruptos o vacíos.
  - Servicio de dominio `TF\Multilingual\Domain\Language\LanguageRegistry` para gestión del catálogo de idiomas, protección de invariantes del idioma primario predeterminado (no puede ser desactivado ni eliminado), ordenación, activación y discriminación de estado (`is_configured()` frente a `NOT_CONFIGURED`).
- **Pruebas y Verificación:**
  - Suite de pruebas unitarias con PHPUnit: `LanguageTest`, `SettingsRepositoryTest`, `LanguageRegistryTest`, `SchemaManagerTest` y `PluginTest` (56 tests, 149 assertions, 0 errores, 0 fallos).
  - Pruebas de integración para SchemaManager (`tests/Integration/SchemaManagerIntegrationTest.php`).
  - Stubs de Options API en `tests/bootstrap.php` para testing unitario puro sin arranque de WordPress Core.
  - Verificación controlada en entorno real WordPress (HTTP 200 frontend, HTTP 302 login, verificación de autoload `on`, integridad intacta de 3,403 registros WPML y 3,995 posts).

### Nota de Estado
- Esta versión incorpora el micro-baseline de persistencia base y dominio de idiomas. No incluye interfaces de usuario (UI), asistentes ni traducción de contenido, los cuales corresponden a fases posteriores.
