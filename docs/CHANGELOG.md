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
- **Saneamiento del Protocolo de Integración y Centinela WPML (Fase 1.3A):**
  - Prohibición formal de sentencias SQL directas (`DELETE`, etc.) contra tablas de plugins externos (`*_icl_*`).
  - Identificación y caracterización del ciclo de vida de hooks de WPML: en contexto administrativo (`WP_ADMIN = true`), WordPress y WPML enganchan naturalmente `WPML_Admin_Post_Actions::delete_post_actions` en `delete_post` y `SitePress::delete_term` en `delete_term`, eliminando automáticamente los registros creados durante la inserción de fixtures sin requerir intervención SQL manual.
  - Implementación de centinela de solo lectura reforzado: verificación pre y post prueba no sólo por conteo de filas (`COUNT(*)` = 3,403), sino mediante hash determinista MD5 del contenido ordenado de la tabla `icl_translations` (`4241ca7e7ec6399a594537cb04790c10`), garantizando delta cero absoluto e invariabilidad criptográfica.
- **Resolución Multilingüe de Contenido (Fase 1.4):**
  - Servicio de aplicación/dominio `TF\Multilingual\Domain\Translation\ContentTranslationResolver` para resolución programática de objetos WordPress (`post` y `term`).
  - Resolución simétrica y multilateral (`resolve`, `resolve_element`, `resolve_element_id`) soportando posts estándar, páginas, CPTs y taxonomías dinámicas (`term_id + taxonomy`).
  - Detección soberana del idioma de un objeto vía `language_of(element_type, element_id)` consultando exclusivamente la asignación persistida en TFML (`tfml_group_elements.language_code`) sin inferencias por URL, locale o meta.
  - Validación rigurosa del idioma destino contra `LanguageRegistry`: idiomas inexistentes arrojan `LanguageNotFoundException`, idiomas inactivos arrojan `InvalidTranslationElementException`.
  - Ausencia estricta de fallback: traducciones no existentes devuelven inequívocamente `null`, sin degradación automática a canonical ni al idioma predeterminado.
  - Resolución al mismo idioma (`ES -> ES`) devuelve el elemento correspondiente sin duplicaciones.
  - Objetos sin asignar a grupos devuelven `null` en `language_of` y `resolve` sin generar filas automáticas.
  - Manejo seguro de integridad física ante objetos eliminados en Core mediante `WordPressElementValidator::exists()`: degradación segura a `null` sin fatales ni autoreparaciones ambiguas.
  - Cache en memoria in-request por instancia para eliminación de consultas redundantes (pre-calentamiento O(1) de miembros del grupo) con método explícito `clear_cache()`.
