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
- **Dominio de Idiomas (Fase 1.2 / 1.2A):**
  - Entidad inmutable / Value Object `TF\Multilingual\Domain\Language\Language` con normalización canónica de códigos y locales, validación estricta y métodos de evolución inmutable (`with_active`, `with_order`, `with_details`).
  - Jerarquía de excepciones de dominio en `TF\Multilingual\Domain\Language\Exceptions\`: `LanguageDomainException`, `InvalidLanguageException`, `LanguageNotFoundException`, `DefaultLanguageException` y `DuplicateLanguageException`.
  - Repositorio de configuración `TF\Multilingual\Domain\Language\SettingsRepository` para almacenamiento en Options API (`tfml_settings`) con política de autoload (`autoload = true / 'on'`) que permite a WordPress cargar esta configuración frecuente junto con sus opciones autoloaded evitando normalmente consultas individuales posteriores dentro del request, y tolerancia ante datos corruptos o vacíos.
  - Servicio de dominio `TF\Multilingual\Domain\Language\LanguageRegistry` para gestión del catálogo de idiomas, ordenación, activación y discriminación de estado (`is_configured()` frente a `NOT_CONFIGURED`).
  - **Regla Soberana de Idioma Predeterminado (Fase 1.2A):** El registro de idiomas no infiere ni auto-selecciona el idioma predeterminado. El estado permanece legítimamente en `default_language = null` (`NOT_CONFIGURED`) hasta que se defina explícitamente mediante `set_default()` o `$is_default = true`. Protección estricta de invariantes: el default debe existir, estar activo, y no puede ser desactivado ni eliminado.
- **Dominio de Grupos de Traducción y Relaciones (Fase 1.3):**
  - Entidad de dominio `TF\Multilingual\Domain\Translation\TranslationGroup` para la representación y administración de agrupaciones multilingües de objetos nativos WordPress (`post` y `term`).
  - Entidad / Value Object `TF\Multilingual\Domain\Translation\TranslationElement` para la representación de miembros individuales de un grupo con control de versiones y huella digital para detección de cambios.
  - Jerarquía de excepciones de dominio en `TF\Multilingual\Domain\Translation\Exceptions\`: `TranslationDomainException`, `TranslationGroupNotFoundException`, `TranslationConflictException` y `InvalidTranslationElementException`.
  - Servicio de validación `TF\Multilingual\Domain\Translation\WordPressElementValidator` que valida la existencia real y correspondencia de tipos/taxonomías (`term_id + taxonomy`) mediante APIs oficiales de WordPress Core.
  - Repositorio soberano `TF\Multilingual\Domain\Translation\TranslationGroupRepository` sobre las tablas `tfml_groups` y `tfml_group_elements` con soporte para:
    - Creación atómica de grupos y elementos mediante transacciones InnoDB.
    - Búsqueda bidireccional eficiente (`find`, `find_by_element`).
    - Resolución inequívoca de traducciones con representación explícita de `null` para estados SIN TRADUCIR.
    - Protección de homogeneidad de tipos/subtipos, unicidad de idioma por grupo y no concurrencia de elementos en múltiples grupos.
    - Gestión controlada y explícita del elemento canónico (`canonical_element_id`).
    - Política de eliminación limpia de relaciones sin alterar objetos WordPress anfitriones y purga automática de grupos huérfanos vacíos.
- **Pruebas y Verificación:**
  - Suite de pruebas unitarias con PHPUnit: `TranslationElementTest`, `TranslationGroupTest`, `TranslationGroupRepositoryTest`, `LanguageRegistryTest`, `LanguageTest`, `SettingsRepositoryTest`, `SchemaManagerTest` y `PluginTest` (99 tests, 302 assertions, 0 errores, 0 fallos).
  - Test double `TestableWpdb` para pruebas unitarias de persistencia relacional y concurrencia sin arrancar Core.
  - Stubs de Options API y funciones de Core en `tests/bootstrap.php`.
  - Verificación controlada en WordPress 7.1.2 real (creación de posts y términos con validación estricta de `term_id + taxonomy`, resolución multilingüe, reversibilidad absoluta, restauración a 0-delta en WPML con 3,403 filas y 3,995 posts).

### Nota de Estado
- Esta versión incorpora el núcleo de grupos de traducción y repositorio de dominio. No incluye interfaces de usuario (UI), filtros de query globales, metaboxes ni enrutamiento de URLs, los cuales corresponden a fases posteriores.