- **Resolución de Idioma y Arquitectura de URLs (Fase 1.5 + 1.5A Consolidada):**
  - **Autoridad Lingüística de la URL:** La URL es consagrada como la fuente primaria y soberana de verdad lingüística del request, desacoplada de `get_locale()` y de cabeceras HTTP.
  - **Estructura Canónica de URLs:** El idioma predeterminado se mantiene estrictamente sin prefijo (e.g. `/`, `/tours/`). Los idiomas secundarios activos reciben prefijo canónico de primer segmento (e.g. `/en/`, `/en/tours/`, `/pt-br/hotel/`).
  - **Semántica Endurecida 1.5A ante Prefijos Inactivos:** Un prefijo correspondiente a un idioma registrado pero inactivo (e.g. `/fr/tours/` cuando `fr` está inactivo) **NUNCA** se degrada silenciosamente al idioma predeterminado (`es`). Devuelve el estado tipado `UrlLanguageResolution::STATUS_INACTIVE` en `resolve()` y `null` en `resolve_from_url()`.
  - **Protección de Slugs Desconocidos:** Segmentos raíz que no coinciden con códigos de idioma TFML (e.g. `/hotel/`, `/blog/`) no son secuestrados ni considerados errores lingüísticos; resuelven con normalidad al idioma predeterminado (`es`).
  - **Degradación Segura ante Default Inactivo:** Si el idioma predeterminado se encuentra inactivo o inconsistente, el sistema degrada de manera segura al estado tipado `STATUS_DEFAULT_INACTIVE` y devuelve `null` sin adivinar ni conjeturar otro idioma alternativo.
  - **Exclusión de Rutas del Sistema:** Prefijos de administración y endpoints técnicos (`wp-admin`, `wp-login.php`, `wp-json`, `xmlrpc.php`, `wp-cron.php`, `wp-content`, `wp-includes`) quedan formalmente excluidos (`STATUS_EXCLUDED`, `resolve_from_url() = null`).
  - **Value Object Tipado:** `TF\Multilingual\Routing\UrlLanguageResolution` para representar de forma inmutable y explícita el resultado de la resolución (`active`, `inactive`, `default_inactive`, `not_configured`, `excluded`).
  - **Servicios de Enrutamiento y Resolución:**
    - `TF\Multilingual\Routing\UrlLanguageResolver`: Servicio de análisis, normalización de rutas, extracción y saneamiento de prefijos (`strip_prefix`) con soporte transparente para instalaciones en subdirectorios.
    - `TF\Multilingual\Routing\CurrentLanguageResolver`: Proveedor autoritativo del idioma del request actual, con capacidad de simulación manual controlada (`set_current_language`, `reset`).
    - `TF\Multilingual\Routing\LocalizedUrlGenerator`: Generador lingüístico de URLs para portada (`home_url`), URLs arbitrarias (`localize_url`), posts (`get_post_translation_url`) y términos (`get_term_translation_url`).
    - **WordPress Permalinks como Fuente Soberana:** Posts, páginas y CPTs obtienen su URL base exclusivamente de `get_permalink($translated_id)`. Los términos obtienen su URL de `get_term_link($translated_id, $taxonomy)`. TFML aplica únicamente la transformación lingüística (`localize_url`), sin routers paralelos ni reconstrucción artesanal de permalinks.
    - **Endurecimiento de Transformación en `localize_url()`:** Preservación estricta de parámetros de consulta (`?search=andes&sort=asc`), fragmentos (`#section-itinerary`), puertos personalizados (`:8080`), prevención de doble prefijo, soporte de subdirectorios (`/cms/en/tours/`, nunca `/en/cms/tours/`) y política de seguridad de URLs externas (si el host difiere de `home_url()`, se devuelve inalterada).
    - **Guard de Recursión:** Bandera reentrante `$is_resolving_link` en `get_post_translation_url` y `get_term_translation_url` para blindar contra bucles infinitos durante filtros recursivos de enlace.
    - `TF\Multilingual\Routing\RewriteManager`: Inyector de reglas de reescritura en WordPress mediante filtrado dinámico de `rewrite_rules_array` y registro de query var `tfml_lang`. Genera reglas para la portada de idiomas secundarios (`^({lang})/?$`) y antepone prefijos a las reglas nativas desplazando apropiadamente los retro-capturas (`$matches[1] -> $matches[2]`). Excluye endpoints de sistema (`wp-json`, `wp-sitemap`). **Cero llamadas a `flush_rewrite_rules()` en `init`**.
    - `TF\Multilingual\Routing\SlugCollisionDetector`: Detector preventivo de colisiones para verificar si un código de idioma coincide con slugs existentes en `wp_posts` (`post_name`) o taxonomías `wp_terms`/`wp_term_taxonomy` (`slug`), garantizando que la incorporación de idiomas no opaque rutas legítimas preexistentes.
- **Pruebas y Verificación:**
  - Suite de pruebas unitarias ampliada con 6 nuevos tests suites: `UrlLanguageResolutionTest`, `UrlLanguageResolverTest`, `CurrentLanguageResolverTest`, `LocalizedUrlGeneratorTest`, `RewriteManagerTest` y `SlugCollisionDetectorTest`. Total consolidado: **154 tests, 505 assertions, 0 errores, 0 fallos**.
  - Doble `TestableWpdb` enriquecido con simulación de consultas sobre posts y términos para pruebas unitarias de colisión de slugs.
  - Verificación en vivo sobre WordPress 7.1.2 real con centinela criptográfico de solo lectura: preservación absoluta de WPML en **3,403 filas** e identidad MD5 exacta `4241ca7e7ec6399a594537cb04790c10`, 3,995 posts, 80 términos y laboratorio terminado con `tfml_settings` limpio.
- **Fronteras y Scope Respetados:**
  - Cero intervención de `WP_Query`, `pre_get_posts` o el Loop (reservado para Fase 1.6).
  - Cero escrituras directas sobre tablas externas.
  - Cero llamadas a `flush_rewrite_rules()` en `init`.

### Nota de Estado
- Esta versión incorpora la resolución soberana de idioma en URL, el generador de URLs localizadas sobre permalinks nativos de Core, el gestor de rewrite rules y el detector de colisiones. La intervención de consultas globales (`WP_Query`), filtrado del Loop y taxonomías asociadas queda estrictamente reservada para la Fase 1.6.

