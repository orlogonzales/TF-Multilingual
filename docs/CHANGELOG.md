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
- **Filtrado Multilingüe de Consultas y Loop (Fase 1.6):**
  - **Servicio Soberano de Filtrado `TF\Multilingual\Query\QueryLanguageFilter`:**
    - Intervención limpia y no invasiva de consultas WordPress sin secuestrar ni alterar el ciclo de ejecución de `WP_Query`.
    - Calificación de elegibilidad en `pre_get_posts` (marca query var interna de control `_tfml_target_language`).
    - Inyección SQL quirúrgica en `posts_clauses` (`join` y `where`), preservando el cálculo nativo de paginación (`found_posts`, `max_num_pages`). Prohibición absoluta de filtrado posterior vía `the_posts` o `posts_results`.
    - **Política Aprobada de Adopción Progresiva (Default-Language Ownership):**
      - Contenido gestionado en TFML (`tfml_group_elements`): filtrado estrictamente por `language_code`.
      - Contenido histórico no gestionado en TFML (`tfml_group_elements.id IS NULL`): visible exclusivamente en el idioma predeterminado (`es`). Prohibición estricta de fuga o visibilidad en idiomas secundarios.
      - Peticiones en idioma predeterminado: incluyen contenido gestionado en predeterminado + contenido no gestionado (`AND (tfml_ge.language_code = '{$default}' OR tfml_ge.id IS NULL)`).
      - Peticiones en idioma secundario: muestran exclusivamente contenido traducido al idioma secundario (`AND tfml_ge.language_code = '{$secondary}'`, Strict NO FALLBACK).
    - **Eficiencia y Cero DISTINCT:**
      - Consulta estructurada con `LEFT JOIN {$table} AS tfml_ge ON (tfml_ge.element_id = {$posts}.ID AND tfml_ge.element_type = 'post')`.
      - Omisión intencional de `DISTINCT` por diseño garantizado por el índice `UNIQUE uq_element (element_type, element_id)` que previene duplicados.
      - Optimización de rendimiento demostrada en `EXPLAIN`: resolución `ref` sobre `idx_lookup` y `eq_ref` sobre `PRIMARY`.
    - **Mecanismos de Opt-out:**
      - Soporte para deshabilitar el filtro explícitamente mediante `'tfml_suppress_language_filter' => true`.
      - Respeto total al parámetro estándar de WordPress `'suppress_filters' => true`.
    - **Aislamiento de Contextos y Exclusiones:**
      - Exclusión automática en pantallas y listados administrativos (`is_admin()`), endpoints de la API REST (`REST_REQUEST`, `wp_is_json_request()`), WP-CLI, WP-Cron, peticiones AJAX y vista previa editorial (`$query->is_preview()`).
      - Exclusión automática de post types internos (`attachment`, `revision`, `nav_menu_item`, etc.).
    - **Idempotencia Robusta:**
      - Marcado interno con `_tfml_clauses_applied => true` y detección textual previa de `tfml_group_elements` en la cláusula `join` para blindar contra doble inyección de SQL.
  - **Orquestación en `TF\Multilingual\Core\Plugin`:**
    - Integración e instanciación de `QueryLanguageFilter` coordinada con `LanguageRegistry` y `CurrentLanguageResolver`.
    - Conexión formal de hooks (`init_hooks()`) durante la inicialización del plugin.
- **Pruebas y Verificación:**
  - Suite de pruebas unitarias ampliada con `QueryLanguageFilterTest` (16 pruebas exhaustivas). Total consolidado: **170 tests, 536 assertions, 0 errores, 0 fallos**.
  - Doble `TestableWpdb` y `tests/bootstrap.php` actualizados con soporte para propiedades de WordPress Core `$wpdb->posts` e instanciación global segura para tests unitarios aislados.
  - Verificación física en entorno WordPress 7.1.2 real con 14 baterías de pruebas (consultas por idioma default, secundario, secundario sin contenido, idioma inactivo, CPTs, páginas, exclusión de attachments, paginación con `found_posts` exacto, opt-out explícito y nativo, búsquedas filtradas, singular queries sin fallback, exclusiones de contexto, alternancia a política STRICT, e inspección de plan de ejecución `EXPLAIN`).
  - Preservación íntegra de WPML en **3,403 filas** e identidad MD5 exacta `4241ca7e7ec6399a594537cb04790c10`, 3,995 posts, 80 términos y tablas TFML limpias.
- **Filtrado Multilingüe de Taxonomías y WP_Term_Query (Fase 1.7):**
  - **Servicio Soberano de Filtrado `TF\Multilingual\Query\TermQueryLanguageFilter`:**
    - Intervención limpia y no invasiva de consultas de términos en WordPress a través del hook oficial `terms_clauses` (`fields`, `join`, `where`, `distinct`, `orderby`, `order`, `limits`).
    - WordPress Core se mantiene como el propietario absoluto del motor de taxonomías (`wp_terms`, `wp_term_taxonomy`, `WP_Term_Query`, `get_terms()`, `get_categories()`). Cero motores paralelos.
    - **Identidad Canónica Rigurosa:**
      - Asociación de elementos vinculada a `element_type = 'term'`, `element_id = term_id`, y `subtype = taxonomy`. Prohibición absoluta de utilizar `term_taxonomy_id` como `element_id`.
      - Validación y acoplamiento estricto de subtipo: `tfml_groups.subtype = tt.taxonomy`, garantizando aislamiento absoluto entre taxonomías que compartan nombres o slugs coincidentes (e.g. `category` vs `post_tag` vs taxonomías personalizadas).
    - **Política de Adopción Progresiva (Default-Language Ownership) para Términos:**
      - Peticiones en idioma predeterminado (`es`): muestran términos gestionados en el idioma predeterminado con subtipo coincidente más términos históricos no gestionados en TFML (`((tfml_ge.language_code = '{$default}' AND tfml_g.subtype = tt.taxonomy) OR tfml_ge.id IS NULL)`).
      - Peticiones en idioma secundario (`en`, `pt-br`): muestran exclusivamente términos traducidos y asignados al idioma secundario con subtipo coincidente (`(tfml_ge.language_code = '{$secondary}' AND tfml_g.subtype = tt.taxonomy)`).
      - **Strict NO FALLBACK:** Un idioma secundario sin traducciones devuelve un conjunto vacío (0 términos), sin degradarse jamás al idioma default ni filtrar términos no gestionados.
    - **Protección de Consultas Directas de Identidad:**
      - Exención explícita en `is_query_eligible()` para búsquedas unívocas por identidad (`get_term($id)`, `get_term_by('slug', ...)` y `term_exists()` con `get => all` y parámetros de slug, nombre, term_taxonomy_id o include de ID único), permitiendo a WordPress Core resolver la existencia e integridad del término sin ser secuestrado por el contexto lingüístico del request.
    - **Eficiencia y Cero DISTINCT en Términos:**
      - Join estructurado mediante `LEFT JOIN {$elements} AS tfml_ge ON (tfml_ge.element_id = t.term_id AND tfml_ge.element_type = 'term') LEFT JOIN {$groups} AS tfml_g ON (tfml_g.id = tfml_ge.group_id)`.
      - Cero alteración o inyección de `DISTINCT` en la cláusula SQL, aprovechando la unicidad determinista de `UNIQUE KEY uq_element (element_type, element_id)`.
      - Verificación de plan de ejecución `EXPLAIN` confirmando accesos `eq_ref` sobre `uq_element` y `PRIMARY`.
    - **Mecanismos de Opt-out:**
      - Soporte para suprimir el filtrado mediante `'tfml_suppress_language_filter' => true`.
      - Respeto total al parámetro nativo de `WP_Term_Query` `'suppress_filter' => true` y al defensivo plural `'suppress_filters' => true`.
    - **Aislamiento de Contextos y Exclusiones:**
      - Exclusión automática en administración (`is_admin()`), endpoints REST (`REST_REQUEST`, `wp_is_json_request()`), WP-CLI, WP-Cron y AJAX.
    - **Idempotencia Robusta:**
      - Verificación previa de la tabla `tfml_group_elements` en la cláusula `join` para prevenir doble inyección sobre la misma consulta.
  - **Orquestación en `TF\Multilingual\Core\Plugin`:**
    - Registro e instanciación de `TermQueryLanguageFilter` expuesto a través de `get_term_query_filter()`.
    - Conexión de hooks (`init_hooks()`) durante la inicialización del plugin.
- **Pruebas y Verificación:**
  - Suite de pruebas unitarias ampliada con `TermQueryLanguageFilterTest` (16 pruebas exhaustivas). Total consolidado: **186 tests, 571 assertions, 0 errores, 0 fallos**.
  - Verificación física en entorno WordPress 7.1.2 real con 18 baterías de pruebas completas (consultas de categorías en default, secundario, secundario sin contenido, idioma inactivo, etiquetas `post_tag`, taxonomía personalizada `lab_destination`, aislamiento de subtipos, helpers `get_categories`, relaciones post-term vía `get_the_terms`, consultas explícitas `get_term`/`get_term_by`, `hide_empty`, `include`/`exclude`, límites y offsets, `orderby`/`order`, variaciones de `fields`, opt-outs, transición a política STRICT, y verificación de no regresión sobre `WP_Query` de Fase 1.6).
  - Preservación íntegra de WPML en **3,403 filas** e identidad MD5 exacta `4241ca7e7ec6399a594537cb04790c10`, 3,995 posts, 80 términos y tablas TFML limpias.
- **Fronteras y Scope Respetados:**
  - Cero intervención o alteración de tablas Core (`wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`).
  - Cero schema modifications (`SCHEMA_VERSION = 1.0.0`, 5 tablas relacionales intactas).
  - Cero filtrado de menús, widgets o shortcodes (reservados para fases posteriores).
  - Cero escrituras directas sobre tablas externas (`*_icl_*`).

- **Flujo Editorial Multilingüe Base (Fase 1.8):**
  - **Filosofía "WordPress sigue siendo WordPress":**
    - Integración directa y no disruptiva en las pantallas nativas de WordPress (`post.php`, `post-new.php`, `edit.php`, `term.php`, `edit-tags.php`).
    - Cero CMS paralelo, cero SPA React invasiva, cero router administrativo artificial.
  - **Servicio de Aplicación Editorial `TF\Multilingual\Editorial\TranslationEditorialService`:**
    - Orquestación centralizada de estados y acciones editoriales: asignación inicial de idioma para contenido legacy/unmanaged, creación de traducciones vinculadas al grupo original, agregación de datos de estado editorial multilingüe (`get_editorial_data`).
    - **Invariante Soberana de Creación: Estado Estricto DRAFT:**
      - Toda traducción recién creada nace estrictamente con `post_status = 'draft'`. Prohibición absoluta de publicar automáticamente traducciones derivadas.
    - **Política de No Clonación Automática (Zero Auto-Cloning):**
      - Prohibición estricta de copiar automáticamente `post_title`, `post_content`, `post_excerpt`, `postmeta`, términos taxonómicos (`wp_term_relationships`) o imagen destacada (`_thumbnail_id`). El editor humano mantiene el control editorial total.
      - Uso seguro del filtro oficial de WordPress `wp_insert_post_empty_content` para permitir la instanciación de drafts vacíos sin violar las validaciones nativas de Core.
    - **Asignación Explícita de Idioma para Contenido No Gestionado:**
      - Contenidos históricos no gestionados no infieren mágicamente su idioma; requieren una acción editorial explícita (`assign_initial_language`) que valida permisos y crea el `TranslationGroup`.
    - **Aislamiento Estricto de Subtipos y Taxonomías:**
      - Traducciones de posts preservan la invariante de `post_type`.
      - Traducciones de términos preservan la invariante de taxonomía (`category`, `post_tag`, etc.). Cero copia automática de jerarquía parental de términos.
    - **Seguridad Robusta, Verificación de Permisos y Prevención de Concurrencia:**
      - Comprobación rigurosa de capacidades de usuario mediante Core APIs (`edit_post`, `create_posts`/`edit_posts`, `edit_terms`).
      - Prevención de duplicación y carreras concurrentes respaldada por la clave única `UNIQUE(group_id, language_code)`.
      - Prevención de reasignación sobre elementos ya gestionados (`EditorialConflictException`).
      - Tratamiento informativo de idiomas inactivos: se visualizan en la interfaz pero se bloquea la creación hacia idiomas desactivados (`EditorialValidationException`).
    - **Jerarquía de Excepciones Editoriales (`TF\Multilingual\Editorial\Exceptions\`):**
      - `EditorialException`, `EditorialValidationException`, `EditorialPermissionException`, `EditorialConflictException`.
  - **Componentes de Interfaz Administrativa Nativa:**
    - `TF\Multilingual\Admin\PostEditorialUi`: Meta box lateral (`side`, `high`) compatible tanto con el Editor Clásico como con Gutenberg (Block Editor), conmutador de idioma actual para objetos unmanaged, matriz de traducciones existentes con enlaces directos a sus pantallas de edición, y acciones seguras para creación de traducciones faltantes con protección anti-CSRF vía nonces específicos.
    - `TF\Multilingual\Admin\TermEditorialUi`: Integración nativa en formularios de edición (`{$taxonomy}_edit_form_fields`) y creación (`{$taxonomy}_add_form_fields`) de términos taxonómicos, selectores de idioma y tabla de estado de traducciones.
    - Endpoints de procesamiento seguro mediante `admin-post.php` (`admin_post_tfml_create_post_translation`, `admin_post_tfml_create_term_translation`) con redirección segura a las pantallas nativas del editor.
  - **Integración en `TF\Multilingual\Core\Plugin`:**
    - Instanciación de `TranslationEditorialService`, `PostEditorialUi` y `TermEditorialUi`, y conexión de hooks en `init_hooks()`.
  - **Pruebas y Verificación:**
    - Suite de pruebas unitarias ampliada con `TranslationEditorialServiceTest` (21 pruebas exhaustivas). Total consolidado: **207 tests, 637 assertions, 0 errores, 0 fallos**.
    - Verificación física en entorno WordPress 7.1.2 real con batería completa end-to-end (`scratch/verify_fase_1_8.php`): pruebas de posts, páginas, CPTs (`lab_tour`), categorías, rechazo de idiomas inactivos, prevención de duplicados, barreras de autorización para usuarios sin privilegios (suscriptor), resoluciones bidireccionales y preservación íntegra de WPML en **3,403 filas** e identidad MD5 exacta `4241ca7e7ec6399a594537cb04790c10`, 3,995 posts, 80 términos y tablas TFML limpias.
  - **Fronteras y Scope Respetados:**
    - Cero SPA o React invasivo.
    - Cero routers administrativos paralelos.
    - Cero alteración de tablas Core ni tablas externas (`*_icl_*`).

- **Estado de Traducciones en Listados Administrativos & Zero N+1 (Fase 1.9):**
  - **Componente de UI Nativa `TF\Multilingual\Admin\AdminListColumnsUi`:**
    - Registro de columna conceptual "Idiomas" (`tfml_languages`) en listados nativos de WordPress (`edit.php`, `edit-tags.php`, `WP_List_Table`).
    - Soporte completo para entradas estándar (`post`), páginas (`page`), CPTs públicos (e.g. `tour`), y taxonomías públicas (`category`, `post_tag`, custom taxonomies).
    - Inserción elegante y contextual de la columna inmediatamente antes de la columna 'date' en posts y antes de 'posts' en taxonomías.
    - **Visualización Completa de Estados Lingüísticos:**
      1. Idioma actual del objeto: badge distinguido con código en mayúsculas (`ES`, `EN`).
      2. Traducciones existentes: enlace nativo a la pantalla de edición (`post.php?post={id}&action=edit`, `term.php?taxonomy={tax}&tag_ID={id}`) acompañado del icono de verificación nativo Dashicons (`dashicons-yes`).
      3. Traducciones faltantes: formulario POST seguro e inline hacia `admin-post.php` reutilizando los endpoints de creación de la Fase 1.8 (`tfml_create_post_translation`, `tfml_create_term_translation`) con token CSRF/nonce individual por objeto (`tfml_create_nonce`, `tfml_create_term_nonce`).
      4. Estado no gestionado (unmanaged/legacy): badge informativo "Sin idioma" (`tfml-badge--unmanaged`), sin auto-asignación y sin creación automática de grupos.
      5. Traducciones inactivas: badge informativo solo lectura (`tfml-badge--inactive`, `dashicons-hidden`), sin botón de acción de creación `+`.
    - **Accesibilidad Neta y Respeto de Estándares WordPress:**
      - Atributos `aria-label`, títulos explicativos contextuales (`title`), etiquetas para lectores de pantalla (`screen-reader-text`), y uso de códigos ISO en mayúsculas (`ES`, `EN`, `PT`). Prohibición de banderas nacionales como único indicador.
      - Comprobación estricta de capacidades (`edit_posts`, `create_posts`, `edit_others_posts`, `edit_terms`): si el usuario carece de permisos de creación/edición, se oculta el formulario y se despliega un indicador inactivo accesible (`tfml-missing--forbidden`).
  - **Garantía Crítica de Cero N+1 / Batch Loading:**
    - Método en repositorio `TF\Multilingual\Domain\Translation\TranslationGroupRepository::find_by_elements()`: recupera en lote todos los grupos y miembros asociados a un conjunto de elementos en **exactamente 3 consultas SQL** (complejidad O(1) con respecto al número de filas).
    - Pre-calentamiento O(1) en `TF\Multilingual\Domain\Translation\ContentTranslationResolver::prime_cache()` y `prime_empty()`.
    - Servicio editorial `TF\Multilingual\Editorial\TranslationEditorialService::get_editorial_data_for_elements()` con memoización en memoria in-request (`$editorial_cache`) y método `clear_editorial_cache()`.
    - Generación ultra-rápida y determinista de URLs nativas de edición (`get_edit_url()`) eliminando roundtrips redundantes y blindando contra hooks de plugins de terceros.
    - Pre-fetching en lote de posts mediante el filtro nativo `the_posts` y de términos mediante inspección única de `$GLOBALS['wp_list_table']->items`.
    - **Demostración Empírica:** Ejecución idéntica de 3 queries SQL totales para 20 posts vs 50 posts, con CERO queries ejecutadas durante el renderizado fila por fila.
  - **Pruebas y Verificación:**
    - Suite de pruebas unitarias ampliada con `AdminListColumnsUiTest` (14 pruebas exhaustivas). Total consolidado: **222 tests, 693 assertions, 0 errores, 0 fallos**.
    - Cobertura 100% limpia de PHPCS (WordPress Coding Standards) en todos los 60 archivos del proyecto (0 errores, 0 warnings).
    - Verificación física en entorno WordPress 7.1.2 real con batería completa end-to-end (`scratch/verify_fase_1_9.php`): pruebas de registro de columnas, unmanaged, managed posts, managed categories, prueba empírica O(1) 20 vs 50 rows, restricción por capabilities, no regresión de fases 1.6, 1.7 y 1.8, y preservación intacta de sentinelas de base de datos (WPML 3,403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`, 3,995 posts, 80 terms, 0 groups, 0 elements, settings NULL).
- **Motor de Políticas para Custom Fields (Fase 2.0):**
  - **Autoridad Lingüística Única sobre Custom Fields:**
    - Establecimiento de la arquitectura agnóstica de políticas: `META KEY -> POLÍTICA TFML -> TRANSLATE | SHARE | IGNORE`.
    - Cero dependencias de plugins externos de campos (ACF, Elementor, WPBakery, Yoast, Rank Math); el motor es soberano e independiente en el Core de TF Multilingual.
    - Metadatos 100% nativos: persistencia exclusiva en `wp_postmeta`, sin tablas paralelas ni esquemas duplicados de valores meta.
  - **Regla Soberana de Adopción Progresiva (Default IGNORE):**
    - Cualquier meta key no configurada explícitamente aplica estrictamente la política por defecto: `CustomFieldPolicy::IGNORE`.
    - Prohibición absoluta de heurísticas por nombre de campo (`price`, `sku`, etc.); ninguna clave asume sincronización sin configuración intencional.
  - **Enumeración y Value Object `TF\Multilingual\Domain\CustomField\CustomFieldPolicy`:**
    - Políticas normalizadas: `TRANSLATE` (`translate`), `SHARE` (`share`), `IGNORE` (`ignore`).
    - Métodos canónicos: `all()`, `is_valid()`, `normalize()` y `default()`.
  - **Servicio de Dominio `TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry`:**
    - Gestión de configuración y consulta de políticas por clave meta.
    - Coexistencia en `SettingsRepository` (`tfml_settings['custom_fields']['policies']`), garantizando que la persistencia de idiomas no degrade las políticas y viceversa.
    - Sanitización y tolerancia a corrupción ante datos inválidos en wp_options.
  - **Servicio de Sincronización `TF\Multilingual\Domain\CustomField\SharedMetaSynchronizer`:**
    - Escucha de mutaciones nativas de WordPress en `added_post_meta`, `updated_post_meta` y `deleted_post_meta`.
    - **Protección contra Recursión Infinita (Recursion Guard):** Pila de reentrancia en memoria por meta key (`$syncing[$meta_key]`), impidiendo bucles o desbordamiento de pila durante la propagación.
    - **Sincronización Bidireccional de Hermanos:** Propagación inmediata de mutaciones y eliminaciones a todos los miembros hermanos del grupo (`TranslationGroup`), incluidos borradores, elementos en papelera e idiomas inactivos para conservar la consistencia del grupo.
    - **Filtros de Exclusión y Límites:** Exclusión total de revisiones (`wp_is_post_revision`), autosaves (`wp_is_post_autosave`), objetos no gestionados (unmanaged) y grupos de un solo miembro.
    - Preservación de tipos de datos complejos y serializados, y correcta propagación de valores evaluados como falsey (`0`, `'0'`, `''`, `false`, `[]`).
  - **Integración con Flujo Editorial y Respeto a Zero Auto-Cloning:**
    - En `TranslationEditorialService::create_post_translation()`: solo las claves explícitamente configuradas con `SHARE` son inicializadas desde el post de origen hacia la nueva traducción borrador.
    - Todas las claves `TRANSLATE`, `IGNORE` y no configuradas permanecen estrictamente vacías en la nueva traducción, preservando la regla soberana de no clonación automática.
  - **Interfaz de Administración Nativa `TF\Multilingual\Admin\CustomFieldsSettingsUi`:**
    - Pantalla de ajustes nativa bajo el menú de opciones (`options-general.php?page=tfml-custom-fields`).
    - Control de capacidades `manage_options` y protección anti-CSRF vía nonces específicos.
    - Listado de políticas activas, formulario de alta/edición y acciones de borrado con confirmación.
    - Avisos informativos claros sobre la política soberana por defecto (Ignorar).
  - **Pruebas y Verificación:**
    - Suite de pruebas unitarias ampliada con `CustomFieldPolicyTest`, `CustomFieldPolicyRegistryTest`, `SharedMetaSynchronizerTest` y `CustomFieldsSettingsUiTest`. Total consolidado: **262 tests, 801 assertions, 0 errores, 0 fallos**.
    - Cobertura 100% limpia de PHPCS (WordPress Coding Standards) en todos los 68 archivos del proyecto (0 errores, 0 warnings).
    - Verificación física en entorno WordPress 7.1.2 real con batería completa end-to-end (`scratch/verify_fase_2_0.php`): pruebas de creación de traducciones con aislamiento de Zero Auto-Cloning, sincronización bidireccional forward y reverse en 3 idiomas (ES, EN, PT), aislamiento estricto de TRANSLATE e IGNORE, propagación de eliminación, inmunidad de posts unmanaged y solitarios, renderizado de UI, y preservación intacta de sentinelas de base de datos (WPML 3,403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`, 3,995 posts, 80 terms, 0 groups, 0 elements, settings NULL).

- **Adaptador para Advanced Custom Fields — ACF (Fase 2.1):**
  - **Arquitectura de Integración Desacoplada (`IntegrationManager`):**
    - Componente gestor en `TF\Multilingual\Integration\IntegrationManager` que detecta la presencia de ACF Free en tiempo de ejecución (`class_exists('ACF') || function_exists('acf')`) sin imponer dependencias estructurales duras hacia paquetes externos.
    - Cero dependencias hacia versiones comerciales: ACF Free (6.8.10) exclusivamente. Si ACF no está activo, TFML no ejecuta código ni consume recursos de integración.
  - **Componente de Integración ACF (`AcfIntegration`):**
    - Clase en `TF\Multilingual\Integration\Acf\AcfIntegration` que interactúa con el ecosistema de hooks y filtros de ACF.
    - **Inyección de Ajustes en Editor de ACF:** Enganche en `acf/render_field_settings` mediante `acf_render_field_setting()` para ofrecer la selección de política multilingüe (`tfml_policy`): `Traducir (independiente)`, `Compartir (sincronizar)`, `Ignorar (sin gestión)`, con valor por defecto `Ignorar`.
    - **Carga Dinámica de Políticas:** Filtro en `acf/load_field` que inicializa el valor de `tfml_policy` desde el registro soberano `CustomFieldPolicyRegistry` para campos existentes.
    - **Persistencia en Registro Soberano:** Filtro en `acf/update_field` que persiste la política directamente en `CustomFieldPolicyRegistry` usando la clave física de almacenamiento de WordPress (`wp_postmeta`).
    - **Mapeo Recursivo de Claves para Campos Anidados (Group Fields):** Método `get_field_storage_key()` con guardia de reentrancia (`$resolving`) y discriminación estricta de prefijos `field_`, resolviendo claves compuestas físicas (`hero_section_title`) para subcampos de grupos ACF.
    - **Emparejamiento Transparente de Reference Keys (`_{field_name}`):**
      - Cuando un campo se configura como `SHARE`, su clave interna de referencia ACF (`_{field_name}`) se registra y sincroniza automáticamente como `SHARE` en el motor soberano.
      - Al cambiar a `TRANSLATE` o `IGNORE`, la clave de referencia se elimina del registro compartido.
      - Al eliminar un campo en ACF (`acf/delete_field`), se eliminan tanto la clave de valor como la clave de referencia del registro soberano.
  - **Invalidación Inmediata de Caché de Runtime (`acf_flush_value_cache`):**
    - En `SharedMetaSynchronizer::on_meta_changed` y `on_meta_deleted`, tras propagar cambios a los posts hermanos en el grupo, se invoca `acf_flush_value_cache( $sibling_id, $meta_key )` (y para la clave desprovista de guion bajo si es de referencia).
    - Garantiza que cualquier lectura subsecuente vía `get_field()` en el mismo ciclo de petición PHP reciba los datos frescos y sincronizados sin lecturas estancadas en memoria.
  - **Integración con Creación Editorial (Zero Auto-Cloning + ACF):**
    - En `TranslationEditorialService::initialize_shared_meta()`, al crear un nuevo borrador de traducción, tanto la clave de valor (`field_name`) como su clave de referencia emparejada (`_{field_name}`) se copian al nuevo post, permitiendo que `get_field()` resuelva el campo inmediatamente en la nueva traducción.
    - Los campos `TRANSLATE` e `IGNORE` permanecen estrictamente vacíos (Zero Cloning).
  - **Encapsulación en `CustomFieldsSettingsUi`:**
    - La pantalla de configuración nativa de campos personalizados encapsula y oculta automáticamente las claves de referencia internas que inician con guion bajo (`_{field_name}`) cuando el campo base está configurado.
    - El usuario interactúa únicamente con el nombre amigable del campo, sin confusión con claves internas de ACF.
  - **Pruebas y Verificación:**
    - Suite de pruebas unitarias ampliada con `IntegrationManagerTest` y `AcfIntegrationTest`, más tests adicionales en `SharedMetaSynchronizerTest` y `CustomFieldsSettingsUiTest`. Total consolidado: **277 tests, 839 assertions, 0 errores, 0 fallos**.
    - Cobertura 100% limpia de PHPCS (WordPress Coding Standards) en todos los 72 archivos del proyecto (0 errores, 0 warnings).
    - Verificación física en entorno WordPress 7.1.2 real con ACF Free 6.8.10 activo (`scratch/verify_fase_2_1.php`): 60 aserciones pasadas (100%), validando matriz de tipos de campo (text, textarea, number, email, url, select, checkbox, true_false, group anidado), valores falsey (`0`, `'0'`, `''`, `false`, `[]`), sincronización bidireccional inmediata ES↔EN↔PT, aislamiento estricto de TRANSLATE e IGNORE, eliminación de campos, encapsulación en UI, y preservación criptográfica absoluta de sentinelas de base de datos (WPML 3,403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`, 3,995 posts, 80 terms, 0 groups, 0 elements, settings NULL).

- **Media Multilingüe Base (Fase 2.2):**
  - **Principio Fundamental de Identidad y Archivo Único:**
    - Arquitectura de un único archivo físico en disco y un único attachment post en WordPress Core compartido transparentemente entre todos los idiomas.
    - Prohibición absoluta de clonar adjuntos (e.g. Attachment #100 no se clona en #101) o duplicar archivos en uploads (`img-es.jpg`, `img-en.jpg`).
    - Desacoplamiento de `tfml_groups` y `tfml_group_elements`: los adjuntos NO son grupos de traducción, manteniendo el sistema ligero y con cero contaminación del grafo de posts.
  - **Persistencia Aislada en Tabla Dedicada (`tfml_media_translations`):**
    - Almacenamiento físico de variantes editoriales multilingües (ALT, Título, Leyenda, Descripción) en la 3ra tabla de la suite creada en Fase 1.1 (`etk_t_tfml_media_translations`).
    - Clave única `uq_attachment_language (attachment_id, language_code)` con índices optimizados.
  - **Entidad / Value Object `TF\Multilingual\Domain\Media\MediaTranslation`:**
    - Representación inmutable de metadatos editoriales de un medio por idioma.
    - Factory methods canónicos: `create()`, `create_fallback()`, `from_row()`, `to_array()`.
    - Flag explícito `is_fallback()` para distinguir variantes persistidas en TFML de metadatos heredados de WordPress Core.
  - **Repositorio de Datos `TF\Multilingual\Domain\Media\MediaTranslationRepository`:**
    - Operaciones CRUD atómicas: `find()`, `find_by_attachment()`, `find_by_attachments()`, `exists()`, `save()`, `delete()`, `delete_all_for_attachment()`.
    - Soporte nativo para recuperación por lotes en una única consulta SQL (`SELECT ... WHERE attachment_id IN (...)`).
  - **Servicio de Dominio `TF\Multilingual\Domain\Media\MediaTranslationResolver`:**
    - Resolución bajo demanda de metadatos con caché de memoria in-request (`$cache[$attachment_id][$language]`).
    - **Regla Soberana de Fallback Estricto:** Si existe variante TFML para el idioma actual, se retorna dicha variante. Si no existe, se retorna el metadato nativo de WordPress Core marcado como fallback. **NUNCA** se degrada a otro idioma secundario (e.g. PT no recibe ES ni EN).
    - Pre-calentamiento por lotes `prime_cache( array $attachment_ids, ?string $language_code )` con Zero N+1 garantizado (1 sola consulta SQL para 20 o 50 attachments, 0 consultas en lectura de memoria).
    - Adopción progresiva `adopt_core_metadata()` sin migraciones masivas automáticas.
    - Método explícito `flush_cache()`.
  - **Filtro de Frontend `TF\Multilingual\Domain\Media\MediaFrontendFilter`:**
    - Enganche en `wp_get_attachment_image_attributes` para sustitución contextual de `$attr['alt']`.
    - Cumplimiento de accesibilidad WCAG: soporte explícito para imágenes decorativas (`alt=""` en traducción TFML no cae erróneamente en el Core alt).
    - Enganche en `wp_get_attachment_caption` para filtrado contextual de leyendas.
    - Enganche en `delete_attachment` para cascada automática de eliminación en `tfml_media_translations`.
  - **Interfaz Editorial Nativa `TF\Multilingual\Admin\MediaEditorialUi`:**
    - Meta box nativo en pantalla de edición de adjuntos (`post_type = 'attachment'`) con ID `tfml_media_translations_meta_box`.
    - Pestañas dinámicas para idiomas activos, insignias de estado (Guardado TFML vs Heredado de Core), sanitización contextual según Section 26 (`sanitize_text_field`, `sanitize_textarea_field`, `wp_kses_post`), verificación de nonces y capabilities (`edit_post`).
  - **Integración con Imagen Destacada (`_thumbnail_id`):**
    - Compatible con la política `SHARE` de Fase 2.0: posts en múltiples idiomas comparten el mismo `_thumbnail_id`, y cada idioma renderiza su ALT correspondiente al llamar `get_the_post_thumbnail()`.
  - **Pruebas y Verificación:**
    - Suite de pruebas unitarias ampliada con 5 nuevas clases (`MediaTranslationTest`, `MediaTranslationRepositoryTest`, `MediaTranslationResolverTest`, `MediaFrontendFilterTest`, `MediaEditorialUiTest`). Total consolidado: **310 tests, 993 assertions, 0 errores, 0 fallos**.
    - Cobertura 100% limpia de PHPCS (WordPress Coding Standards) en todos los 82 archivos del proyecto (0 errores, 0 warnings).
    - Verificación física exhaustiva en WordPress 7.1.2 real (`scratch/verify_fase_2_2.php`): **69 aserciones pasadas (100%)**, validando archivo y attachment únicos, adopción explícita, variantes ES/EN, fallback estricto a Core en PT, hooks frontend, imágenes destacadas compartidas, UI nativa, Zero N+1 probado (1 query en lote de 20, 1 query en lote de 50, 0 en lectura), cascada de eliminación, no-regresión de ACF y políticas de campos, y preservación criptográfica idéntica de sentinelas de base de datos (WPML 3,403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`, 3,995 posts, 80 terms, 0 groups, 0 elements, 0 media rows).
- **Hardening de Verificación Media / Aislamiento Total de Plugins Externos (Fase 2.2A):**
  - **Inviolabilidad Cero Escrituras de Tablas Externas (`*_icl_*`):**
    - Prohibición absoluta de sentencias SQL directas de escritura (`DELETE`, `INSERT`, `UPDATE`, `REPLACE`) contra tablas de WPML tanto en código productivo como en scripts de verificación, pruebas y helpers.
    - Eliminación de la mutación temporal en laboratorio (`3403 -> 3474 -> 3403`) mediante aislamiento preventivo de callbacks de WPML (`ExternalPluginIsolationGuard`) durante la inserción de fixtures en el entorno.
    - Invariabilidad absoluta de sentinelas demostrada en vivo: WPML permanece en exactamente 3,403 filas (DELTA: 0) y MD5 `4241ca7e7ec6399a594537cb04790c10` constante antes, durante y después de la suite de pruebas.
  - **Limpieza Determinista Basada en APIs de Core y Bloque `try/finally`:**
    - Todas las entidades temporales de prueba (attachments, posts, archivos físicos en uploads) son rastreadas y eliminadas de forma determinista mediante `wp_delete_attachment( $id, true )` y `wp_delete_post( $id, true )` dentro de bloques `finally`.
    - Cero consultas `DELETE FROM posts WHERE ID > ...` o `DELETE FROM postmeta WHERE post_id > ...`.
    - Restauración obligatoria y verificada de todos los callbacks externos desenganchados en el bloque `finally`.
  - **Test de Fallo Controlado Premeditado (`scratch/test_controlled_failure_2_2a.php`):**
    - Demostración de robustez ante excepciones catastróficas simuladas a mitad de la ejecución: el bloque `finally` rescata y destruye todas las entidades creadas hasta el momento del fallo, restaura todos los hooks externos y preserva íntegramente las tablas anfitrionas sin corrupción.
  - **Auditoría Repositorio-Wide:**
    - Inspección exhaustiva de todo el repositorio confirmando **0 sentencias SQL de escritura** sobre tablas `*_icl_*` en la totalidad del código fuente, pruebas y scripts auxiliares.
  - **Verificación Física:**
    - `scratch/verify_fase_2_2.php`: 72 aserciones pasadas (100%).
    - `scratch/test_controlled_failure_2_2a.php`: 13 aserciones pasadas (100%).
    - `scratch/verify_fase_2_1.php`: 60 aserciones pasadas (100%).
    - PHPUnit: 310 tests, 993 assertions (100% pass).
    - PHPCS: 82 files clean (100% clean).

- **Versionado Lógico y Estado de Traducción (Fase 2.3):**
  - **Autoridad de Versionado Lógico Desacoplada de Timestamps:**
    - Prohibición estricta de marcas de tiempo (`post_modified`, `updated_at`) como criterio de vigencia.
    - El estado de traducción se deriva de la comparación matemática: `source_version_at_translation >= canonical.current_content_version` (`UPDATED`) vs `<` (`REVIEW`).
    - Objeto de valor tipado `TF\Multilingual\Domain\Translation\TranslationStatus` (`UNTRANSLATED = 'untranslated'`, `UPDATED = 'updated'`, `REVIEW = 'review'`).
  - **Huella Digital Determinista (`TF\Multilingual\Domain\Versioning\TranslatableFingerprint`):**
    - Algoritmo criptográfico SHA-256 (64 caracteres hexadecimales) que evalúa exclusivamente el contenido traducible.
    - Normalización canónica rigurosa: unificación de saltos de línea (`\r\n` y `\r` a `\n`), recorte de espacios en blanco circundantes, ordenamiento alfabético determinista de claves de metadatos (`ksort`), y preservación estricta de tipos de datos falsy (`''` vs `'0'` vs `0` vs `false` vs `null` vs `[]`).
    - Integración con políticas de Custom Fields: únicamente los metadatos registrados con política `TRANSLATE` forman parte de la huella digital. Los metadatos `SHARE` e `IGNORE` quedan estrictamente excluidos, evitando incrementos accidentales de versión o falsos positivos de revisión entre hermanos.
    - Soporte independiente para taxonomías evaluando `name`, `slug` y `description`.
    - Exclusión estricta de taxonomías y adjuntos del fingerprint del post (ciclos de vida independientes).
  - **Servicio de Dominio (`TF\Multilingual\Domain\Versioning\TranslationStatusResolver`):**
    - Resolución determinista del estado editorial en O(1) con caché en memoria in-request (`$status_cache`).
    - El elemento canónico es por definición la autoridad de origen y resuelve invariablemente como `UPDATED`.
    - Método `resolve_all_statuses( TranslationGroup $group )` para resolver en memoria todos los idiomas activos sin consultas SQL adicionales.
  - **Orquestación en Capa de Aplicación (`TranslationEditorialService`):**
    - Métodos públicos `sync_post_version( int $post_id )` y `sync_term_version( int $term_id, string $taxonomy )` que incrementan la versión del elemento ÚNICAMENTE si la huella digital ha mutado. Guardado sin cambios = 0 incremento de versión.
    - Cuando se crea una nueva traducción (`create_post_translation`, `create_term_translation`), se registra automáticamente con `source_version_at_translation = canonical.current_content_version` y su huella digital inicial, naciendo en estado `UPDATED`.
    - Actualización individual: al editar y guardar una traducción no canónica, su `source_version_at_translation` se alinea con la versión canónica actual, pasando a `UPDATED` mientras que las traducciones hermanas no modificadas permanecen legítimamente en `REVIEW`.
    - Método `mark_translation_reviewed( string $element_type, int $element_id )` que permite a los editores confirmar la vigencia de una traducción sin alterar su texto.
    - Enganches automáticos al ciclo de vida de WordPress: `save_post` (prioridad 50), `added_post_meta`, `updated_post_meta`, `deleted_post_meta` (prioridad 50) y `edited_term` (prioridad 50) con guardia de reentrancia en memoria (`$syncing_elements`).
  - **Enriquecimiento de la Interfaz de Usuario:**
    - Listados administrativos (`AdminListColumnsUi`): renderizado contextual de enlaces de traducción con distintivos visuales `dashicons-yes` (`tfml-link--translated`) para `UPDATED`, `dashicons-warning` (`tfml-link--review`) y etiqueta accesible `(requiere revisión)` para `REVIEW`, y `dashicons-plus` (`tfml-link--add`) para `UNTRANSLATED`.
    - Editores nativos (`PostEditorialUi` y `TermEditorialUi`): visualización de insignias de estado por idioma e indicación de revisión pendiente en el contenido actual.
  - **Rendimiento Zero N+1 y Escalabilidad O(1):**
    - Pre-calentamiento por lotes (`get_editorial_data_for_elements`) demostrado en pruebas en vivo: exactamente 3 consultas SQL para 20 elementos y exactamente 3 consultas SQL para 50 elementos (O(1) absoluto).
    - Renderizado de celdas de la tabla de listado: exactamente 0 consultas SQL adicionales para 20 y 50 elementos.
  - **Suite de Pruebas y Certificación:**
    - Suite de pruebas unitarias ampliada con `TranslatableFingerprintTest`, `TranslationStatusResolverTest` y casos de versionado en `TranslationEditorialServiceTest`: **327 tests, 1,030 assertions, 0 errores, 0 fallos**.
    - Validación de estándares de código (PHPCS / WPCS): **87 archivos analizados, 0 errores, 0 warnings**.
    - Verificación física en entorno real WordPress 7.1.2 (`scratch/verify_fase_2_3.php`): **67 aserciones pasadas (100%)**, con verificación estricta de invariantes de sentinela WPML (3,403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`).

### Nota de Estado
- Esta versión consolida el sistema de versionado lógico y estado de traducción de TF Multilingual (Fase 2.3), estableciendo las bases deterministas para la sincronización y auditoría editorial del catálogo multilingüe.

---

## [Fase 2.3A] - 2026-10-07

### Hardening y Calidad Final
- **Eliminación de Deprecations PHPUnit:**
  - Declaración explícita de propiedad `$description` en el doble de prueba `WP_Term` (`tests/bootstrap.php`), eliminando las dos advertencias de creación dinámica de propiedades en PHP 8.2+.
  - Suite de pruebas unitarias ahora ejecuta con **0 fallos, 0 errores, 0 deprecations**.
- **Justificación y Validación Arquitectónica de Term Slug:**
  - Documentación formal en ADR-021 justificando la inclusión de `slug` en la huella de términos (`slug ∈ fingerprint`) debido a su impacto en URLs públicas, permalinks y SEO.
  - Nueva prueba unitaria específica en `TranslatableFingerprintTest` verificando que la mutación exclusiva del slug genera una huella criptográfica distinta.
- **Invarianza de Autosaves y Revisions:**
  - Nueva prueba unitaria en `TranslationEditorialServiceTest` demostrando rigurosamente que los guardados automáticos (`DOING_AUTOSAVE`), las revisiones (`wp_is_post_revision`) y los guardados sin mutación editorial no incrementan `current_content_version` ni alteran la huella digital.
- **Regresión Cruzada Certificada:**
  - Regresión Fase 2.2 / 2.2A (Media): **72 passed, 0 failed**, 0 escrituras WPML.
  - Regresión Fase 2.1 (ACF Free): **60 passed, 0 failed**, 0 escrituras WPML.
  - Centinela WPML verificado: 3,403 filas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).

---

## [Fase 2.4] - 2026-10-07

### Menús y Navegación Multilingüe
- **Resolución Contextual de Ubicaciones (`NavMenuLocationRepository` & `NavMenuFrontendFilter`):**
  - Mapeo persistente de ubicaciones de tema por idioma (`tfml_nav_menu_locations`) en `wp_options`.
  - Invarianza total del idioma predeterminado: `theme_mod_nav_menu_locations` nativo de WordPress permanece inalterado.
  - Intercepción en frontend de `theme_mod_nav_menu_locations`, `pre_wp_nav_menu` y `wp_nav_menu_args` para resolver el menú según el idioma del request (vía mapeo explícito o grupo de traducción `TranslationGroup`).
  - Soporte de política de fallback al menú canónico cuando no existe traducción para el idioma activo.
- **Localización Dinámica de Items de Menú (`NavMenuFrontendFilter`):**
  - Reasignación de destinos de posts (`post_type` items) a sus IDs traducidos y URLs correspondientes vía `ContentTranslationResolver`.
  - Reasignación de destinos de taxonomía (`taxonomy` items) a sus términos traducidos y URLs de archivo.
  - Localización de URLs internas personalizadas (`custom` items) con prefijo de idioma activo, prevención estricta de doble prefijo (`/en/en/`), y preservación íntegra de query parameters y fragmentos.
  - URLs externas permanecen completamente inalteradas.
- **Rendimiento Zero N+1 O(1) con Pre-calentamiento por Lotes:**
  - Pre-calentamiento por lotes de todos los post targets y term targets del menú en exactamente O(1) consultas fijas (3 consultas SQL para posts + 3 consultas SQL para términos).
  - Cero consultas SQL adicionales durante el ciclo de renderizado de elementos de menú.
- **Soporte FSE / Gutenberg (`BlockNavigationFrontendFilter`):**
  - Intercepción de `render_block_data` en bloques `core/navigation`.
  - Sustitución efímera del atributo `ref` (post `wp_navigation`) por su traducción correspondiente en memoria.
  - Cero mutaciones en base de datos y sin mutar el array del bloque en memoria.
- **Interfaz Administrativa (`NavMenuEditorialUi`):**
  - Submenú bajo *Apariencia > Ubicaciones de Menú Multilingüe*.
  - Matriz visual de ubicaciones de tema registradas e idiomas activos con selección de menús traducidos.
  - Protección con capability `edit_theme_options` y validación de nonces de WordPress.
- **Verificación y Calidad:**
  - Suite de pruebas unitarias ampliada: **349 tests, 1,075 assertions, 0 errors, 0 failures, 0 deprecations**.
  - Estándares WPCS / PHPCS: **95/95 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_2_4.php`): **32/32 assertions passed**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).

---

## [Fase 2.5] - 2026-10-07

### Strings Multilingües (Cadenas de Interfaz)
- **Activación del Esquema Soberano y Modelo de Dominio (`tfml_strings` y `tfml_string_translations`):**
  - Implementación de entidades `TranslatableString`, `StringTranslation` y value object `StringStatus`.
  - Mapeo bidireccional y paridad conceptual con el modelo de versionado de Fase 2.3: `untranslated` ↔ `UNTRANSLATED`, `up_to_date` ↔ `UPDATED`, `needs_review` ↔ `REVIEW`.
  - Identidad semántica inmutable `domain + string_key` bajo ADR-013.
- **Versionado Lógico de Cadenas y Democión Determinista:**
  - `StringRepository::register` implementa registro explícito y seguro ante concurrencia.
  - El texto fuente no es la identidad: mutaciones en el texto fuente incrementan `string_version: N -> N+1` y degradan automáticamente todas las traducciones dependientes a `needs_review` (REVIEW) sin destruirlas ni sobreescribirlas.
  - Re-registros con idéntico texto fuente son estrictamente idempotentes ($0$ incremento de versión).
  - Detección explícita de conflictos de contexto (`has_conflict = 1`).
- **Política Estricta de Fallback y Cero Fallback Lateral:**
  - En idioma predeterminado se devuelve directamente el texto fuente sin sobrecarga en base de datos.
  - En idiomas secundarios, traducciones en estado `needs_review` (REVIEW) continúan sirviéndose en frontend para garantizar continuidad visual.
  - Ausencia de traducción (`UNTRANSLATED`) o idiomas inactivos degradan estrictamente al texto fuente por defecto.
  - Cero degradación lateral entre idiomas secundarios.
- **Rendimiento Zero N+1 con Pre-calentamiento por Dominio (`preload_domain`):**
  - Pre-calentamiento en $O(1)$ consultas fijas (1-2 consultas SQL por dominio).
  - Bucle de resolución de 100 cadenas verificado en laboratorio real con exactamente **0 consultas SQL**.
  - Actualización diferida de `last_seen_at` para prevenir sobrecarga de escritura en frontend.
- **Helpers Globales de Localización (`src/Domain/Strings/functions.php`):**
  - `tfml__()`, `tfml_e()`, `tfml_x()`, `tfml_esc_html__()`, `tfml_esc_attr__()`, `tfml_translate()`.
  - `tfml_n()`: contrato formal para selección singular/plural forward-compatible con motores de plurales futuros.
  - Validación de compatibilidad de tokens y placeholders (`has_matching_placeholders`).
- **Interfaz Administrativa (`StringEditorialUi`):**
  - Ubicada bajo *TF Multilingual > Cadenas de texto* en WordPress Admin nativo.
  - Filtro por dominio, búsqueda en tiempo real, registro manual, tabla de listado con distintivos de estado y edición protegida con `manage_options` y nonces.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **381 tests, 1,162 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **111/111 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_2_5.php`): **44/44 assertions passed (100%)**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).

---

## [Fase 2.6] - 2026-10-09

### SEO Multilingüe (Hreflang, Canonical y Core Sitemaps)
- **Generación Soberana de Hreflang (`HreflangGenerator`):**
  - Descubrimiento determinista de variantes traducidas para contenido singular, términos taxonómicos y portada (front page / home).
  - **Invariante de Publicación:** Exclusión estricta de variantes no publicadas (borradores, privados, papelera) e idiomas inactivos.
  - **Invariante de Frescura Editorial vs Publicación:** Variantes publicadas con estado editorial `REVIEW` (`needs_review`) permanecen plenamente indexables y son emitidas en `hreflang`.
  - **Invariante Estricta de `x-default`:** `x-default` apunta exclusivamente a la URL de la variante en el idioma predeterminado del sitio cuando existe y está publicada; si la variante predeterminada está ausente, `x-default` queda estrictamente ausente (sin invenciones ni falsos fallbacks a la home).
- **Gestión Unívoca de Canonical (`CanonicalUrlManager`):**
  - Cada variante de traducción cuenta con su propia URL canónica localizada (`rel="canonical"`), garantizando que las traducciones nunca apunten erróneamente al idioma predeterminado.
  - Intercepción limpia del hook canónico de WordPress Core (`wp_get_canonical_url`).
  - Intercepción de hooks de suites SEO de terceros (`wpseo_canonical` para Yoast SEO y `rank_math/canonical_url` para Rank Math), evitando etiquetas canónicas duplicadas o conflictivas.
- **Integración con XML Sitemaps Nativos de WordPress Core (`CoreSitemapsFilter`):**
  - Reutilización de la infraestructura nativa `wp_sitemaps` de WordPress Core sin motores paralelos.
  - Intercepción de `wp_sitemaps_posts_query_args` y `wp_sitemaps_taxonomies_query_args` con `tfml_suppress_filters => true`, permitiendo la indexación de todas las variantes sin restricción por idioma del request.
  - Exclusión de contexto sitemap (`is_sitemap()`, query var `sitemap`) en `QueryLanguageFilter` y `TermQueryLanguageFilter`.
  - Localización de URLs de entrada en `$entry['loc']` para posts y términos, descartando borradores o variantes de idiomas inactivos (`array()`).
- **Coordinación y Salida Frontend (`SeoFrontendFilter`):**
  - Inyección de etiquetas `<link rel="alternate" hreflang="..." href="..." />` en `<head>` con prioridad temprana (`wp_head`, prioridad 2).
  - Filtro extensible `tfml_hreflang_variants` para desarrolladores.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **401 tests, 1,227 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **119/119 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_2_6.php`): **48/48 assertions passed (100%)**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 2.7] - 2026-10-09

### REST API Multilingüe
- **Exposición Soberana de Endpoints bajo Namespace `/wp-json/tf-multilingual/v1/`:**
  - `RestApiRegistrar`: Orquestador desacoplado conectado al hook `rest_api_init` de WordPress Core, registrando controladores REST para idiomas, traducciones y estado de versionado.
- **Controlador de Idiomas (`LanguagesController`):**
  - Endpoint público `GET /tf-multilingual/v1/languages`: catálogo de idiomas activos configurados, con parámetro opcional `all=true` (restringido a usuarios con `manage_options`) para incluir idiomas inactivos.
  - Generación de esquema OpenAPI / JSON Schema completo para autocapacidad y validación de tipos.
- **Controlador de Traducciones (`TranslationsController`):**
  - Endpoint de consulta singular `GET /tf-multilingual/v1/translations/(?P<element_type>post|term)/(?P<id>[\d]+)`: retorna grupo de traducción, idioma asignado, variante canónica, mapa completo de traducciones hermanas con permalinks y estados editoriales, e idiomas no traducidos (`untranslated_languages`).
  - Representación explícita para elementos sin grupo asignado (`group_id => null`), retornando su idioma intrínseco si existe y todos los demás idiomas como no traducidos.
  - Endpoint de listado coleccionado `GET /tf-multilingual/v1/translations`: soporte de paginación $O(1)$ con cabeceras estándar `X-WP-Total` y `X-WP-TotalPages`, y filtrado por `element_type` y `subtype`.
  - Endpoint de vinculación editorial `POST /tf-multilingual/v1/translations/link`: asociación de objetos independientes a un grupo común con validaciones estrictas de homogeneidad y no colisión de idiomas.
  - Endpoint de desvinculación editorial `POST /tf-multilingual/v1/translations/unlink`: desasociación limpia de elementos con reasignación opcional de canonical.
- **Controlador de Estado y Versionado (`StatusController`):**
  - Endpoint de auditoría de versión `GET /tf-multilingual/v1/status/(?P<element_type>post|term)/(?P<id>[\d]+)`: inspección de `current_content_version`, `source_version_at_translation`, `translatable_fingerprint`, estado editorial lógico (`needs_review`, `is_canonical`, etc.) y detalle comparativo frente al canónico.
  - Endpoint de validación editorial `POST /tf-multilingual/v1/status/reviewed`: marca explícita de revisión completada actualizando `source_version_at_translation` a la versión canónica actual y retornando el estado fresco en la misma respuesta.
- **Seguridad Robusta Anti-IDOR y Verificación de Capacidades:**
  - Control de acceso granular según tipo de elemento (`post` vs `term`) y estado de publicación:
    - Elementos públicos (`publish` o términos) son legibles por cualquier usuario.
    - Borradores, elementos privados o no públicos requieren capabilities específicas (`read_post` / `edit_post`).
    - En operaciones de vinculación (`/translations/link`), se exige verificación dual de permisos tanto en el elemento fuente (`source_id`) como en el elemento destino (`target_id`).
    - En operaciones de mutación (`/unlink`, `/status/reviewed`), se exige autorización de edición (`edit_post` o capability de taxonomía).
  - Manejo de respuestas de autenticación/autorización con códigos HTTP estándar (`401 Unauthorized` si no autenticado, `403 Forbidden` si carece de privilegios).
- **Rendimiento y Paginación Optimizada en Base de Datos:**
  - Incorporación de `TranslationGroupRepository::paginate_groups()` para paginación $O(1)$ en endpoints de colección.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **428 tests, 1,337 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **127/127 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_2_7.php`): **47/47 assertions passed (100%)**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 2.8] - 2026-10-09

### Diagnóstico, Health Check y Cierre Integral del Core
- **Verificación y Hardening de Seguridad REST Acotada:**
  - Endpoints públicos `/translations` y `/status` actualizados para aplicar control de acceso Anti-IDOR sobre todos los elementos hermanos del grupo.
  - Ocultamiento estricto de elementos privados, borradores o restringidos ante solicitudes de usuarios no autorizados, mostrándolos en `untranslated_languages`.
  - Enmascaramiento soberano de `canonical_element_id` y `canonical_language` (`null`) en caso de que el elemento canónico sea privado o no accesible para el solicitante.
- **Servicio de Diagnóstico Desacoplado (`DiagnosticService`):**
  - Implementación en namespace `TF\Multilingual\Diagnostic` de auditoría de solo lectura integral y bajo demanda.
  - Evaluación de 6 ejes estratégicos:
    1. Entorno de ejecución (PHP >= 8.1, WordPress >= 6.8, extensiones `mbstring`, `json`, `hash`).
    2. Tablas maestras SQL (presencia física y recuento de filas en las 5 tablas relacionales oficiales).
    3. Catálogo de idiomas (configuración, idioma predeterminado activo, idiomas secundarios).
    4. Integridad relacional (detección libre de N+1 de grupos vacíos, duplicados, elementos huérfanos y desalineaciones de canonical).
    5. Reglas de reescritura y URLs (estado de permalinks y prefijos de idioma activos).
    6. Módulos funcionales del Core (Media, Strings, Menús, SEO y REST API).
- **Interfaz Administrativa Dedicada (`DiagnosticUi`):**
  - Submenú nativo bajo *TF Multilingual > Diagnóstico* (`tfml-diagnostic`).
  - Panel visual estructurado por tarjetas, distintivos de salud y resumen global con cero exposición de credenciales, rutas absolutas ni hashes sensibles.
  - Botón de auditoría interactiva bajo demanda protegido con nonces y capability `manage_options`.
- **Integración con WordPress Core Site Health (`site_status_tests`):**
  - Conexión oficial a *Herramientas > Salud del sitio* con 3 tests directos:
    - `tfml_tables_integrity`: Verificación de tablas maestras.
    - `tfml_default_language`: Verificación del idioma predeterminado.
    - `tfml_relations_integrity`: Verificación de integridad relacional.
- **Certificación y Cierre Definitivo del Core:**
  - Auditoría transversal satisfactoria: persistencia, configuración, ciclo de vida (`Lifecycle::activate()`, `Lifecycle::deactivate()`), desinstalación segura (`uninstall.php`), rendimiento y cero N+1.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **442 tests, 1,414 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **131/131 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_2_8.php`): **53/53 assertions passed (100%)**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.1] - 2026-10-09

### Hardening del Ciclo de Vida y Compatibilidad con WPBakery Page Builder
- **Hardening del Ciclo de Vida y Política de Retención (`Lifecycle` & `SchemaManager`):**
  - Implementación de `SchemaManager::drop_tables()` para borrado limpio y estructurado de las 5 tablas maestras en orden inverso de dependencia (`tfml_string_translations`, `tfml_strings`, `tfml_media_translations`, `tfml_group_elements`, `tfml_groups`).
  - Implementación de `Lifecycle::uninstall()` con política soberana de retención segura de datos por defecto (no destructiva).
  - Incorporación de opción explícita de purga `tfml_purge_data_on_uninstall` (`Lifecycle::OPTION_PURGE_ON_UNINSTALL`) que, de ser activada, ejecuta el vaciado total de tablas y opciones maestras.
  - Actualización de `uninstall.php` para invocar `Lifecycle::uninstall()` cargando de forma resiliente el autoloader de Composer si no se encuentra en memoria.
  - Creación de suite de pruebas unitarias dedicadas en `tests/Unit/LifecycleTest.php` y ampliación de `tests/Unit/SchemaManagerTest.php`.
- **Adaptador de Compatibilidad para WPBakery Page Builder (`WPBakeryIntegration`):**
  - Implementación de `TF\Multilingual\Integration\WPBakery\WPBakeryIntegration` como adaptador desacoplado para constructores basados en shortcodes (`js_composer` / Visual Composer).
  - Registro de políticas soberanas de metadatos en `CustomFieldPolicyRegistry`:
    - `_wpb_vc_js_status` => `SHARE` (sincroniza el estado del editor visual para abrir WPBakery nativamente en traducciones).
    - `_wpb_post_custom_css` => `TRANSLATE` (independencia editorial para estilos CSS específicos de cada idioma).
    - `_wpb_shortcodes_custom_css` => `TRANSLATE` (independencia editorial para estilos de shortcodes individuales).
  - Coordinación modular a través de `IntegrationManager` y conexión del servicio `MediaTranslationResolver` en `Plugin.php`.
  - Zero-Cloning selectivo: duplicación asistida de la jerarquía de shortcodes (`[vc_row]`, `[vc_column]`, etc.) en borradores de traducción vía `tfml_initial_translation_post_content`, garantizando que posts estándar y bloques Gutenberg preserven `post_content` estrictamente vacío.
  - Localización de shortcode media: reemplazo asistido de atributos `image="ID"`, `background_image="ID"` y `images="ID1,ID2"` mediante `localize_shortcode_media()`, con pre-calentamiento en memoria $O(1)$ (`MediaTranslationResolver::prime_cache()`) y filtro extensible `tfml_localize_attachment_id`.
  - Duplicación de baseline CSS inicial mediante hook `tfml_post_translation_created` manteniendo independencia editorial posterior.
- **Fronteras Estrictas Post-Core:**
  - Elementor estrictamente aislado para la Fase 3.2 sin mezclar código en esta fase.
  - Mandato de gobernanza respetado: se certifica unitaria y estructuralmente el adaptador con fixtures y pruebas reales de laboratorio, dejando constancia explícita de que la certificación funcional completa sobre un runtime con `js_composer` activo se ejecutará cuando dicho plugin esté instalado.
  - Centinela WPML 100% invariante (cero escrituras y cero bit drift).
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **460 tests, 1,460 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **134/134 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_3_1.php`): **27/27 assertions passed (100%)**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.2] - 2026-10-09

### Compatibilidad con Elementor y Elementor Pro (Localización de Árbol JSON y Runtime en Vivo)
- **Adaptador Desacoplado para Elementor (`ElementorIntegration`):**
  - Implementación de `TF\Multilingual\Integration\Elementor\ElementorIntegration` para gestión estructurada de páginas construidas con Elementor Core y Elementor Pro.
  - Detección en caliente del estado de Elementor mediante `IntegrationManager::is_elementor_active()` y `IntegrationManager::is_elementor_pro_active()`.
  - Conexión del servicio `ContentTranslationResolver` a `IntegrationManager` en `Plugin.php` para resolución de enlaces y plantillas internas.
- **Políticas Soberanas de Metadatos de Elementor:**
  - Registro de políticas en `CustomFieldPolicyRegistry` respetando personalizaciones preexistentes:
    - `_elementor_edit_mode` => `SHARE` (sincroniza el modo constructor).
    - `_elementor_template_type` => `SHARE` (sincroniza el tipo de plantilla).
    - `_elementor_version` => `SHARE` (mantiene consistencia de versión de Elementor).
    - `_elementor_pro_version` => `SHARE` (mantiene consistencia de versión de Elementor Pro).
    - `_wp_page_template` => `SHARE` (sincroniza plantilla de página, canvas, etc.).
    - `_elementor_data` => `TRANSLATE` (independencia editorial del árbol JSON de maquetación).
    - `_elementor_page_settings` => `TRANSLATE` (independencia editorial de configuración de página).
    - `_elementor_css` => `IGNORE` (la caché de estilos CSS se regenera bajo demanda por idioma).
- **Localización Segura del Árbol de Elementos JSON (`_elementor_data`):**
  - Parseo estructurado y recorrido recursivo de secciones, columnas y widgets sin expresiones regulares ciegas sobre el string JSON.
  - Preservación íntegra de la estructura técnica y de los IDs de elemento (`id: "..."`) para mantener la validez de reglas CSS personalizadas vinculadas a `.elementor-element-{id}`.
  - Localización tipada y asistida en configuraciones (`settings`):
    - Medios individuales (`image`, `photo`, `background_image`, `icon`, etc.): traducción de `id` y `url` con fallback determinista.
    - Galerías (`gallery`, `carousel`, etc.): localización de colecciones de medios.
    - Enlaces internos: traducción automática de URLs o IDs en controles de enlace apuntando a contenidos con traducción existente en el idioma destino.
    - Plantillas de Elementor (`template_id`): resolución de plantillas traducidas.
  - Persistencia segura con `wp_slash(wp_json_encode())` protegiendo contra la eliminación de escape en `update_post_meta`.
- **Zero-Cloning Selectivo e Independencia Editorial:**
  - Zero-Cloning estricto respetado para posts no construidos con Elementor.
  - Inicialización asistida vía `tfml_post_translation_created` para posts con Elementor activo, clonando y localizando el árbol inicial.
  - Verificación de independencia: modificaciones en la traducción no alteran en absoluto el post fuente original.
- **Certificación en Runtime Real con Elementor Core y Elementor Pro:**
  - Verificación en laboratorio sobre el entorno real de WordPress con Elementor 4.3.4 y Elementor Pro 4.1.0 activos.
  - Renderizado frontend en vivo probado satisfactoriamente con `\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id)` demostrando contenido localizado e independiente para español e inglés.
  - Centinela WPML 100% inalterado: 3,403 filas exactas y checksum MD5 intacto.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **471 tests, 1,495 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **136/136 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_3_2.php`): **35/35 assertions passed (100%)**.
  - Regresiones Core y constructores: Fases 3.1 (27/27), 2.8 (53/53), 2.7 (47/47), 2.6 (48/48) **100% PASS**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.3] - 2026-10-09

### Subsistema de Migración: Detección, Análisis Read-Only e Inventario Diagnóstico (WPML y Polylang)
- **Matriz de Compatibilidad de Elementor formalizada (`docs/COMPATIBILIDAD-ELEMENTOR.md`):**
  - Documentación de funciones verificadas en Elementor Core (secciones, columnas, widgets básicos, enlaces, medios, IDs técnicos, renderizado en vivo) y Pro (versión reactiva, galerías, plantillas).
  - Delimitación rigurosa de elementos pendientes en backlog (Theme Builder, formulários, dynamic tags, widgets globales, contenedores complejos) y módulos de terceros no certificados.
- **Subsistema de Migración Desacoplado (`TF\Multilingual\Migration\`):**
  - **Principio Inviolable de Solo Lectura:** Prohibición absoluta de consultas de escritura (`INSERT`, `UPDATE`, `DELETE`, etc.) sobre tablas de sistemas multilingües externos.
  - Implementación de `MigrationSourceDetector`: escaneo no invasivo de la base de datos para identificar la presencia de WPML (22 tablas `icl_*`) y Polylang (taxonomías `language`, `term_translations`).
  - Implementación de `WpmlSourceAnalyzer`:
    - Inventario exhaustivo de idiomas en `icl_languages` y correspondencia con `LanguageRegistry`.
    - Detección de elementos clasificados por `element_type` (páginas, posts, tours, rooms, taxonomías y attachments).
    - Conteo de grupos de traducción (`trid`) distinguiendo grupos de contenido editorial de attachments clonados para gobernanza Media Model B Refinado.
    - Detección de advertencias por idiomas ausentes en TFML (`fr`) y potenciales conflictos/huérfanos.
    - Simulación de migración (Dry-Run): proyección de asignación canónica y miembros de grupo sin tocar la base de datos.
  - Implementación de `PolylangSourceAnalyzer`: análisis no invasivo de taxonomías de Polylang (`language`, `term_translations`, `post_translations`).
  - Value object `MigrationInventoryReport`: estructura de datos inmutable para diagnóstico, advertencias y reportes reproducibles.
  - Orquestador `MigrationManager` integrado en `Plugin.php` accesible vía `get_migration_manager()`.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **484 tests, 1,570 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **146/146 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_3_3.php`): **38/38 assertions passed (100%)**.
  - Regresiones Core y constructores: Fases 3.2 (35/35), 3.1 (27/27), 2.8 (53/53) **100% PASS**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.4] - 2026-10-11

### Planificador Determinista y Motor de Simulación de Migración WPML → TFML (Dry-Run, Idempotencia y Checkpoints)
- **Planificador Determinista de Migración (`TF\Multilingual\Migration\WpmlMigrationPlanner`):**
  - Generación de planes de migración inmutables (`MigrationPlan`) basados en análisis exhaustivo y de solo lectura de la base de datos.
  - Clasificación semántica de grupos y elementos en cinco categorías deterministas:
    - `IMPORTABLE`: Elementos y grupos íntegros con idiomas activos (`es`, `en`), existentes en Core y sin conflictos.
    - `BLOCKED_BY_LANGUAGE`: Grupos que dependen de idiomas inactivos o no configurados en TFML (`fr`).
    - `SKIPPED`: Elementos expresamente excluidos del flujo editorial (comentarios, prioridades técnicas).
    - `ORPHAN`: Grupos cuyos posts o términos fueron eliminados de `wp_posts` o `wp_terms`.
    - `CONFLICT`: Elementos previamente mapeados a otros grupos en `tfml_group_elements`.
  - Paginación y segmentación de lotes mediante parámetros `limit` y `offset`.
- **Política Soberana de Retención Lingüística de Traducciones en Francés (`fr`):**
  - Detección explícita de `MISSING_LANGUAGE_DEPENDENCY`.
  - Cero descarte silencioso: las traducciones en `fr` se retienen formalmente en el diccionario `pending_translations['fr']` de cada grupo para su importación diferida cuando el idioma sea configurado.
  - Las traducciones en idiomas soportados (`es`, `en`) se proyectan normalmente en `translations`.
  - Prohibición estricta de auto-registro o activación no autorizada de idiomas en `LanguageRegistry` o `tfml_settings`.
  - Estado global del plan marcado como `NEEDS_CONFIGURATION`.
- **Gobernanza de Medios bajo Media Model B Refinado:**
  - Los 3,033 adjuntos detectados en WPML se excluyen de la creación de posts redundantes y se asignan a la estrategia `MEDIA_MODEL_B_DEDUPLICATION`.
  - Proyección de localización de metadatos de medios (título, alt, leyenda, descripción) en `tfml_media_translations` asociada a un único attachment compartido.
- **Subsistema de Checkpoints e Idempotencia (`TF\Multilingual\Migration\MigrationCheckpoint`):**
  - Objeto inmutable serializable que rastrea `processed_items`, `last_processed_trid`, errores y estado (`PENDING`, `IN_PROGRESS`, `COMPLETED`, `FAILED`).
  - Capacidad de serialización y reconstrucción determinista (`from_array`) para garantizar la reanudación segura ante interrupciones.
- **Ampliación de Orquestación en `MigrationManager`:**
  - Métodos `get_wpml_planner()` y `generate_plan( $source, $limit, $offset )` integrados en el orquestador principal.
---

## [Fase 3.5] - 2026-10-11

### Ejecutor Transaccional de Migraciones, Idempotencia y Recuperación ante Fallos
- **Ejecutor Transaccional de Migraciones (`TF\Multilingual\Migration\WpmlMigrationExecutor`):**
  - Procesamiento por lotes atómicos (`batch_size`) coordinados transaccionalmente con niveles de anidamiento y `SAVEPOINT`s en `TranslationGroupRepository`.
  - Mecanismo de rollback limpio: cualquier interrupción o colisión revierte en su totalidad el lote sin dejar filas huérfanas en las tablas maestras.
- **Seguridad y Gobernanza de Entorno:**
  - El ejecutor inicia cerrado por defecto (`is_authorized() = false`), arrojando `MigrationAuthorizationException` ante cualquier intento no autorizado.
  - Verificación previa de frescura y vigencia del plan (`validate_plan`): detecta alteraciones o eliminaciones en `wp_posts`/`wp_terms` desde la planificación, abortando con `MigrationPlanValidationException`.
  - Cero exposición pública: sin rutas REST, AJAX, WP-CLI ni botones de administración que expongan la ejecución en `cms.ecoterra`.
- **Control de Concurrencia (`TF\Multilingual\Migration\MigrationExecutionLock`):**
  - Sistema de exclusión mutua por fuente en Options API con token de ejecución y TTL, bloqueando ejecuciones paralelas con `MigrationConcurrencyException`.
- **Gobernanza de Checkpoints y Recuperabilidad (`TF\Multilingual\Migration\MigrationCheckpointRepository`):**
  - Persistencia de checkpoints coordinada transaccionalmente con el progreso de los lotes (`save`, `get`, `delete`).
  - Capacidad de reanudación exacta desde el último TRID procesado tras pausas o caídas de servidor.
- **Idempotencia Comprobada:**
  - Verificación previa de asignaciones: re-ejecutar el mismo lote detecta las asignaciones existentes y marca `idempotent_skipped`, garantizando delta cero en la base de datos.
- **Gobernanza de Medios bajo Media Model B Refinado:**
  - Exclusión estricta de adjuntos de los grupos editoriales, preservando la política de adjunto compartido con metadatos independientes.
- **Trazabilidad de Idiomas Pendientes (`fr`):**
  - Documentación de la relación entre los 52 grupos `BLOCKED_BY_LANGUAGE` y los 53 elementos franceses retenidos (1 elemento perteneciente al grupo técnico de prioridad de traducción `tax_translation_priority` clasificado como `SKIPPED`).
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **503 tests, 1,707 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **165/165 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_3_5.php`): **31/31 assertions passed (100%)**.
  - Regresiones Core, constructores y migración: Fases 3.4 (31/31), 3.3 (38/38), 3.2 (35/35), 3.1 (27/27), 2.8 (53/53) **100% PASS**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.6] - 2026-10-11

### Endurecimiento del Ejecutor Transaccional, Fencing Tokens y Pruebas de Recuperación
- **Fencing Tokens y Renovación Segura de Concurrencia:**
  - Incorporación de métodos `verify_ownership(string $source, string $execution_id): bool` y `renew(string $source, string $execution_id, int $ttl): bool` en `MigrationExecutionLock`.
  - Verificación estricta de posesión (fencing token) al iniciar cada lote y justo antes de confirmar (`COMMIT`) la transacción SQL en `WpmlMigrationExecutor::execute_batch()`.
  - Reversión transaccional inmediata (`ROLLBACK`) ante locks expirados o apropiados por otros procesos, evitando escrituras en split-brain o desincronizadas (`MigrationConcurrencyException`).
- **Huellas Digitales Criptográficas Deterministas del Plan (`MigrationPlan::get_fingerprint`):**
  - Cálculo de hash SHA-256 inmutable sobre el manifiesto del plan (`source`, `status`, dependencias de idiomas, métricas de resumen, estrategia de medios y grupos planificados).
  - Método `verify_fingerprint(string $expected): bool` integrado en la validación del plan para asegurar la no alteración del plan entre dry-run y ejecución.
- **Validación Avanzada de Planes Obsoletos y Divergencias de Datos:**
  - `WpmlMigrationExecutor::validate_plan()` enriquecido con:
    - Verificación física de entidades en WordPress Core mediante `WordPressElementValidator`.
    - Detección y rechazo de publicaciones enviadas a la papelera (`get_post_status() === 'trash'`).
    - Detección de divergencias en la fuente WPML (validación de correspondencia de TRID e idioma en `icl_translations`).
    - Detección de colisiones relacionales sobrevenidas en TFML introducidas tras la planificación (asignaciones parciales o conflictivas).
- **Recuperación Idempotente ante Interrupciones Post-COMMIT:**
  - Garantía demostrada de recuperación ante cortes abruptos ocurridos tras el `COMMIT` en base de datos pero antes de guardar el checkpoint en `wp_options`.
  - Detección de grupos persistidos que se clasifican como `idempotent_skipped`, avanzando el checkpoint con cero inserciones duplicadas y cero omisiones.
- **Reconciliación Profunda de Integridad Relacional (`WpmlMigrationExecutor::reconcile`):**
  - Verificación exhaustiva grupo a grupo: validación de tipo y subtipo, coincidencia del ID canónico (`get_canonical_element_id`), idioma canónico, paridad numérica y correspondencia exacta de cada traducción (`get_translation`), con reporte estructurado de discrepancias (`discrepancies`).
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **513 tests, 1,743 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **165/165 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_3_6.php`): **34/34 assertions passed (100%)**.
  - Regresiones Core, constructores y migración: Fases 3.5 (31/31), 3.4 (31/31), 3.3 (38/38), 3.2 (35/35), 3.1 (27/27), 2.8 (53/53) **100% PASS**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.



---

## [Fase 3.7] - 2026-10-11

### Validación Integral del Migrador, Reversibilidad, Motor de Rollback y Atomicidad de Locks
- **Evaluación Formal de Staging y Cumplimiento de Precondiciones:**
  - Inspección exhaustiva de entornos y bases de datos locales: se confirmó la inexistencia de una base de datos clonada/staging dedicada para `cms.ecoterra` (`cms_ect`).
  - En estricto cumplimiento con la Directiva DT-TFML-037, se detuvo formalmente la ejecución de migraciones con datos reales sobre el entorno de producción, circunscribiendo la fase a validación arquitectónica, motor de reversibilidad y pruebas sintéticas de laboratorio.
- **Motor de Rollback Transaccional (`WpmlMigrationRollbackService`):**
  - Implementación del servicio de rollback no destructivo: `rollback_execution()` y `rollback_groups()`.
  - Reversión atómica y selectiva que elimina los grupos y elementos registrados en `tfml_groups` y `tfml_group_elements` sin tocar las tablas de WordPress Core (`wp_posts`, `wp_terms`) ni las tablas de WPML (`icl_translations` permanece de solo lectura).
  - Limpieza coordinada del checkpoint (`MigrationCheckpointRepository::delete()`) y liberación forzada del lock de ejecución.
  - Integración en `MigrationManager::rollback_plan( MigrationExecutionReport $report, bool $force = false ): array`.
- **Protección contra Modificaciones Editoriales Posteriores:**
  - Detección proactiva en `inspect_dependencies()`: si un elemento importado fue editado posteriormente por redactores (`current_content_version > source_version_at_translation`), el rollback estándar (`force = false`) es bloqueado automáticamente para evitar pérdida accidental del trabajo editorial.
  - Soporte de parámetro explícito `$force = true` para que los administradores puedan forzar la reversión cuando sea necesario.
- **Atomicidad y Mitigación de Carreras en Locks:**
  - `MigrationExecutionLock::acquire()` implementa fast-path atómico mediante `add_option()`, garantizando exclusión mutua directa a nivel de base de datos MySQL frente a adquisiciones concurrentes simultáneas.
- **Enriquecimiento del Reporte de Ejecución:**
  - Método `MigrationExecutionReport::get_imported_group_ids(): array` para extraer eficientemente los IDs de grupos creados durante la ejecución.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **517 tests, 1,765 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **167/167 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_3_7.php`): **49/49 assertions passed (100%)**.
  - Regresiones Core, constructores y migración: Fases 3.6 (34/34), 3.5 (31/31), 3.4 (31/31), 3.3 (38/38), 3.2 (35/35), 3.1 (27/27), 2.8 (53/53) **100% PASS** (216/216 regresiones).
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.8] - 2026-10-11

### Preparación y Certificación del Laboratorio Aislado y Auditoría de Propiedad en Rollback
- **Aprovisionamiento y Aislamiento del Laboratorio Staging:**
  - Creación de directorio independiente en `d:\laragon\www\lab.ecoterra` con Core WordPress completo y Directory Junction hacia el repositorio de `tf-multilingual`.
  - Creación de base de datos MySQL exclusiva `cms_ect_lab` (independiente de `cms_ect` y sin uso de bases de datos de otros proyectos).
  - Configuración aislada en `wp-config.php` con credenciales separadas, URL local soberana `http://lab.ecoterra.test` y sales criptográficas exclusivas.
  - Implementación de salvaguardas de contención: `WP_HTTP_BLOCK_EXTERNAL = true`, `DISABLE_WP_CRON = true`, `DISALLOW_FILE_EDIT = true`, `AUTOMATIC_UPDATER_DISABLED = true`.
  - Creación del `mu-plugins/lab-isolation-guard.php` con cortocircuito de correos (`pre_wp_mail -> false`), forzado de cabecera y meta `X-Robots-Tag: noindex, nofollow, noarchive` y bloqueo de XML-RPC / pingbacks.
- **Esquema Limpio y Verificación de Infraestructura en `cms_ect_lab`:**
  - Instalación limpia de tablas Core (`wp_options`, `wp_posts`, `wp_postmeta`, `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`).
  - Creación de las 5 tablas maestras de TFML (`wp_tfml_groups`, `wp_tfml_group_elements`, `wp_tfml_media_translations`, `wp_tfml_strings`, `wp_tfml_string_translations`).
  - Verificación de transacciones InnoDB, confirmación (`COMMIT`) y reversión (`ROLLBACK`) de infraestructura en `cms_ect_lab`.
- **Auditoría Avanzada de Propiedad de Ejecución en el Motor de Rollback:**
  - Registro de manifiesto de ejecución (`tfml_migration_manifest_{execution_id}`) persistido por `WpmlMigrationExecutor::run()` y gestionado por `WpmlMigrationRollbackService`.
  - Verificación estricta de titularidad en `inspect_dependencies()`: los grupos que no consten en el manifiesto son clasificados como `not_owned`.
  - Inmunidad de grupos ajenos y preexistentes frente a `$force = true`: la bandera forzada solo anula el bloqueo de ediciones editoriales (`editorial_edits`) en grupos propios, pero **NUNCA** elimina grupos no creados por la ejecución.
- **Protección y Salvaguarda del Sitio Origen:**
  - Conexión al sitio origen `cms.ecoterra` (`cms_ect`) estrictamente de solo lectura (cero escrituras).
  - Constatación formal de que las tablas de WPML no han sido copiadas a `cms_ect_lab`, manteniéndose la copia real pendiente de autorización expresa del usuario.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias ampliada: **519 tests, 1.777 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **167/167 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_3_8.php`): **48/48 assertions passed (100%)**.
  - Regresiones Core, constructores y migración: Fases 3.7 (49/49), 3.6 (34/34), 3.5 (31/31), 3.4 (31/31), 3.3 (38/38), 3.2 (35/35), 3.1 (27/27), 2.8 (53/53) **100% PASS** (265/265 regresiones).
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.9] - 2026-10-11

### Preflight, Aislamiento Físico, Respaldo Verificable y Clonación Controlada
- **Aislamiento Físico del Código (Retiro de Enlace NTFS Junction):**
  - Retiro seguro y verificado del enlace NTFS Junction (`d:\laragon\www\lab.ecoterra\wp-content\plugins\tf-multilingual`) sin afectar el repositorio de desarrollo (`cms.ecoterra`).
  - Despliegue de un árbol de código físico independiente en el laboratorio, con checkout Git anclado estrictamente en el baseline certificado `af46d65a226568e6653549ed4f1eea0956189a44` y vendor propio con dependencias optimizadas.
- **Descubrimiento Dinámico de Prefijo y Estructuras:**
  - Descubrimiento programático del prefijo real de tablas (`etk_t_`) en el sitio origen `cms.ecoterra` (`cms_ect`), prohibiendo asunciones sobre el prefijo `wp_`.
  - Configuración consistente de `$table_prefix = 'etk_t_';` en `d:\laragon\www\lab.ecoterra\wp-config.php`.
- **Respaldo Streaming de Solo Lectura y Protección contra Acceso HTTP:**
  - Extracción consistente en streaming (17.19 MB) de 28 tablas seleccionadas de `cms_ect`: 22 tablas relacionales de WPML (`etk_t_icl_*`), 5 tablas Core (`etk_t_posts`, `etk_t_postmeta`, `etk_t_terms`, `etk_t_term_taxonomy`, `etk_t_term_relationships`) y configuración (`etk_t_options`).
  - Almacenamiento seguro en `d:\laragon\www\lab.ecoterra\backups\preflight_backup_cms_ect_snapshot.sql` con `.gitignore` propio, protegido fuera del repositorio del plugin.
  - Blindaje integral contra acceso HTTP en el directorio de respaldos mediante `.htaccess` (`Require all denied` / `Deny from all`), `index.php` (HTTP 403 Forbidden forzado) y `web.config` (`<deny users="*"/>` para IIS).
- **Restauración, Anonimización Profunda y Blindaje en `cms_ect_lab` (DT-TFML-039A):**
  - Restauración íntegra de las 22 tablas de WPML y 5 tablas Core en la base de datos exclusiva `cms_ect_lab`.
  - Purgado total de usuarios productivos en `etk_t_users` y generación de único administrador sintético `admin_lab` (`admin@lab.ecoterra.test`).
  - Anonimización profunda de pedidos y metadatos de clientes en `etk_t_postmeta`: sobreescritura de campos `_billing_%` y `_shipping_%` (`_billing_first_name`, `_billing_last_name`, `_billing_phone`, `_billing_email`, `_billing_address_index`, etc.) con datos sintéticos no identificables.
  - Sanitización universal de correos electrónicos en `etk_t_postmeta` (incluyendo arrays serializados PHP con recálculo de longitudes y JSON crudo de `_elementor_data`), reescritos a `@lab.ecoterra.test`.
  - Anonimización de correos electrónicos en `etk_t_posts` (`post_content` y `post_excerpt`), eliminando direcciones corporativas o personales en páginas legales y formularios de contacto.
  - Purgado de claves API, tokens y secretos de opciones en `etk_t_options` (`stripe_%`, `paypal_%`, `%token%`, `%secret%`, etc.).
  - Exclusión confirmada de la tabla `etk_t_comments` del entorno de laboratorio.
  - Preservación matemática de los datos WPML: exactamente 3.403 registros en `etk_t_icl_translations` con checksum MD5 `4241ca7e7ec6399a594537cb04790c10`.
  - Paridad de contenido Core: 4.021 posts y 82 términos restaurados exactamente.
- **Frontera Estricta de Fase y Bloqueo de Migración:**
  - Esquema maestro TFML (`etk_t_tfml_*`) inicializado en `cms_ect_lab` con exactamente 0 filas en todas las tablas (`groups`, `elements`, `media`, `strings`).
  - Bloqueo categórico de ejecución de migración: la importación con datos reales queda reservada exclusivamente para la Fase 3.10.
  - Cero alteraciones en el origen de producción `cms_ect`: Delta = 0 absoluto en centinela pre y post-flight.
- **Trazabilidad y Versionado de Herramientas (DT-TFML-039A):**
  - Actualización de `.gitignore` para registrar formalmente los scripts certificados de la fase: `scratch/verify_fase_3_9.php`, `scratch/clonar_a_lab_aislado.php`, `scratch/preflight_discovery.php` y `scratch/ExternalPluginIsolationGuard.php`.
  - Parametrización segura de credenciales de base de datos vía variables de entorno (`TFML_DB_*`) con valores predeterminados seguros para entornos locales de desarrollo, sin credenciales de producción ni datos reales embebidos en el repositorio.
- **Verificación Integral y Calidad:**
  - Suite de pruebas unitarias: **519 tests, 1.777 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
  - Estándares WPCS / PHPCS: **167/167 archivos analizados, 0 errors, 0 warnings**.
  - Verificación en laboratorio real WordPress (`scratch/verify_fase_3_9.php`): **73/73 assertions passed (100%)**.
  - Invariante centinela WPML: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.10] - 2026-10-11

### Preflight Final, Verificación de Restauración y Planificación de Migración Piloto (Dry-Run)
- **Reubicación Física del Respaldo SQL Fuera del DocumentRoot:**
  - Traslado verificado del archivo de respaldo `preflight_backup_cms_ect_snapshot.sql` (17.19 MB, SHA-256 `f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a`) al directorio del sistema `d:\laragon\backup\tfml_staging\`, ubicado de forma estricta fuera de `d:\laragon\www`.
  - Eliminación definitiva del archivo SQL en `d:\laragon\www\lab.ecoterra\backups\`, mitigando todo riesgo de descarga vía peticiones HTTP directas y preservando intactas las salvaguardas de protección de carpeta (`.htaccess` con `Require all denied`, `index.php` 403 y `web.config` deny).
- **Certificación de Restauración Verificable en Base Temporal:**
  - Procedimiento automatizado `scratch/test_restore_verify.php` que crea una base de datos efímera (`cms_ect_restore_verify`), ejecuta la restauración de las 154 sentencias SQL del respaldo en 3.58 segundos con 0 errores, verifica la integridad idéntica de las 28 tablas (3.403 registros WPML con checksum MD5 `4241ca7e7ec6399a594537cb04790c10`, 4.021 posts y 82 términos), y destruye la base temporal de manera limpia.
- **Corrección de Divergencia en WPML Migration Executor (`validate_plan`):**
  - Corrección de la consulta sobre `icl_translations` en `WpmlMigrationExecutor::validate_plan()` para discriminar por `AND element_type = %s`, impidiendo falsos positivos de divergencia en TRID ocasionados por identificadores solapados entre entidades Core (`posts`, `terms`, `comments`).
- **Resiliencia ante Taxonomías No Registradas en Contextos de CLI / Aislamiento:**
  - Implementación de mecanismo de respaldo directo contra la tabla `term_taxonomy` en `WordPressElementValidator::validate_term()` cuando `get_term()` retorna `WP_Error('invalid_taxonomy')`, asegurando validación fiel de términos asociados a taxonomías de plugins de terceros en entornos CLI de migración.
- **Inventario Determinista y Planificación Piloto sobre `cms_ect_lab`:**
  - Ejecución de `WpmlMigrationPlanner` sobre el laboratorio con datos reales clonados de WPML.
  - Validación de concordancia matemática absoluta con el inventario de la Fase 3.4 (Delta = 0 en todas las categorías): 249 grupos totales analizados (120 IMPORTABLE, 52 BLOCKED_BY_LANGUAGE por `fr`, 24 SKIPPED, 53 ORPHAN, 0 CONFLICT) y 3.033 adjuntos para deduplicación bajo `MEDIA_MODEL_B_DEDUPLICATION`.
  - Huella digital SHA-256 determinista calculada: `08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`.
  - El plan permanece en `STATUS_NEEDS_CONFIGURATION` reteniendo el francés (`fr`) sin forzar su registro automático, preservando la soberanía lingüística y editorial.
- **Validación Formal Dry-Run y Cero Escrituras en TFML:**
  - Validación formal del plan en `WpmlMigrationExecutor::validate_plan()`, certificada como 100% válida con 0 errores.
  - Cero llamadas a `execute_plan()`; retención estricta de migración en todas las 5 tablas propias de TFML (`etk_t_tfml_*`), manteniéndose en exactamente 0 registros.
  - Verificación de capacidad de reversión (rollback) certificando que grupos ajenos no registrados en el manifiesto son estrictamente bloqueados como `not_owned`.
  - Invarianza absoluta de la base de producción `cms_ect`: 3.403 filas en WPML con checksum MD5 intacto (Delta = 0 en pre y post-flight).
- **Trazabilidad y Versionado:**
  - Inclusión en `.gitignore` de las herramientas certificadas: `scratch/verify_fase_3_10.php`, `scratch/test_restore_verify.php` y `scratch/execute_lab_planner_inventory.php`.
- **Verificación Integral y Calidad:**
  - Pruebas PHPUnit: **520 tests, 1.777 assertions, 0 errors, 0 failures, 0 warnings**.
  - Estándares WPCS / PHPCS: **167/167 archivos analizados, 0 errors, 0 warnings**.
  - Verificación integral de Fase 3.10 (`scratch/verify_fase_3_10.php`): **63/63 checks PASS (100%)**.
  - Regresiones previas (Fases 3.9, 3.7, 3.6, 3.5, 3.4): **100% PASS**.
  - Centinela WPML `cms_ect`: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.11] - 2026-10-11

### Gobernanza de Planes Incompletos, Derivación Criptográfica y Preparación de Ejecución Piloto
- **Gobernanza de Planes y Bloqueo de Ejecución de Estados Incompletos:**
  - Blindaje del contrato de ejecución en `WpmlMigrationExecutor::execute_batch()` y `MigrationManager::execute_plan()`: los planes en estado `STATUS_NEEDS_CONFIGURATION` o `STATUS_BLOCKED` son categóricamente rechazados arrojando `MigrationPlanValidationException`.
  - La presencia de grupos importables en un plan matriz no permite ignorar dependencias no resueltas; la ejecución de subconjuntos exige formalmente un plan derivado.
- **Mecanismo de Derivación de Planes Parciales / Piloto (`derive_partial_plan`):**
  - Implementación del método soberano `MigrationPlan::derive_partial_plan(array $selected_trids, string $label = 'pilot')`.
  - Invarianza del plan matriz: el inventario de 249 grupos, sus clasificaciones y su huella digital SHA-256 (`08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`) permanecen estrictamente inmutables.
  - Validación de elegibilidad: los TRIDs seleccionados deben existir y estar clasificados como `CLASSIFICATION_IMPORTABLE` (los grupos bloqueados, huérfanos o excluidos son rechazados con `InvalidArgumentException`).
  - El plan derivado adquiere estado formal `STATUS_READY`, con dependencias de idioma vacías (`missing_languages = []`), linaje criptográfico `parent_fingerprint` y huella SHA-256 propia calculada deterministamente.
- **Definición del Lote Piloto Representativo (5 grupos / 9 elementos):**
  - Construcción del plan piloto calibrado (`pilot-batch-1`):
    - `TRID 4575` (`term`, `tours_cat`): Canónico 41 (`es`), Traducción 47 (`en`).
    - `TRID 9238` (`term`, `tours_cat`): Canónico 46 (`es`), Traducción 48 (`en`).
    - `TRID 9239` (`post`, `tours`): Canónico 5286 (`es`), Traducción 5287 (`en`).
    - `TRID 9247` (`post`, `tours`): Canónico 5303 (`es`), Traducción 5304 (`en`).
    - `TRID 4338` (`post`, `page`): Canónico 4338 (`es`).
  - Huella digital SHA-256 del plan piloto: `30742abbc3af9267c826aef6c24875756e829971df65993553ec75d2aa55f711`.
- **Verificación Previa de Correspondencia Core y WPML:**
  - Comprobación exhaustiva en `cms_ect_lab` de la existencia física y vigencia de los 5 TRIDs en `etk_t_icl_translations`, los posts 5286, 5287, 5303, 5304, 4338 en `etk_t_posts` (estado activo, no trash), y los términos 41, 47, 46, 48 en `etk_t_terms` y `etk_t_term_taxonomy`.
- **Validación Dry-Run del Plan Piloto y Cero Escrituras:**
  - Validación formal en `WpmlMigrationExecutor::validate_plan()` certificada al 100% con 0 errores.
  - Retención absoluta de llamadas a `execute_plan()`; las 5 tablas de TFML en `cms_ect_lab` permanecen con exactamente 0 filas.
  - Invarianza de `cms_ect`: 3.403 filas en WPML con checksum intacto (Delta = 0 en pre y post-flight).
- **Checkpoints, Manifiestos y Protección de Rollback:**
  - Checkpoint estructurado y serializable para el lote piloto de 5 elementos.
  - Prueba de titularidad en `WpmlMigrationRollbackService`: los grupos ajenos no registrados en el manifiesto son incondicionalmente bloqueados como `not_owned` (la bandera `force = true` no puede vulnerar grupos ajenos).
- **Trazabilidad y Versionado:**
  - Registro de `scratch/verify_fase_3_11.php` en `.gitignore`.
- **Verificación Integral y Calidad:**
  - Pruebas PHPUnit: **526 tests, 1.798 assertions, 0 errors, 0 failures, 0 warnings**.
  - Estándares WPCS / PHPCS: **167/167 archivos analizados, 0 errors, 0 warnings**.
  - Verificación integral de Fase 3.11 (`scratch/verify_fase_3_11.php`): **60/60 checks PASS (100%)**.
  - Regresiones previas (Fases 3.10, 3.9, 3.7, 3.6, 3.5, 3.4): **100% PASS**.
  - Centinela WPML `cms_ect`: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.12] - 2026-10-11

### Ejecución Piloto Transaccional WPML → TFML, Idempotencia y Reversibilidad Completa (DT-TFML-042 / ADENDA DT-TFML-042-A)
- **Ejecución Piloto Autorizada en Laboratorio Aislado (`cms_ect_lab`, prefijo `etk_t_`):**
  - Ejecución real transaccional acotada al lote `pilot-batch-1` (5 grupos de traducción y 9 elementos editoriales reales) mediante `WpmlMigrationExecutor::run()`:
    - `TRID 4575` (`term`, `tours_cat`): Canónico 41 (`es`), Traducción 47 (`en`).
    - `TRID 9238` (`term`, `tours_cat`): Canónico 46 (`es`), Traducción 48 (`en`).
    - `TRID 9239` (`post`, `tours`): Canónico 5286 (`es`), Traducción 5287 (`en`).
    - `TRID 9247` (`post`, `tours`): Canónico 5303 (`es`), Traducción 5304 (`en`).
    - `TRID 4338` (`post`, `page`): Canónico 4338 (`es`).
  - Huella SHA-256 autorizada: `30742abbc3af9267c826aef6c24875756e829971df65993553ec75d2aa55f711`.
  - Resultado en base de datos: exactamente 5 registros en `etk_t_tfml_groups` y 9 registros en `etk_t_tfml_group_elements`.
- **Aislamiento Total de Almacenamiento y Opciones de WordPress:**
  - Redirección dinámica de `$GLOBALS['wpdb']` a la instancia de laboratorio para garantizar que checkpoints, locks y manifiestos de ejecución se almacenen exclusivamente en `cms_ect_lab.etk_t_options`. CERO escrituras en `cms_ect` (Delta = 0 verificado por centinela pre y post-flight).
- **Auditoría de Esquema y Sincronización en Laboratorio (DT-TFML-042B):**
  - Justificación de `SchemaManager::install()`: adición inocua vía `dbDelta()` de la columna canónica `updated_at` a la tabla `etk_t_tfml_group_elements`, alineándola al 100% con la definición canónica del plugin.
  - Cero modificaciones estructurales ni relacionales en tablas de WordPress Core (`etk_t_posts`, `etk_t_terms`) ni de WPML (`etk_t_icl_*`).
  - Erradicación definitiva de operaciones `TRUNCATE` en el protocolo de certificación final; preservación física inmutable de los datos del lote piloto en el laboratorio.
- **Cobertura Exhaustiva de Pruebas Negativas (NEG-01 a NEG-13 / ADENDA DT-TFML-042-A):**
  - Certificación automatizada del 100% de los criterios explícitos de fallo:
    - **NEG-01:** Rechazo preventivo si la conexión apunta a base no autorizada (`$wpdb->dbname !== 'cms_ect_lab'`).
    - **NEG-02:** Bloqueo inmediato ante ejecutor no autorizado (`MigrationAuthorizationException`).
    - **NEG-03:** Rechazo tajante de planes con huella SHA-256 manipulada o adulterada (`MigrationPlanValidationException`).
    - **NEG-04:** Bloqueo incondicional de ejecución directa de plan matriz en `STATUS_NEEDS_CONFIGURATION`.
    - **NEG-05:** Rechazo al intentar derivar grupos bloqueados por dependencias lingüísticas (`fr`).
    - **NEG-06:** Rechazo de TRIDs ajenos al lote autorizado o inexistentes en el inventario.
    - **NEG-07:** Rechazo atómico de ejecución concurrente ante bloqueo activo de otro worker (`MigrationConcurrencyException`).
    - **NEG-08:** Recuperación de bloqueo con TTL vencido y verificación estricta de fencing token.
    - **NEG-09:** Rechazo de rollback ante manifiesto adulterado o grupo ajeno (bloqueo `not_owned`).
    - **NEG-10:** Inyección de fallo a mitad de transacción con rollback transaccional automático e InnoDB savepoint/rollback.
    - **NEG-11:** Capacidad de recuperación tras fallo previo y preservación del historial de error en el checkpoint para auditoría.
    - **NEG-12:** Renovación automática de checkpoint completado para permitir re-evaluación idempotente limpia.
    - **NEG-13:** Blindaje de registros preexistentes: rollback con `force = true` bloqueado incondicionalmente ante grupos ajenos (`not_owned`).
- **Certificación del Estado Físico Preservado del Laboratorio (`cms_ect_lab`):**
  - Conservación exacta y permanente de los 5 grupos y 9 elementos migrados en el laboratorio:
    - `etk_t_tfml_groups`: exactamente 5 filas (IDs 6, 7, 8, 9, 10).
    - `etk_t_tfml_group_elements`: exactamente 9 filas (elementos 41, 47, 46, 48, 5286, 5287, 5303, 5304, 4338).
    - Tablas TFML restantes: 0 filas.
  - Registro de ejecución oficial: `pilot_final_qJ1bqJV9`.
  - Manifiesto: `tfml_migration_manifest_pilot_final_qJ1bqJV9` con grupos `[6, 7, 8, 9, 10]`.
  - Checkpoint: `tfml_migration_checkpoint_wpml` con estado `completed`, 5 ítems procesados y 0 errores.
  - Bloqueo: completamente liberado (`is_locked = false`).
- **Idempotencia Estricta Demostrada en Base de Datos Real:**
  - Re-ejecución del plan piloto produce exactamente 0 grupos nuevos y 0 elementos duplicados; todos los 5 grupos reportan `idempotent_skipped`. Conteo en base de datos inmutable (5 grupos y 9 elementos).
- **Reversión Total Transaccional (Rollback Completo y Limpieza de Manifiesto):**
  - `WpmlMigrationRollbackService::rollback_execution()` revierte exactamente los 5 grupos y 9 elementos; las 5 tablas TFML quedan restauradas a 0 filas; WordPress Core (`etk_t_posts` con 4.021 filas y `etk_t_terms` con 82 filas) y WPML (`etk_t_icl_translations` con 3.403 filas) intactos. Manifiesto y checkpoint eliminados limpiamente.
- **Capacidad de Recuperación y Re-ejecución Post-Rollback:**
  - Re-ejecución posterior al rollback regenera limpiamente los 5 grupos y 9 elementos con 100% de integridad y conciliación verificada.
- **Trazabilidad y Versionado:**
  - `scratch/verify_fase_3_12.php` registrado en `.gitignore`.
- **Métricas de Calidad y Resultados Finales:**
  - Pruebas PHPUnit: **527 tests, 1.804 assertions, 0 errors, 0 failures, 1 skipped**.
  - Estándares WPCS / PHPCS: **167/167 archivos analizados, 0 errors, 0 warnings**.
  - Verificación Integral Fase 3.12 (`scratch/verify_fase_3_12.php`): **103/103 checks PASS (100%)**.
  - Matriz de Pruebas Negativas (NEG-01 a NEG-13): **100% PASS**.
  - Centinela WPML `cms_ect`: 3,403 filas exactas, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 absoluto).
  - Composer: `composer validate --strict` exitoso y `composer dump-autoload -o` optimizado.

---

## [Fase 3.13] - 2026-10-11

### Planificación Incremental de Migración WPML → TFML, Matriz de Estrés y Resiliencia en Fixtures Aislados, y Verificación de API Funcional (DT-TFML-043)
- **Planificación Incremental y Catalogación Determinista de los 115 Grupos Restantes:**
  - Segmentación exhaustiva de los 115 grupos importables pendientes (124 elementos) a partir de la huella matriz certificada `08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`.
  - Estructuración de 9 lotes secuenciales discretos y ordenados por dependencias relacionales, con huellas SHA-256 inmutables:
    - `batch-02-taxonomy-categories` (3 grupos / 3 elementos, TRIDs 9237, 9367, 9532, huella `9b76d7e38d779a08448474a9c22ef24b88dd3e65da292dbd64e338371a0e1d78`).
    - `batch-03-taxonomy-woocommerce` (16 grupos / 16 elementos, huella `5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945`).
    - `batch-04-taxonomy-tags-1` (23 grupos / 23 elementos, huella `b039d492f187b1fbe59b9089686c148110aa4d8ea0296f3208ea2809b3ed65f7`).
    - `batch-05-taxonomy-tags-2` (22 grupos / 22 elementos, huella `cd1c0018d1014c36c5430d1ae9aaf0272e32748b094379025fc01c99f3816e1c`).
    - `batch-06-elementor-templates` (10 grupos / 11 elementos, huella `ee32aff6cc43ea1f334b758bda34d9a9cc271de3a3c76217abd56e6264924a24`).
    - `batch-07-cpt-tours` (12 grupos / 20 elementos, huella `d24b52d1224564bd745b4bc8032a9607adef5dc00379b2634a50eed08e77888b`).
    - `batch-08-cpt-products` (7 grupos / 7 elementos, huella `3df3ce6f1a6dac619d203adaf5a31d8eb3fc332493330a5e8b755d4ff1d9136d`).
    - `batch-09-core-posts-forms` (4 grupos / 4 elementos, huella `6d188e1cbff392deaa030e917d5d43aaed482f915c218a73c87f415d39f53720`).
    - `batch-10-nav-menu-items` (18 grupos / 18 elementos, huella `19b7f15c730a7f0059b7d6873a37b07ed2bcd63f499c5a30fc921ae5d4957acc`).
  - Validación de cardinalidad: suma exacta de 115 grupos y 124 elementos, sin solapamiento de TRIDs entre lotes.
  - Salvaguarda de frontera: ninguno de los 115 grupos fue ejecutado en la base de datos real del laboratorio (`cms_ect_lab`).
- **Suite de Pruebas Unitarias de Estrés y Resiliencia (`tests/Unit/Migration/WpmlMigrationStressRecoveryTest.php`):**
  - Implementación de 6 casos de prueba unitarios que cubren interrupción pre-commit con rollback de transacciones anidadas, interrupción post-commit con reanudación por token sin duplicados, expiración de lock y fencing tokens, rechazo concurrente, rollback ante manifiestos manipulados, e idempotencia estricta.
- **Matriz de Pruebas de Estrés y Resiliencia en Fixtures Aislados (STR-01 a STR-09):**
  - `STR-01`: Interrupción antes del COMMIT con rollback transaccional automático y liberación de lock.
  - `STR-02`: Reanudación limpia tras interrupción post-commit sin duplicar registros.
  - `STR-03`: Pérdida de lock por TTL vencido y recuperación atómica por nuevo worker con fencing token.
  - `STR-04`: Serialización y recuperación de checkpoints incompletos conservando `last_processed_trid`.
  - `STR-05`: Reintento de lote duplicado reportando formalmente `idempotent_skipped`.
  - `STR-06`: Bloqueo atómico ante ejecuciones concurrentes con `MigrationConcurrencyException`.
  - `STR-07`: Detección en pre-validación de colisiones de asignación relacional con `MigrationPlanValidationException`.
  - `STR-08`: Rechazo de rollback ante manifiesto manipulado o grupo ajeno reportando `not_owned`.
  - `STR-09`: Registro detallado de auditoría (`failed`) preservando TRID, timestamp y causa técnica de fallo.
- **Pruebas Funcionales de API y Dominio sobre Laboratorio Real (`cms_ect_lab`):**
  - Validación en modo lectura sobre los datos del piloto previo:
    - `FUN-01` / `FUN-02`: Resolución bidireccional y simétrica de CPT `tours` 5286 [es] ↔ 5287 [en] vía `ContentTranslationResolver`.
    - `FUN-03`: Resolución idéntica es → es devolviendo el mismo elemento sin colisión.
    - `FUN-04`: Detección soberana del idioma de entidad vía `language_of()`.
    - `FUN-05` / `FUN-06`: Resolución de taxonomías dinámicas `tours_cat` (41 ↔ 47, 46 ↔ 48).
    - `FUN-07`: Página monolingüe 4338 hacia EN devuelve `null` sin fallback espurio.
    - `FUN-08`: `UrlLanguageResolver` resuelve correctamente rutas canónicas default, secundarias, slugs regulares y prefix inactivo (/fr/).
    - `FUN-09`: Certificación técnica de aislamiento de entorno CLI frente a peticiones HTTP.
- **Preservación Inmutable de Estado del Laboratorio y Centinelas:**
  - `cms_ect_lab`: Conserva estrictamente los 5 grupos de traducción (IDs 6, 7, 8, 9, 10) y 9 elementos del piloto de Fase 3.12, checkpoint completado y lock liberado.
  - `cms_ect`: Centinela de producción inalterado con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **533 tests, 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.13 (`scratch/verify_fase_3_13.php`): **99/99 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.14] - 2026-10-11

### Segundo Lote Piloto y Certificación HTTP (DT-TFML-044)
- **Gate de Reproducibilidad de Fase 3.13 Superado (Micro-commit 6f1f1b3):**
  - Versionado oficial e inclusión en git de `scratch/verify_fase_3_13.php`, `scratch/incremental_batches_catalog.json` y `scratch/plan_incremental_fase_3_13.php`.
  - Re-ejecución íntegra y exitosa de los 99 controles de Fase 3.13.
  - Certificación de huella criptográfica inmutable del Lote 2: `9b76d7e38d779a08448474a9c22ef24b88dd3e65da292dbd64e338371a0e1d78`.
- **Ejecución Transaccional del Lote 2 (`batch-02-taxonomy-categories`):**
  - Migración exitosa de 3 grupos y 3 elementos correspondientes a categorías (`tours_cat` y `category`) en `cms_ect_lab` (TRIDs 9237, 9367, 9532).
  - Resultado acumulado en la base de datos: exactamente 8 grupos en `etk_t_tfml_groups` (5 de piloto 1 + 3 de lote 2) y 12 elementos en `etk_t_tfml_group_elements` (9 de piloto 1 + 3 de lote 2).
  - Inalterabilidad de los 5 grupos originales (IDs 6, 7, 8, 9, 10). Tablas restantes de TFML (`media_translations`, `strings`, `string_translations`) se mantienen en 0 filas.
  - Registro de manifiesto de ejecución y actualización de checkpoint a `completed` con 3 ítems procesados. Lock liberado.
- **Idempotencia y Reversibilidad Aislada del Lote 2:**
  - Re-ejecución del Lote 2 no genera duplicados; los 3 TRIDs reportan formalmente `idempotent_skipped` y los conteos de BD permanecen inmutables en 8 grupos y 12 elementos.
  - Rollback granular de los grupos del Lote 2 devuelve la base de datos a 5 grupos y 9 elementos, con los 5 grupos del piloto previo 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado acumulado final de 8 grupos y 12 elementos con 100% de conciliación profunda sin discrepancias.
- **Certificación HTTP Real sobre Servidor Web (`http://lab.ecoterra.test`):**
  - Configuración de VirtualHost Apache y router `.htaccess` de WordPress en `d:\laragon\www\lab.ecoterra\`.
  - Pruebas HTTP reales mediante cURL:
    - Ruta raíz (`/`) responde HTTP 200 OK en idioma predeterminado.
    - Prefijos `/en/` y `/fr/` son procesados limpiamente sin errores 500.
    - Solicitudes a entidades reales: Página 4338 (Lote 1) responde 301 redirigiendo canónicamente a `/old-blog/` (HTTP 200 OK); Categoría 50 (Lote 2) responde 301 redirigiendo canónicamente a `/category/blog/` (HTTP 200 OK).
    - Ausencia absoluta de bucles de redirección (`CURLE_TOO_MANY_REDIRECTS = false`).
    - Cabecera de aislamiento `X-Robots-Tag: noindex, nofollow, noarchive` activa en todas las respuestas.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 8 grupos acumulados y 12 elementos en `cms_ect_lab`.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **533 tests, 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.14 (`scratch/verify_fase_3_14.php`): **85/85 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.15] - 2026-10-11

### Saneamiento Integral del Catálogo y Preparación del Lote 3 (DT-TFML-045)
- **Reconstrucción y Saneamiento del Catálogo Incremental:**
  - Auditoría exhaustiva sobre el plan matriz de `cms_ect_lab` (249 grupos totales, 120 importables, 115 incrementales).
  - Partición matemática disjunta de los 9 lotes incrementales (lotes 02 al 10): 0 colisiones de TRID y 0 duplicados.
  - Validación del 100% de los 124 elementos editoriales confirmando su existencia en las tablas nativas de WordPress Core (`etk_t_posts`, `etk_t_terms`, `etk_t_term_taxonomy`).
  - Generación de `scratch/incremental_batches_catalog.json` y del desglose relacional completo `scratch/incremental_batches_catalog_detailed.json`.
- **Conciliación Anatómica del Lote 3 (`batch-03-taxonomy-woocommerce`):**
  - Subsanación de erratas de tipado previas: exclusión de supuestos grupos `product_tag` y `pa_attribute`.
  - Aclaración de la errata referencial sobre TRIDs 9342..9357 (pertenecientes en realidad a `post_attachment`).
  - Certificación formal de los 16 TRIDs reales: `[4578, 4579, 4580, 4581, 4607, 4614, 4615, 4616, 4617, 4618, 4619, 4620, 4621, 4622, 4638, 9544]`.
  - Desglose verificado: 5 grupos `product_type`, 9 grupos `product_visibility` y 2 grupos `product_cat` (16 grupos / 16 elementos).
  - Huella criptográfica inmutable ratificada: `5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945`.
- **Auditoría Funcional de Rutas Frontend:**
  - Investigación de `/en/`: responde `404 Not Found` debido a que la página de inicio configurada (ID 197) tiene su traducción en inglés (Post 8356) dentro de un TRID bloqueado por francés (`BLOCKED_BY_LANGUAGE`), por lo que no está migrado a TFML.
  - Confirmación de `/fr/`: responde `404 Not Found` en la ejecución real; el reporte anterior tuvo una errata clerical de transcripción (`200 OK`). El router de TFML rechaza rutas de idiomas inactivos sin aliasing.
  - Clasificación honesta de renderizado HTML frontend como `BLOCKED` por ausencia física del directorio del tema `ecoCMS` en el laboratorio.
- **Preservación Inmutable de Estado:**
  - `cms_ect_lab`: Conserva estrictamente los 8 grupos y 12 elementos acumulados. Lote 3 permanece sin ejecutar a la espera de autorización.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.15 (`scratch/verify_fase_3_15.php`): **216/216 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.16] - 2026-10-11

### Migración Transaccional del Tercer Lote WooCommerce (DT-TFML-046)
- **Ejecución Transaccional del Lote 3 (`batch-03-taxonomy-woocommerce`):**
  - Migración exitosa de 16 grupos y 16 elementos correspondientes a taxonomías de WooCommerce en `cms_ect_lab` (TRIDs `[4578, 4579, 4580, 4581, 4607, 4614, 4615, 4616, 4617, 4618, 4619, 4620, 4621, 4622, 4638, 9544]`).
  - Desglose verificado por subtipos taxonómicos: 5 grupos `product_type`, 9 grupos `product_visibility` y 2 grupos `product_cat`.
  - Huella criptográfica inmutable ratificada: `5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945`.
  - Nuevo estado acumulado en la base de datos: exactamente **24 grupos** en `etk_t_tfml_groups` (8 previos + 16 de lote 3, asignados con IDs 41 al 56) y **28 elementos** en `etk_t_tfml_group_elements` (12 previos + 16 de lote 3).
  - Manifiesto de ejecución persistido y checkpoint actualizado a `completed` con 16 ítems procesados. Lock liberado.
- **Idempotencia y Reversibilidad Aislada del Lote 3:**
  - Re-ejecución inmediata del Lote 3 sobre el laboratorio evalúa los 16 grupos sin generar duplicados: los 16 TRIDs reportan formalmente `idempotent_skipped` y los conteos de base de datos se mantienen inmutables en 24 grupos y 28 elementos.
  - Rollback selectivo mediante `rollback_groups` sobre los IDs del Lote 3 revierte exclusivamente este lote, retornando la base de datos a 8 grupos y 12 elementos.
  - Los 8 grupos iniciales correspondientes a los lotes 1 y 2 (`[6, 7, 8, 9, 10, 38, 39, 40]`) y sus 12 elementos permanecen 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado final de 24 grupos y 28 elementos, con certificación profunda de reconciliación (`reconcile()`) al 100% (`integrity_verified = true`, 0 discrepancias).
- **Validación de API de Dominio y Comprobación HTTP:**
  - Validación funcional de `ContentTranslationResolver` sobre términos migrados (e.g. término 2 y 97, con resolución simétrica cruzada ES <-> EN).
  - Comprobaciones HTTP reales contra `http://lab.ecoterra.test`:
    - Ruta raíz (`/`) responde HTTP 200 OK en idioma predeterminado.
    - Prefijos `/en/` y `/fr/` responden limpiamente 404 (sin página traducida en EN, e idioma inactivo en FR) sin errores 500 ni degradación espuria.
    - Entidades migradas de lotes 1 y 2 responden HTTP 301 redirigiendo canónicamente a 200 OK.
    - Ausencia absoluta de bucles de redirección (`CURLE_TOO_MANY_REDIRECTS = false`).
    - Cabecera de aislamiento `X-Robots-Tag: noindex, nofollow, noarchive` activa en todas las respuestas.
  - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 24 grupos acumulados y 28 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.16 (`scratch/verify_fase_3_16.php`): **151/151 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.17] - 2026-10-11

### Migración Controlada del Cuarto Lote: Etiquetas WordPress (DT-TFML-047)
- **Ejecución Transaccional del Lote 4 (`batch-04-taxonomy-tags-1`):**
  - Migración exitosa de 23 grupos y 23 elementos correspondientes a etiquetas de WordPress (`post_tag`) en `cms_ect_lab` (TRIDs 9368 a 9390, inclusive).
  - Huella criptográfica inmutable ratificada: `b039d492f187b1fbe59b9089686c148110aa4d8ea0296f3208ea2809b3ed65f7`.
  - Nuevo estado acumulado en la base de datos: exactamente **47 grupos** en `etk_t_tfml_groups` (24 previos + 23 de lote 4) y **51 elementos** en `etk_t_tfml_group_elements` (28 previos + 23 de lote 4).
  - Manifiesto de ejecución persistido y checkpoint actualizado a `completed` con 23 ítems procesados. Lock liberado.
- **Trazabilidad Dinámica y Titularidad por Manifiesto:**
  - Eliminación de suposiciones de IDs autoincrementales consecutivos; captura dinámica de IDs en el manifiesto de ejecución y preservación formal del conjunto previo de 24 grupos.
- **Idempotencia y Reversibilidad Aislada del Lote 4:**
  - Re-ejecución inmediata del Lote 4 sobre el laboratorio evalúa los 23 grupos sin generar duplicados: los 23 TRIDs reportan formalmente `idempotent_skipped` y los conteos de base de datos se mantienen inmutables en 47 grupos y 51 elementos.
  - Rollback selectivo mediante `rollback_groups` sobre los IDs del Lote 4 revierte exclusivamente este lote, retornando la base de datos a 24 grupos y 28 elementos.
  - Los 24 grupos acumulados de los lotes 1, 2 y 3 permanecen 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado final de 47 grupos y 51 elementos, con certificación profunda de reconciliación (`reconcile()`) al 100% (`integrity_verified = true`, 0 discrepancias).
- **Validación de API de Dominio y Comprobación HTTP:**
  - Validación funcional de `ContentTranslationResolver` sobre términos de etiquetas migradas (e.g. términos 51 'Cusco', 52 'Machu Picchu', 53 'Eco Lodges' en idioma `es`; resolución hacia `en` devuelve `null` sin fallback espurio).
  - Comprobaciones HTTP reales contra `http://lab.ecoterra.test`:
    - Ruta raíz (`/`) responde HTTP 200 OK en idioma predeterminado.
    - Prefijos `/en/` y `/fr/` responden limpiamente 404 (sin página traducida en EN, e idioma inactivo en FR) sin errores 500 ni degradación espuria.
    - Entidades migradas de lotes 1 y 2 responden HTTP 301 redirigiendo canónicamente a 200 OK.
    - Consulta de etiqueta migrada responde limpiamente (HTTP 200 o 301 canónico).
    - Ausencia absoluta de bucles de redirección (`CURLE_TOO_MANY_REDIRECTS = false`).
    - Cabecera de aislamiento `X-Robots-Tag: noindex, nofollow, noarchive` activa en todas las respuestas.
  - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 47 grupos acumulados y 51 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.17 (`scratch/verify_fase_3_17.php`): **260/260 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.18] - 2026-10-11

### Migración Controlada del Quinto Lote: Etiquetas WordPress Parte 2 (DT-TFML-048 y DT-TFML-048-R1)
- **Activación de STOP Preventivo y Ratificación del Catálogo:**
  - Detención inmediata preflight tras detectar divergencia entre el rango continuo 9391-9412 y el catálogo oficial de Fase 3.15.
  - Ratificación por la Dirección Técnica mediante DT-TFML-048-R1, consagrando la regla de oro: los TRID de cada lote proceden siempre directamente del catálogo oficial versionado, sin deducción por rangos.
- **Ejecución Transaccional del Lote 5 (`batch-05-taxonomy-tags-2`):**
  - Migración exitosa de 22 grupos y 22 elementos correspondientes a etiquetas de WordPress (`post_tag`) en `cms_ect_lab` (TRIDs `[9392, 9393, 9394, 9395, 9396, 9397, 9398, 9400, 9401, 9402, 9403, 9404, 9407, 9408, 9409, 9410, 9412, 9413, 9414, 9415, 9416, 9417]`).
  - Huella criptográfica inmutable ratificada: `cd1c0018d1014c36c5430d1ae9aaf0272e32748b094379025fc01c99f3816e1c`.
  - Nuevo estado acumulado en la base de datos: exactamente **69 grupos** en `etk_t_tfml_groups` (47 previos + 22 de lote 5) y **73 elementos** en `etk_t_tfml_group_elements` (51 previos + 22 de lote 5), alcanzando el 57,5% de cobertura de grupos importables del universo WPML.
  - Manifiesto de ejecución persistido y checkpoint actualizado a `completed` con 22 ítems procesados. Lock liberado.
- **Idempotencia y Reversibilidad Aislada del Lote 5:**
  - Re-ejecución inmediata del Lote 5 sobre el laboratorio evalúa los 22 grupos sin generar duplicados: los 22 TRIDs reportan formalmente `idempotent_skipped` y los conteos de base de datos se mantienen inmutables en 69 grupos y 73 elementos.
  - Rollback selectivo mediante `rollback_groups` sobre los IDs del Lote 5 revierte exclusivamente este lote, retornando la base de datos a 47 grupos y 51 elementos.
  - Los 47 grupos acumulados de los lotes 1 al 4 permanecen 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado final de 69 grupos y 73 elementos, con certificación profunda de reconciliación (`reconcile()`) al 100% (`integrity_verified = true`, 0 discrepancias).
- **Validación de API de Dominio y Comprobación HTTP:**
  - Validación funcional de `ContentTranslationResolver` sobre términos de etiquetas migradas (e.g. términos 74, 75, 95 en idioma `es`; resolución hacia `en` devuelve `null` sin fallback espurio).
  - Comprobaciones HTTP reales contra `http://lab.ecoterra.test`:
    - Ruta raíz (`/`) responde HTTP 200 OK en idioma predeterminado.
    - Prefijos `/en/` y `/fr/` responden limpiamente 404 (sin página traducida en EN, e idioma inactivo en FR) sin errores 500 ni degradación espuria.
    - Entidades migradas de lotes anteriores responden HTTP 301 redirigiendo canónicamente a 200 OK.
    - Consulta de etiqueta migrada responde limpiamente sin error 500.
    - Ausencia absoluta de bucles de redirección (`CURLE_TOO_MANY_REDIRECTS = false`).
    - Cabecera de aislamiento `X-Robots-Tag: noindex, nofollow, noarchive` activa en todas las respuestas.
  - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 69 grupos acumulados y 73 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.18 (`scratch/verify_fase_3_18.php`): **281/281 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.19] - 2026-10-11

### Migración Controlada del Sexto Lote: Plantillas y Tipos Elementor (DT-TFML-049)
- **Ejecución Transaccional del Lote 6 (`batch-06-elementor-templates`):**
  - Migración exitosa de 10 grupos y 11 elementos correspondientes a plantillas y tipos de Elementor (`elementor_library` y `elementor_library_type`) en `cms_ect_lab` (TRIDs `[6, 1300, 1713, 1723, 2365, 3423, 4130, 4532, 4533, 9208]`). Desglose exacto: 8 grupos de plantillas `elementor_library` (9 elementos: 7 grupos monolingües de 1 elemento + 1 grupo bilingüe TRID 9208 de 2 elementos) y 2 grupos de términos `elementor_library_type` (2 elementos: 2 grupos monolingües de 1 elemento).
  - Incorporación del primer grupo bilingüe de plantillas: TRID 9208 con Post 5048 en español ("Testimonios") y Post 5055 en inglés ("Testimonial").
  - Huella criptográfica inmutable ratificada: `ee32aff6cc43ea1f334b758bda34d9a9cc271de3a3c76217abd56e6264924a24`.
  - Nuevo estado acumulado en la base de datos: exactamente **79 grupos** en `etk_t_tfml_groups` (69 previos + 10 de lote 6) y **84 elementos** en `etk_t_tfml_group_elements` (73 previos + 11 de lote 6), alcanzando el 65,8% de cobertura de grupos importables del universo WPML.
  - Manifiesto de ejecución persistido y checkpoint actualizado a `completed` con 10 ítems procesados. Lock liberado.
- **Invarianza Criptográfica de Metadatos y JSON Elementor:**
  - Captura y comparación de snapshots pre y post-migración sobre los 9 posts de biblioteca y 2 términos de tipo: certificación de Delta Cero absoluto en `etk_t_posts`, `etk_t_postmeta` y estructuras JSON serializadas en `_elementor_data` (hashes MD5 idénticos al 100%).
- **Idempotencia y Reversibilidad Aislada del Lote 6:**
  - Re-ejecución inmediata del Lote 6 sobre el laboratorio evalúa los 10 grupos sin generar duplicados: los 10 TRIDs reportan formalmente `idempotent_skipped` y los conteos de base de datos se mantienen inmutables en 79 grupos y 84 elementos.
  - Rollback selectivo mediante `rollback_groups` sobre los IDs del Lote 6 revierte exclusivamente este lote, retornando la base de datos a 69 grupos y 73 elementos.
  - Los 69 grupos acumulados de los lotes 1 al 5 permanecen 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado final de 79 grupos y 84 elementos, con certificación profunda de reconciliación (`reconcile()`) al 100% (`integrity_verified = true`, 0 discrepancias).
- **Validación de API de Dominio y Comprobación HTTP:**
  - Validación funcional de `ContentTranslationResolver` sobre plantillas Elementor: resolución simétrica cruzada ES <-> EN en TRID 9208 (Post 5048 hacia EN resuelve exactamente a Post 5055, y Post 5055 hacia ES resuelve a Post 5048).
  - Ausencia estricta de fallback hacia idiomas sin traducción (devuelve `null`).
  - Comprobaciones HTTP reales contra `http://lab.ecoterra.test` sin errores 500 ni bucles.
  - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 79 grupos acumulados y 84 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.19 (`scratch/verify_fase_3_19.php`): **250/250 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.20] - 2026-10-11

### Migración Controlada del Séptimo Lote: CPT Tours (DT-TFML-050)
- **Subsanación Documental Fase 3.19:**
  - Ratificación en documentación canónica (`docs/DECISIONES-ARQUITECTONICAS.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`) del desglose exacto de Elementor: 8 grupos de plantillas `elementor_library` (9 elementos: 7 monolingües de 1 elemento + 1 bilingüe TRID 9208 de 2 elementos) y 2 grupos de términos `elementor_library_type` (2 elementos: 2 monolingües de 1 elemento), totalizando 10 grupos y 11 elementos.
  - Subtipo formal del Lote 7 ratificado como `tours` (CPT `tours`), tal como está catalogado en WordPress Core.
- **Ejecución Transaccional del Lote 7 (`batch-07-cpt-tours`):**
  - Migración exitosa de 12 grupos y 20 elementos correspondientes a publicaciones del tipo personalizado `tours` en `cms_ect_lab` (TRIDs `[9256, 9285, 9287, 9293, 9303, 9313, 9318, 9428, 9533, 9534, 9535, 9536]`).
  - Desglose de traducciones de tours: 8 grupos bilingües español-inglés (16 elementos) y 4 grupos monolingües en español (4 elementos).
  - Huella criptográfica inmutable ratificada: `d24b52d1224564bd745b4bc8032a9607adef5dc00379b2634a50eed08e77888b`.
  - Nuevo estado acumulado en la base de datos: exactamente **91 grupos** en `etk_t_tfml_groups` (79 previos + 12 de lote 7) y **104 elementos** en `etk_t_tfml_group_elements` (84 previos + 20 de lote 7), alcanzando el 75,8% de grupos y 78,2% de elementos importables del universo WPML.
  - Manifiesto de ejecución persistido y checkpoint actualizado a `completed` con 12 ítems procesados. Lock liberado.
- **Invarianza Criptográfica de Custom Post Types y Metadatos de Tours:**
  - Captura y comparación de snapshots pre y post-migración sobre los 20 posts `tours`: certificación de Delta Cero absoluto en `etk_t_posts`, `etk_t_postmeta` (campos personalizados, itinerarios, configuraciones de tours) y relaciones taxonómicas en `etk_t_term_relationships`.
- **Idempotencia y Reversibilidad Aislada del Lote 7:**
  - Re-ejecución inmediata del Lote 7 sobre el laboratorio evalúa los 12 grupos sin generar duplicados: los 12 TRIDs reportan formalmente `idempotent_skipped` y los conteos de base de datos se mantienen inmutables en 91 grupos y 104 elementos.
  - Rollback selectivo mediante `rollback_groups` sobre los IDs del Lote 7 revierte exclusivamente este lote, retornando la base de datos a 79 grupos y 84 elementos.
  - Los 79 grupos acumulados de los lotes 1 al 6 permanecen 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado final de 91 grupos y 104 elementos, con certificación profunda de reconciliación (`reconcile()`) al 100% (`integrity_verified = true`, 0 discrepancias).
- **Validación de API de Dominio y Comprobación HTTP:**
  - Validación funcional de `ContentTranslationResolver` sobre CPT Tours: resolución simétrica cruzada ES <-> EN en los 8 pares de tours bilingües (TRID 9256: 5321 <-> 5322; TRID 9285: 5380 <-> 5381; TRID 9287: 5384 <-> 5385; TRID 9293: 5396 <-> 5397; TRID 9303: 5416 <-> 5417; TRID 9313: 5436 <-> 5437; TRID 9318: 5446 <-> 5447; TRID 9428: 5772 <-> 5773).
  - Tours monolingües (TRIDs 9533, 9534, 9535, 9536) devuelven estrictamente `null` hacia EN (sin fallback espurio).
  - Preservación íntegra de la capacidad de resolución de lotes anteriores (Elementor, etiquetas, categorías, páginas).
  - Comprobaciones HTTP reales contra `http://lab.ecoterra.test` sin errores 500 ni bucles.
  - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 91 grupos acumulados y 104 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.20 (`scratch/verify_fase_3_20.php`): **359/359 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.21] - 2026-10-11

### Migración Controlada del Octavo Lote: Productos WooCommerce (DT-TFML-051)
- **Ejecución Transaccional del Lote 8 (`batch-08-cpt-products`):**
  - Migración exitosa de 7 grupos y 7 elementos correspondientes a publicaciones del tipo personalizado `product` (habitaciones y suites de alojamiento comercial) en `cms_ect_lab` (TRIDs `[9224, 9225, 9231, 9431, 9499, 9526, 9540]`, Posts `[5250, 5264, 5275, 5787, 6123, 6178, 6224]`).
  - Carácter lingüístico: 7 publicaciones monolingües en idioma español (`es`).
  - Huella criptográfica inmutable ratificada: `3df3ce6f1a6dac619d203adaf5a31d8eb3fc332493330a5e8b755d4ff1d9136d`.
  - Nuevo estado acumulado en la base de datos: exactamente **98 grupos** en `etk_t_tfml_groups` (91 previos + 7 de lote 8) y **111 elementos** en `etk_t_tfml_group_elements` (104 previos + 7 de lote 8), alcanzando el 81,7% de grupos y el 83,5% de elementos importables del universo WPML.
  - Manifiesto de ejecución persistido y checkpoint actualizado a `completed` con 7 ítems procesados. Lock liberado.
- **Invarianza Criptográfica de Productos WooCommerce y Metadatos Comerciales:**
  - Captura y comparación de snapshots pre y post-migración sobre los 7 productos: certificación de Delta Cero absoluto en `etk_t_posts`, `etk_t_postmeta` (precios regulares `_regular_price`, precios vigentes `_price`, gestión de stock `_stock`/`_stock_status`, impuestos `_tax_status`/`_tax_class`, metadatos de reserva `ovabrw_*`) y relaciones taxonómicas en `etk_t_term_relationships`. Cero recálculos automáticos de existencias, ventas o pedidos.
- **Idempotencia y Reversibilidad Aislada del Lote 8:**
  - Re-ejecución inmediata del Lote 8 sobre el laboratorio evalúa los 7 grupos sin generar duplicados: los 7 TRIDs reportan formalmente `idempotent_skipped` y los conteos de base de datos se mantienen inmutables en 98 grupos y 111 elementos.
  - Rollback selectivo mediante `rollback_groups` sobre los IDs del Lote 8 revierte exclusivamente este lote, retornando la base de datos a 91 grupos y 104 elementos.
  - Los 91 grupos acumulados de los lotes 1 al 7 permanecen 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado final de 98 grupos y 111 elementos, con certificación profunda de reconciliación (`reconcile()`) al 100% (`integrity_verified = true`, 0 discrepancias).
- **Validación de API de Dominio y Comprobación HTTP:**
  - Validación funcional de `ContentTranslationResolver` sobre Productos WooCommerce: reconocimiento de idioma `es` para cada producto y resolución hacia `en` devolviendo estrictamente `null` (sin fallback indebido a default ni canonical).
  - Preservación íntegra de la capacidad de resolución de lotes anteriores (tours bilingües de Lote 7, plantillas Elementor de Lote 6, etiquetas, categorías, páginas).
  - Comprobaciones HTTP reales contra `http://lab.ecoterra.test` sin errores 500 ni bucles.
  - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 98 grupos acumulados y 111 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.21 (`scratch/verify_fase_3_21.php`): **299/299 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.22] - 2026-10-11

### Migración Controlada del Noveno Lote: Entradas y Formularios CF7 (DT-TFML-052)
- **Ejecución Transaccional del Lote 9 (`batch-09-core-posts-forms`):**
  - Migración exitosa de 4 grupos y 4 elementos en `cms_ect_lab` (TRIDs `[4340, 9391, 9406, 9411]`, Posts `[4340, 5687, 5718, 5734]`).
  - Subtipos y tipos de post involucrados: 3 grupos `post:post` (entradas nativas de blog: Posts 5687, 5718, 5734) y 1 grupo `post:wpcf7_contact_form` (formulario de contacto Contact Form 7: Post 4340).
  - Carácter lingüístico: 4 publicaciones monolingües en idioma español (`es`).
  - Huella criptográfica inmutable ratificada: `6d188e1cbff392deaa030e917d5d43aaed482f915c218a73c87f415d39f53720`.
  - Nuevo estado acumulado en la base de datos: exactamente **102 grupos** en `etk_t_tfml_groups` (98 previos + 4 de lote 9) y **115 elementos** en `etk_t_tfml_group_elements` (111 previos + 4 de lote 9), alcanzando el 85,0% de grupos y el 86,5% de elementos importables del universo WPML.
  - Manifiesto de ejecución persistido y checkpoint actualizado a `completed` con 4 ítems procesados. Lock liberado.
- **Invarianza Criptográfica de Entradas y Formularios Contact Form 7:**
  - Captura y comparación de snapshots pre y post-migración sobre las 4 publicaciones: certificación de Delta Cero absoluto en `etk_t_posts`, `etk_t_postmeta` (metadatos de blog, configuración de CF7: `_form`, `_mail`, `_mail_2`, `_messages`, `_additional_settings`) y relaciones taxonómicas en `etk_t_term_relationships`. Cero envíos de formulario y cero despachos de correo durante la verificación.
- **Idempotencia y Reversibilidad Aislada del Lote 9:**
  - Re-ejecución inmediata del Lote 9 sobre el laboratorio evalúa los 4 grupos sin generar duplicados: los 4 TRIDs reportan formalmente `idempotent_skipped` y los conteos de base de datos se mantienen inmutables en 102 grupos y 115 elementos.
  - Rollback selectivo mediante `rollback_groups` sobre los IDs del Lote 9 revierte exclusivamente este lote, retornando la base de datos a 98 grupos y 111 elementos.
  - Los 98 grupos acumulados de los lotes 1 al 8 permanecen 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado final de 102 grupos y 115 elementos, con certificación profunda de reconciliación (`reconcile()`) al 100% (`integrity_verified = true`, 0 discrepancias).
- **Validación de API de Dominio y Comprobación HTTP:**
  - Validación funcional de `ContentTranslationResolver` sobre Entradas y Formulario CF7: reconocimiento de idioma `es` para cada publicación y resolución hacia `en` devolviendo estrictamente `null` (sin fallback indebido a default ni canonical).
  - Preservación íntegra de la capacidad de resolución de lotes anteriores (productos WooCommerce de Lote 8, tours bilingües de Lote 7, plantillas Elementor de Lote 6, etiquetas, categorías, páginas).
  - Comprobaciones HTTP reales contra `http://lab.ecoterra.test` sin errores 500 ni bucles.
  - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 102 grupos acumulados y 115 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.22 (`scratch/verify_fase_3_22.php`): **250/250 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.23] - 2026-10-11

### Migración Controlada del Décimo Lote: Elementos de Menú WordPress y Cobertura 100% Universo Importable (DT-TFML-053)
- **Ejecución Transaccional del Lote 10 (`batch-10-nav-menu-items`):**
  - Migración exitosa de los 18 grupos y 18 elementos correspondientes a elementos de menú de navegación (`post:nav_menu_item`) en `cms_ect_lab` (TRIDs `[9210, 34659, 34701, 34789, 34792, 34824, 34826, 34828, 34829, 34832, 34833, 34834, 34835, 34836, 34837, 34838, 34839, 34840]`, Posts `[8363, 9243, 9376, 9657, 9671, 9902, 10076, 10080, 10081, 10151, 10152, 10153, 10154, 10155, 10156, 10157, 10158, 10159]`).
  - Carácter lingüístico: 9 elementos en español (`es`) y 9 elementos en inglés (`en`).
  - Huella criptográfica inmutable ratificada: `19b7f15c730a7f0059b7d6873a37b07ed2bcd63f499c5a30fc921ae5d4957acc`.
  - Nuevo estado acumulado en la base de datos: exactamente **120 grupos** en `etk_t_tfml_groups` (102 previos + 18 de lote 10) y **133 elementos** en `etk_t_tfml_group_elements` (115 previos + 18 de lote 10), alcanzando el **100,0% del universo clasificado como IMPORTABLE**.
  - Manifiesto de ejecución persistido y checkpoint actualizado a `completed` con 18 ítems procesados. Lock liberado.
- **Invarianza Criptográfica de Estructura de Navegación (Delta Cero Absoluto):**
  - Captura y comparación de snapshots pre y post-migración sobre los 18 elementos de menú: certificación de Delta Cero absoluto en `etk_t_posts`, `etk_t_postmeta` (metadatos de navegación `_menu_item_type`, `_menu_item_object`, `_menu_item_object_id`, `_menu_item_menu_item_parent`, `_menu_item_url`, `_menu_item_target`, `_menu_item_classes`, `_menu_item_xfn`) y relaciones taxonómicas con `nav_menu` en `etk_t_term_relationships`. Cero reescrituras de IDs de destino o de jerarquía de menús.
- **Idempotencia y Reversibilidad Aislada del Lote 10:**
  - Re-ejecución inmediata del Lote 10 sobre el laboratorio evalúa los 18 grupos sin generar duplicados: los 18 TRIDs reportan formalmente `idempotent_skipped` y los conteos de base de datos se mantienen inmutables en 120 grupos y 133 elementos.
  - Rollback selectivo mediante `rollback_groups` sobre los IDs del Lote 10 revierte exclusivamente este lote, retornando la base de datos a 102 grupos y 115 elementos.
  - Los 102 grupos acumulados de los lotes 1 al 9 permanecen 100% blindados e intactos.
  - Re-ejecución posterior restaura el estado final de 120 grupos y 133 elementos, con certificación profunda de reconciliación (`reconcile()`) al 100% (`integrity_verified = true`, 0 discrepancias).
- **Reconciliación Global de los 10 Lotes y Preservación de No Importables:**
  - Culminación del 100% del universo importable saneado: 120 grupos importados / 120 importables previstos (100%), 133 elementos importados / 133 importables previstos (100%). Cero grupos pendientes del catálogo importable. Cero grupos duplicados y cero elementos duplicados en tablas TFML.
  - Preservación íntegra de entidades no importables del plan global WPML: 52 grupos `BLOCKED_BY_LANGUAGE`, 24 grupos `SKIPPED` y 53 grupos `ORPHAN` conservados sin alteración.
- **Diferenciación de Alcance y Frontera Frontend:**
  - Distinción formal: Cobertura del universo importable (100%) vs migración global WPML (pendiente de entidades bloqueadas/omitidas/huérfanas).
  - El renderizado visual de menús y etiquetas HTML (`<html lang>`, canonical y hreflang) permanece formalmente catalogado como `BLOCKED` por la ausencia física del tema `ecoCMS` en el laboratorio.
  - Preparación para producción no autorizada.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 120 grupos acumulados y 133 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.23 (`scratch/verify_fase_3_23.php`): **503/503 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.24] - 2026-10-11

### Auditoría Integral Post-Migración y Diagnóstico de Brechas Funcionales (DT-TFML-054)
- **Certificación y Congelamiento del Baseline Post-Migración en Laboratorio:**
  - Auditoría exhaustiva en `cms_ect_lab` ratificando el congelamiento exacto de **120 grupos** en `etk_t_tfml_groups` y **133 elementos** en `etk_t_tfml_group_elements` (100,0% del universo clasificado como IMPORTABLE).
  - Cero grupos duplicados, cero elementos duplicados, 0 registros en `etk_t_tfml_media_translations`, 0 en `etk_t_tfml_strings` y 0 en `etk_t_tfml_string_translations`.
  - Checkpoint oficial en estado `completed` con 18 ítems procesados (Lote 10), último TRID `34840`, y lock de ejecución liberado.
- **Diagnóstico Causa-Raíz de la Ausencia del Tema `ecoCMS`:**
  - Constatación de configuración en BD `cms_ect_lab` (`etk_t_options`): `template = 'ecoCMS'`, `stylesheet = 'ecoCMS'`, `current_theme = 'ecoCMS'`.
  - Constatación física: el directorio `D:/laragon/www/lab.ecoterra/wp-content/themes` está completamente vacío, provocando el fallo visual y el estado formal `BLOCKED` para renderizado HTML de etiquetas lingüísticas, canonicals, hreflang y menús.
  - Caracterización del tema en origen: tema autónomo (no child theme) versión 1.0.0, Text Domain `qomfort`, dependiente de plugins `ova-framework`, `elementor` y `elementor-pro`.
  - Diseño de procedimiento controlado de incorporación física (copia aislada estricta, sin enlaces simbólicos ni directorios compartidos) con salvaguardas perimetrales activas.
- **Diagnóstico Quirúrgico del TRID 197 (Página de Inicio) y Causa de HTTP 404 en `/en/`:**
  - Configuración Core: `show_on_front = 'page'`, `page_on_front = 197`.
  - Estructura en WPML: TRID 197 contiene Post 197 (`es` - Inicio, canónica), Post 8356 (`en` - Home, traducción inglesa) y Post 8839 (`fr` - Accueil, traducción francesa).
  - Causa raíz de brecha funcional: Al tener una traducción en francés (`fr`) y estar `fr` inactivo en TFML, el planificador clasificó el TRID completo como `BLOCKED_BY_LANGUAGE`.
  - En consecuencia, el par en inglés no fue importado a tablas de TFML, `ContentTranslationResolver::resolve_element_id('post', 197, 'en')` devuelve estrictamente `null`, y las peticiones a `http://lab.ecoterra.test/en/` devuelven `404 Not Found`.
  - Propuesta para fase posterior: Derivación quirúrgica del TRID 197 registrando el par activo `es` <-> `en` en TFML y dejando `fr` retenido en `pending_translations`, sin activar francés en frontend.
- **Auditoría Estructural de los 129 Grupos No Importables del Universo WPML (249 Totales):**
  - **`BLOCKED_BY_LANGUAGE` (52 grupos / 139 elementos):** 23 `page`, 14 `nav_menu_item`, 11 `rooms`, 2 `ova_framework_hf_el`, 1 `category`, 1 `nav_menu`. Entidades íntegras y válidas retenidas únicamente por miembros en francés (`fr`).
  - **`SKIPPED` (24 grupos / 48 elementos):** 21 comentarios de blog (`comment`) y 3 taxonomías técnicas (`translation_priority`). No aplicables para tablas de traducción TFML.
  - **`ORPHAN` (53 grupos / 53 elementos):** 20 `product`, 19 `page`, 10 `tours`, 3 `post`, 1 `mc4wp-form`. Entidades eliminadas de WordPress Core cuyos registros huérfanos persisten en `icl_translations`. Deben permanecer excluidas.
- **Diagnóstico de Integraciones (Elementor vs WPBakery):**
  - WPBakery: 0 registros de metadatos `vc_*` o `wpb*` en `etk_t_postmeta`. WPBakery no aplica al sitio (desestimado).
  - Elementor: Confirmada presencia activa en 398 publicaciones con metadatos `_elementor_data`.
- **Comprobaciones HTTP Reales No Destructivas:**
  - Raíz (`/`): HTTP 200 OK con cabecera `X-Robots-Tag: noindex, nofollow, noarchive`.
  - Prefijo `/en/`: HTTP 404 Not Found (por TRID 197 no importado; sin errores 500 ni loops).
  - Prefijo `/fr/`: HTTP 404 Not Found (idioma inactivo, rechazo limpio sin aliasing espurio).
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 120 grupos acumulados y 133 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.24 (`scratch/verify_fase_3_24.php`): **50/50 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.25] - 2026-10-11

### Restauración Controlada del Entorno Visual en Laboratorio (DT-TFML-055)
- **Incorporación Física Aislada del Tema `ecoCMS` (175 Archivos):**
  - Despliegue mediante copia física independiente desde `D:/laragon/www/cms.ecoterra/wp-content/themes/ecoCMS` hacia `D:/laragon/www/lab.ecoterra/wp-content/themes/ecoCMS`.
  - Cero symlinks, cero junctions, cero archivos compartidos: 100% de paridad en tamaño y checksums SHA-256 (175/175 archivos coincidentes, 0 discrepancias).
  - Tema de producción permanece 100% inalterado y fuera del repositorio del plugin.
- **Evaluación y Copia Controlada de `ova-framework` (6 Archivos):**
  - Inspección previa de código: sin escrituras no controladas, llamadas HTTP externas, tareas cron ni desvíos de aislamiento.
  - Copia física independiente hacia `D:/laragon/www/lab.ecoterra/wp-content/plugins/ova-framework` (6/6 archivos con 100% paridad SHA-256).
  - Registro de CPTs requeridos: `rooms` y constructor de cabeceras/pies `ova_framework_hf_el`.
- **Inicio de WordPress y Renderizado Real:**
  - Reconocimiento de `ecoCMS` como tema activo (`template`, `stylesheet`, `current_theme`).
  - Transición de documento vacío (0 bytes en Fase 3.24) a renderizado HTML estructural completo de 52.217 bytes en `/`, con `<header>`, `<footer>`, estilos y scripts locales.
- **Ejecución de la Matriz HTTP y HTML Obligatoria (11 Rutas Evaluadas):**
  - Ruta raíz (`/`): HTTP 200 OK (52.217 bytes, canonical, `X-Robots-Tag: noindex, nofollow, noarchive`).
  - Prefijo `/en/`: HTTP 404 Not Found (31.062 bytes, renderizado de plantilla 404 de `ecoCMS`; comportamiento esperado por TRID 197 bloqueado).
  - Prefijo `/fr/`: HTTP 404 Not Found (31.062 bytes, rechazo limpio de idioma no habilitado).
  - Página monolingüe migrada (`/old-blog/`): HTTP 200 OK (31.865 bytes, canonical localizado).
  - Entrada de blog migrada (`/blog/recomendaciones-para-disfrutar-machu-picchu/`): HTTP 200 OK (51.107 bytes, contenido editorial y maquetación de blog completa).
  - Categoría migrada (`/category/blog/`): HTTP 200 OK (49.588 bytes, listado de taxonomía).
  - Etiqueta migrada (`/tag/cusco/`): HTTP 200 OK (41.544 bytes, listado de taxonomía).
  - Tours bilingües y Producto WooCommerce: códigos de respuesta controlados sin errores fatales 500.
  - Cero errores críticos PHP o excepciones no capturadas.
- **Auditoría de Menús, Navegación y Selector de Idioma:**
  - Detección de tres menús en base de datos: `Menu Principal` (ES, ID 43), `Menu - ES - Inglés` (EN, ID 98) y `Menu Principal - Francés` (FR, ID 105).
  - Ubicación de tema `primary` sin asignar (`''`) en `nav_menu_locations`, debido a que en origen la cabecera dependía de Elementor Pro (`ova_framework_hf_el`).
  - Detección del helper `traducir()` en `ecoCMS/inc/ecoterra.php` que consulta `ICL_LANGUAGE_CODE` para desvíos lingüísticos.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 120 grupos acumulados y 133 elementos, lock liberado.
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **168/168 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.25 (`scratch/verify_fase_3_25.php`): **60/60 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.26] - 2026-10-11

### Diagnóstico y Diseño Seguro de la Portada Bilingüe y Compatibilidad del Tema (DT-TFML-056)
- **Diagnóstico Causa-Raíz del Atributo `<html lang="en-US">` en Páginas en Español:**
  - Identificación del flujo en WordPress Core: `ecoCMS/header.php` invoca `language_attributes()` -> `get_bloginfo('language')` -> `get_locale()`.
  - Constatación de causa raíz: la opción de base de datos `WPLANG` se encuentra vacía (`""`) en `cms_ect_lab`, provocando que WordPress Core recurra por defecto a `'en_US'`.
  - Ausencia de interceptor en TF Multilingual sobre el hook nativo `locale` o `determine_locale` en frontend.
  - Diseño de solución limpia y desacoplada (sin expresiones regulares ni manipulación de búferes): registro de un filtro sobre el hook `locale` en `Plugin::init()` / `RewriteManager` que entrega a WordPress Core el locale oficial (`es_ES` o `en_US`) según `CurrentLanguageResolver::get_current_language()` y `LanguageRegistry`.
- **Diagnóstico de Enrutamiento y Resolución de Portada Bilingüe (`/` vs `/en/`):**
  - Verificación de configuración WordPress Core: `show_on_front = 'page'`, `page_on_front = 197`.
  - Anatomía del TRID 197 en WPML: Post 197 (`es` - Inicio, canónica), Post 8356 (`en` - Home, traducción inglesa) y Post 8839 (`fr` - Accueil, traducción francesa).
  - Causa raíz del 404 en `/en/`: TRID 197 fue clasificado como `BLOCKED_BY_LANGUAGE` al contener el miembro en francés (`fr`), no importándose en los 10 lotes iniciales.
  - Demostración de doble requisito técnico para resolver `/en/` con HTTP 200 OK:
    1. Registro del par activo `es` <-> `en` (Posts 197 y 8356) en `tfml_groups` / `tfml_group_elements`.
    2. Intercepción de la consulta de portada de WordPress (`option_page_on_front` o `pre_get_posts`) para sustituir `page_id = 197` por el ID traducido `8356` cuando el idioma activo sea `'en'`.
- **Evaluación Comparativa de Alternativas para el TRID 197:**
  - Alternativa A (Mantener bloqueado): descarta la resolución de `/en/` mientras `fr` no se active.
  - Alternativa B (Importación parcial explícita del par activo — SELECCIONADA Y RECOMENDADA): evoluciona formalmente el contrato de `MigrationPlan` para importar `es` <-> `en` y retener `fr` en `pending_translations`. 100% auditable, reversible (rollback a 120/133) y sin mutaciones al origen.
  - Alternativa C (Asociación editorial manual): descartada por desalineación con el inventario unificado del migrador.
- **Auditoría Exhaustiva de Compatibilidad Temática de `ecoCMS`:**
  - Cuantificación exacta sobre los 175 archivos PHP del tema: 105 llamadas directas y 4 guardas `function_exists` (109 referencias a símbolos WPML en total).
  - Desglose verificado: 5 ocurrencias de la constante `ICL_LANGUAGE_CODE`, 96 llamadas a `traducir()`, 2 llamadas a `icl_object_id()` y 2 llamadas a `icl_get_languages()`.
  - Constatación de dependencia en `ecoCMS/inc/ecoterra.php`: la función `traducir()` recurre a `defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'es'`.
  - Diseño del adaptador de compatibilidad de solo lectura (`ThemeCompatibilityBridge`) en TFML para definir `ICL_LANGUAGE_CODE` y stubs para `icl_object_id` e `icl_get_languages` sin alterar los archivos de `ecoCMS`.
- **Diagnóstico de Arquitectura de Cabecera y Dependencia de Elementor:**
  - Constatación de que la cabecera original se diseñó con Elementor Pro en el CPT `ova_framework_hf_el` (6 publicaciones en `etk_t_posts`).
  - Constatación de que `ecoCMS/inc/class-hook.php` recurre ordenadamente al fallback `template-parts/header-default.php` en ausencia del constructor.
- **Preservación Inmutable de Centinelas:**
  - `cms_ect_lab`: Conserva exactamente 120 grupos acumulados y 133 elementos, lock liberado (Delta = 0).
  - `cms_ect`: Centinela intacto con 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **Métricas de Calidad:**
  - PHPUnit: **532 tests (533 total), 1.830 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
## [Fase 3.27] - 2026-10-11

### Corrección del Idioma HTML y Localización Pública Nativa de WordPress (DT-TFML-057)
- **Implementación del Componente Canónico de Localización:**
  - Nueva clase `TF\Multilingual\Localizacion\LocalizadorPublicoNativo` en `src/Localizacion/LocalizadorPublicoNativo.php`.
  - Integración nativa con `CurrentLanguageResolver` y `LanguageRegistry`.
  - Intercepción limpia mediante los hooks canónicos de WordPress Core `locale` y `determine_locale` (con prioridad 10).
- **Resolución Rigurosa de Contextos y Aislamiento Administrativo:**
  - Método `es_contexto_publico_elegible()` que previene alteraciones en el área de administración (`is_admin()`), tareas programadas (`wp_doing_cron()`), llamadas internas (`wp_doing_ajax()`) y comandos de consola (`WP_CLI`), salvo activación explícita para pruebas unitarias (`habilitar_en_cli_para_pruebas()`).
  - Protección antirrecursión mediante bandera interna en memoria (`$en_resolucion`).
- **Invarianza Estricta de Base de Datos y WPLANG:**
  - Cero mutaciones en la opción `WPLANG` en la base de datos `cms_ect_lab`, manteniéndose vacía (`""`) a nivel persistente.
  - Corrección 100% en memoria en tiempo de ejecución.
  - Cero modificaciones a la base de datos de producción `cms_ect`.
  - Los 120 grupos y 133 elementos migrados en `cms_ect_lab` permanecen intactos (Delta = 0).
  - Centinela WPML en `cms_ect` preserva 3.403 registros y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
- **Acreditación Empírica en Frontend (lab.ecoterra.test):**
  - Corrección demostrada de `<html lang="en-US">` a `<html lang="es-ES">` en todas las URLs públicas en español:
    - Portada raíz (`/`): HTTP 200 OK, `<html lang="es-ES" >`.
    - Página monolingüe migrada (`/old-blog/`): HTTP 200 OK, `<html lang="es-ES" >`.
    - Categoría de blog (`/category/blog/`): HTTP 200 OK, `<html lang="es-ES" >`.
    - Etiqueta de blog (`/tag/cusco/`): HTTP 200 OK, `<html lang="es-ES" >`.
    - Entrada individual de blog (`/sin-categorizar/recomendaciones-para-disfrutar-machu-picchu/`): HTTP 200 OK, `<html lang="es-ES" >`.
    - Ruta en inglés (`/en/`): `<html lang="en-US" >`.
    - Ruta en idioma inactivo (`/fr/`): `<html lang="es-ES" >` (idioma francés inactivo no se habilita).
- **Métricas de Calidad y Verificación:**
  - PHPUnit: **540 tests (541 total), 1.872 assertions, 0 errors, 0 failures, 1 skipped** (100% PASS).
  - PHPCS / WPCS: **170/170 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.27 (`scratch/verify_fase_3_27.php`): **45/45 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.28] - 2026-10-11

### Contratos y Adaptadores Limitados de Compatibilidad con WPML (DT-TFML-058)
- **Separación Arquitectónica en Tres Superficies Soberanas:**
  - **Superficie A (API Pública Nativa):** Implementación de `TF\Multilingual\API\ServicioTraduccionPublico` para proveer acceso independiente y desacoplado a las funciones de traducción y consulta de idiomas del sistema.
  - **Superficie B (Adaptador de Compatibilidad WPML):** Implementación de `TF\Multilingual\Compatibilidad\AdaptadorCompatibilidadWpml` y stubs globales en `src/Compatibilidad/funciones_wpml.php` para traducir llamadas a `icl_object_id()` e `icl_get_languages()`. **Desactivado por omisión** mediante filtro extensible `tfml_enable_wpml_compatibility_adapter`.
  - **Superficie C (Código Específico del Tema ecoCMS):** La función `traducir()` en `ecoCMS/inc/ecoterra.php` y sus 94 llamadas temáticas se mantienen 100% intactas, preservando su comportamiento y fallback nativo a español.
- **Restricción Categórica de Constante Global:**
  - **`ICL_LANGUAGE_CODE` permanece estrictamente NO definida**, protegiendo la soberanía del Core y evitando efectos colaterales de suplantación de WPML.
- **Auditoría e Inventario de Contratos en ecoCMS:**
  - Inventario formal automatizado (`scratch/audit_theme_contracts.php` y `scratch/theme_contracts_inventory.json`):
    - `traducir()`: 94 llamadas en 4 archivos temáticos (`footer.php`, `inc/ecoterra.php`, `inc/short-codes.php`, `template-parts/tour.php`).
    - `icl_object_id()`: 4 llamadas en `inc/class-hook.php` (para CPT `ova_framework_hf_el` con `return_original = false`).
    - `icl_get_languages()`: 4 llamadas en `inc/short-codes.php` (usando argumento `'skip_missing=0&orderby=custom'`).
    - `ICL_LANGUAGE_CODE`: 3 ocurrencias en código temático.
- **Implementación de Contratos Funcionales:**
  - Soporte completo para `icl_object_id($id, $type, $return_original_if_missing, $lang)` con resolución bidireccional sobre los 120 grupos de traducción y preservación de fallbacks.
  - Soporte completo para `icl_get_languages($args)` con entrega de esquemas de datos esperados por el tema y exclusión estricta de idiomas inactivos (`fr`).
  - Funciones globales `icl_object_id` e `icl_get_languages` protegidas con `!function_exists()`, con degradación limpia cuando el adaptador no está habilitado.
- **Preservación Inmutable de Centinelas y Datos:**
  - Base de datos `cms_ect`: 3.403 filas en `etk_t_icl_translations`, hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Base de datos `cms_ect_lab`: 120 grupos y 133 elementos congelados intactos (Delta = 0).
  - TRID 197 no importado en esta fase; Elementor no instalado.
- **Métricas de Calidad y Verificación:**
  - PHPUnit: **554 tests, 1.919 assertions, 0 errors, 0 failures** (100% PASS).
  - PHPCS / WPCS: **174/174 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificación Integral Fase 3.28 (`scratch/verify_fase_3_28.php`): **68/68 controles PASS (100%)**.
  - `composer validate --strict`: PASS.

---

## [Fase 3.29] - 2026-10-11

### Blindaje de Activación y Ausencia de Efectos Secundarios del Adaptador de Compatibilidad WPML (DT-TFML-059)
- **Aislamiento Absoluto de Símbolos Globales en Desactivación:**
  - Resolución definitiva de la observación de seguridad arquitectónica de la Fase 3.28: cuando el adaptador está desactivado (estado por omisión del plugin), `function_exists('icl_object_id')` e `function_exists('icl_get_languages')` retornan estrictamente `false`.
  - La carga del archivo `src/Compatibilidad/funciones_wpml.php` queda condicionada exclusivamente a que el adaptador esté habilitado durante el arranque (*boot-time*), impidiendo la contaminación irreversible de la tabla global de funciones de PHP.
- **Salvaguardas de Detección de WPML Real y Auto-inhibición:**
  - Implementación de `detectar_presencia_wpml_real()` en `AdaptadorCompatibilidadWpml`.
  - Detección activa de la clase `SitePress`, constantes `ICL_SITEPRESS_VERSION` y `WPML_PLUGIN_PATH`.
  - Si un WPML real está presente o activo, el adaptador se auto-inhibe de inmediato (`$habilitado = false`, registrando el motivo) sin emitir errores fatales, cediendo el control total al plugin original.
- **Detección de Colisiones de Símbolos y Centinela de Integridad:**
  - Incorporación de la constante centinela `TFML_WPML_FUNCTIONS_LOADED` en `src/Compatibilidad/funciones_wpml.php`.
  - Implementación de `detectar_colision_simbolos()`: si las funciones `icl_object_id` o `icl_get_languages` ya existen sin nuestro centinela, el adaptador se auto-inhibe limpiamente, preservando la función ajena intacta y evitando errores fatales por redeclaración.
- **Configuración Jerárquica de Activación:**
  - Soporte de activación en tres niveles jerárquicos en `src/Core/Plugin.php`:
    1. Constante de arranque: `TFML_ENABLE_WPML_COMPATIBILITY`.
    2. Opción de WordPress: `tfml_enable_wpml_compatibility_adapter`.
    3. Filtro extensible: `tfml_enable_wpml_compatibility_adapter` (por defecto `false`).
- **Verificación en Procesos PHP Aislados (14 Escenarios Obligatorios):**
  - Implementación del script de certificación `scratch/verify_fase_3_29.php` con subprocesos PHP CLI independientes (`proc_open`):
    - Escenario 1: TFML desactivado -> `function_exists` = false para funciones WPML, `ICL_LANGUAGE_CODE` no definida.
    - Escenario 2: Superficie A (API pública pura) operativa al 100% sin adaptador.
    - Escenario 3: Adaptador habilitado explícitamente -> funciones globales operativas, contratos auditados pasan, `ICL_LANGUAGE_CODE` no definida, francés estrictamente excluido.
    - Escenario 4: WPML real simulado -> detección exitosa y auto-inhibición limpia sin wrappers.
    - Escenario 5: Símbolos preexistentes ajenos -> detección de colisión y preservación de símbolos ajenos sin colisión fatal.
    - Frontend HTTP real en `lab.ecoterra.test` (rutas `/`, `/old-blog/`, `/category/blog/`, `/tag/cusco/`, `/en/`, `/fr/`, >40KB render de tema).
  - Resultado: **79/79 controles PASS (100%)**.
- **Preservación Inmutable de Centinelas y Datos:**
  - Base de datos `cms_ect`: 3.403 registros en `etk_t_icl_translations`, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Base de datos `cms_ect_lab`: 120 grupos y 133 elementos congelados intactos (Delta = 0).
  - TRID 197 no importado; Elementor no instalado; Francés (`fr`) permanece inactivo.
- **Métricas de Calidad:**
  - PHPUnit: **557 tests, 1.923 assertions, 0 errors, 0 failures** (100% PASS).
  - PHPCS / WPCS: **174/174 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - `composer validate --strict`: PASS.

---

## [Fase 3.30] - 2026-10-11

### Soporte Transaccional para Grupos WPML con Idiomas Inactivos Retenidos (DT-TFML-060)
- **Extensión del Sistema de Planificación (`MigrationPlan`):**
  - Incorporación de la constante de clasificación `MigrationPlan::CLASSIFICATION_PARTIAL_RETAINED = 'PARTIAL_RETAINED'`.
  - Extensión del método `derive_partial_plan(array $selected_trids, string $label, array $authorized_retained)` permitiendo derivar formalmente grupos clasificados originalmente como `BLOCKED_BY_LANGUAGE` mediante autorización explícita del operador técnico.
  - Implementación del helper tipado `derive_partial_plan_with_retained_languages()` para simplificar y blindar la invocación.
  - Los miembros en idiomas activos (ES/EN) se preservan en `translations` para inserción relacional estándar en TFML, y los miembros en idiomas inactivos (FR) se aíslan en `pending_translations`.
  - Actualización del cálculo de métricas en `get_summary()`: se desglosan `partial_retained_groups` y `pending_elements` con compatibilidad retrospectiva integral.
- **Componente Persistente y Auditable: `RetainedTranslationRegistry`:**
  - Creación de `TF\Multilingual\Migration\RetainedTranslationRegistry` para registrar, auditar y custodiar de forma persistente las traducciones retenidas en la Options API (`tfml_retained_translations`) sin alterar el esquema relacional de las 5 tablas maestras (`tfml_schema_version = '1.0.0'`).
  - Verificación de unicidad y prevención de colisiones relacionales entre elementos y grupos.
  - Cálculo determinista de huella criptográfica SHA-256 (`fingerprint`) por entrada de traducción retenida.
  - Mecanismo de promoción controlada `promote_retained_translation()` para cuando en el futuro se active un idioma secundario en `LanguageRegistry`.
  - Mecanismo de purga y limpieza atómica `remove_by_group_id()` integrado en el ciclo de vida de reversión.
- **Adaptación Transaccional del Ejecutor (`WpmlMigrationExecutor`):**
  - Inyección de `RetainedTranslationRegistry`.
  - `validate_plan()`: admite formalmente grupos `PARTIAL_RETAINED` en planes parciales/derivados.
  - `execute_batch()`: ejecuta grupos `PARTIAL_RETAINED` e instruye el registro de los miembros retenidos.
  - `import_group()`: registra de forma idempotente las traducciones retenidas en `RetainedTranslationRegistry`.
  - `run()`: contabiliza `imported_partial_retained` en el resumen global y persiste el mapa de retenidas en el manifiesto de lote (`tfml_migration_manifest_{token}`).
  - `reconcile()`: verifica miembros activos en `tfml_group_elements`, comprueba que los miembros retenidos **NO** fueron insertados en la base relacional de TFML, y certifica su presencia auditada en `RetainedTranslationRegistry`.
- **Integración Atómica en Rollback (`WpmlMigrationRollbackService`):**
  - Inyección de `RetainedTranslationRegistry`.
  - `rollback_groups()`: invoca `$this->retained_registry->remove_by_group_id($group_id)` tras revertir el grupo TFML, asegurando que no queden residuos huérfanos en la Options API.
- **Batería de los 15 Escenarios Automatizados de DT-TFML-060:**
  - Verificación exhaustiva en memoria y aislamiento de los 15 escenarios exigidos por la directiva técnica:
    1. TRID con ES/EN activos y FR inactivo.
    2. Derivación formal y asignación de `PARTIAL_RETAINED`.
    3. Conservación del miembro francés en `pending_translations`.
    4. Rechazo de incorporación automática sin autorización explícita.
    5. Rechazo de TRIDs ajenos o no presentes en el plan.
    6. Rechazo de posts inexistentes en WordPress Core.
    7. Rechazo de tipos inconsistentes con WordPress Core.
    8. Rechazo de códigos lingüísticos desconocidos o no normalizables.
    9. Rechazo de colisiones relacionales en elementos ya asignados.
    10. Promoción exitosa al activar el idioma en el futuro.
    11. Idempotencia y estabilidad determinista del fingerprint ante registro repetido.
    12. Rollback atómico y purga completa del registro retenido.
    13. Reconciliación con trazabilidad criptográfica (`parent_fingerprint`).
    14. Resumen de plan con métricas discriminadas (`partial_retained_groups`, `pending_elements`).
    15. Aislamiento absoluto: cero escrituras en la base de datos `cms_ect_lab`.
- **Restricción Estricta de No-Ejecución y Centinelas:**
  - **TRID 197 permanece estrictamente NO importado** en `cms_ect_lab` (Delta = 0).
  - Base de datos `cms_ect`: 3.403 registros en `etk_t_icl_translations`, hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
  - Base de datos `cms_ect_lab`: 120 grupos y 133 elementos congelados intactos (Delta = 0).
  - Frontend HTTP real verificado: 200 OK en portada (`/`), `/old-blog/`, `/category/blog/`, `/tag/cusco/` con `lang="es-ES"`, 404 en `/en/` y `/fr/`.
- **Métricas de Calidad y Verificación:**
  - PHPUnit: **569 tests, 1.996 assertions, 0 errors, 0 failures** (100% PASS).
  - PHPCS / WPCS: **177/177 archivos analizados, 0 errors, 0 warnings** (100% PASS).
  - Verificador de Fase 3.30 (`scratch/verify_fase_3_30.php`): **53/53 controles PASS (100%)**.
  - `composer validate --strict`: PASS.
- **Blindaje Transaccional y Certificación de Recuperación de Traducciones Retenidas (Fase 3.31 / Directiva DT-TFML-061 — ADR-057):**
  - **Unificación de Conexión Transaccional (`$wpdb`) y Persistencia SQL Directa sobre `{$db->prefix}options` (InnoDB):**
    - Inyección de la instancia compartida `$wpdb` (`$this->db`) en `RetainedTranslationRegistry`, `MigrationCheckpointRepository`, `MigrationExecutionLock` y `WpmlMigrationRollbackService`.
    - Ejecución de lecturas, escrituras y borrados mediante SQL parametrizado directo sobre `{$this->db->prefix}options` (motor **InnoDB**) cuando hay conexión MySQL activa (`has_real_db()`), participando en la misma transacción `START TRANSACTION` / `SAVEPOINT` / `COMMIT` / `ROLLBACK` que `tfml_groups` y `tfml_group_elements`.
  - **Eliminación de Lecturas Sucias en `WP_Object_Cache` tras `ROLLBACK` SQL:**
    - Invalidación explícita de claves en `WP_Object_Cache` (`tfml_retained_translations`, `tfml_migration_checkpoint_wpml`, `alloptions`, `notoptions` y manifiestos) en `TranslationGroupRepository::rollback_transaction()`, `RetainedTranslationRegistry::invalidate_option_cache()`, `MigrationCheckpointRepository` y `WpmlMigrationRollbackService`.
  - **Verificación Post-Escritura del Estado Persistido:**
    - Resolución de la ambigüedad de `update_option() === false` (sin cambios vs. fallo de escritura) mediante verificación de `'' === $this->db->last_error` y comparación de huella criptográfica SHA-256 del estado releído en `RetainedTranslationRegistry::persist_and_verify()`, `MigrationCheckpointRepository::save()` y `WpmlMigrationRollbackService::save_execution_manifest()`.
  - **Blindaje de Frontera Transaccional en `execute_batch()`, `promote_retained_translation()` y `rollback_groups()`:**
    - Inclusión de `$this->checkpoint_repository->save()` y `$this->persist_batch_manifest()` **antes** de `commit_transaction()` en `WpmlMigrationExecutor::execute_batch()`.
    - Envoltura transaccional de `RetainedTranslationRegistry::promote_retained_translation()` con `begin_transaction()` / `commit_transaction()` / `rollback_transaction()` y validación de colisión activa (`find_by_element()`).
    - Verificación de estado post-borrado de registros retenidos dentro de la transacción de `WpmlMigrationRollbackService::rollback_groups()`.
    - Detección en `WpmlMigrationExecutor::reconcile()` de registros retenidos faltantes, corruptos (`verify_record_integrity()`) y huérfanos sin grupo TFML activo.
  - **Clarificación de Alcance DDL:**
    - Preservación estricta de `tfml_schema_version = '1.0.0'` (5 tablas maestras de TFML) sin aplicar migraciones DDL no autorizadas sobre `cms_ect_lab`, aprovechando el motor **InnoDB** de `etk_t_options`.
  - **Certificación de los 15 Escenarios Obligatorios en PHPUnit y Base de Datos MySQL Efímera (`cms_ect_lab_tx_test`):**
    - Creación de `tests/Unit/Migration/TransactionalRetainedRecoveryTest.php` y `scratch/verify_fase_3_31.php` certificando los 15 escenarios de fallo inducido, interrupción antes de `COMMIT`, reejecución idempotente, colisiones, promoción atómica, reconciliación de huérfanos, rollback atómico e idempotente y bloqueo de concurrencia.
  - **Métricas de Calidad y Verificación:**
    - PHPUnit: **580 tests, 2.053 assertions, 0 errors, 0 failures** (100% PASS).
    - PHPCS / WPCS: **178/178 archivos analizados, 0 errors, 0 warnings** (100% PASS).
    - Verificador de Fase 3.31 (`scratch/verify_fase_3_31.php`): **40/40 controles PASS (100%)**.
    - Invarianza absoluta certificada en `cms_ect` (3.403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`) y `cms_ect_lab` (120 grupos, 133 elementos, TRID 197 no importado).
- **Importación Piloto Controlada de la Portada Bilingüe TRID 197 en Laboratorio (Fase 3.32 / Directiva DT-TFML-062 — ADR-058):**
  - **Conciliación de Observaciones de Auditoría de Fase 3.31:**
    - Conciliación exacta del conteo de PHPUnit: paso de `570` pruebas totales en Fase 3.30 (`569` ejecutadas + `1` omitida) a `580` pruebas totales en Fase 3.31 (`579` ejecutadas + `1` omitida, `2.053` aserciones) por la adición de exactamente **10 métodos `test_*`** en `TransactionalRetainedRecoveryTest.php` que agrupan los 15 escenarios de fallo inducido sin eliminar ni sustituir pruebas existentes.
    - Delimitación explícita del alcance de concurrencia de `MigrationExecutionLock` (exclusión cooperativa vía `INSERT IGNORE` en InnoDB + *fencing token* pre-commit).
    - Verificación del respaldo histórico inicial (`D:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql`, `2026-10-11 13:24:35`, SHA-256 `f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a`) y generación de respaldo específico pre-piloto de `cms_ect_lab` (`D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_32_pilot_trid197_20261013_233600.sql`, `17.597.240` bytes, SHA-256 `8b40a59daac02844b4a6c7d1414108db2b8836383f4ca2978dc9d141fa74c515`, baseline `a9ea9cd2c6854132c82a1deadbbb4aeeaac630f7`) con restauración íntegra validada en base desechable (`cms_ect_lab_restore_verify`, eliminada tras verificación).
  - **Ejecución Transaccional del Lote Piloto `pilot-trid-197-partial` en `cms_ect_lab`:**
    - Derivación del plan parcial `pilot-trid-197-partial` (`fingerprint = cb9c45034eb0117cd619ce215394a801dfcafe9243a839f355d5896d9e0d17d7`, `parent_fingerprint = 08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`) exclusivamente para el `TRID 197`.
    - Creación atómica del grupo TFML **`group_id = 587`** (`post/page`, `canonical_element_id = 197`), enlaces activos `673` (`197 -> es`) y `674` (`8356 -> en`), y registro retenido `wpml:197` en `RetainedTranslationRegistry` (`fr => 8839`, `fingerprint = 723016cbe0a5ee2044468f882a5f81f9cc3c4154b07c3c7acb18efc3adc81260`), junto con manifiesto `tfml_migration_manifest_pilot-trid-197-partial` y checkpoint `completed`.
    - Consolidación en `cms_ect_lab` de **121 grupos TFML**, **135 elementos activos**, **1 elemento francés retenido** (`8839` ausente de tablas activas) y **0 duplicados**.
  - **Certificación de Resolver, Reconciliación, Idempotencia y Pruebas HTTP:**
    - Verificación de resolución bidireccional `197 (es) <-> 8356 (en)` en `ContentTranslationResolver` y `ServicioTraduccionPublico`, preservación de procedencia canónica y rechazo de `fr` e idiomas inexistentes.
    - Reconciliación sin discrepancias tanto del lote piloto (`1` grupo / `2` activos / `1` retenido) como del universo acumulado (`121` grupos / `135` elementos activos / `1` retenido), y prueba controlada de idempotencia (`idempotent_skipped`, `0` escrituras duplicadas).
    - Comprobación HTTP en vivo sobre `http://lab.ecoterra.test/`: `/` responde `HTTP 200` con `lang="es-ES"` y emite `hreflang` bilingüe (`es`, `en`, `x-default`); `/fr/` responde `HTTP 404` (`lang="es-ES"`); `/en/` responde `HTTP 404` (`lang="en-US"`, `0` errores PHP) y queda registrado como **`BLOCKED BY ROUTING`** conforme a `DT-TFML-062`.
    - Creación del verificador reproducible `scratch/verify_fase_3_32.php` (**40 controles: 39 PASS, 1 BLOCKED por diseño de fase, 0 FAIL**).
- **Resolución Nativa de Portada Multilingüe, Enrutamiento y SEO Canónico (Fase 3.33 / Directiva DT-TFML-063 — ADR-059):**
  - **Nuevo Componente Soberano `TF\Multilingual\Routing\ResolvedorPortadaMultilingue` (`src/Routing/ResolvedorPortadaMultilingue.php`):**
    - Resolución genérica y sin mutación de base de datos de la portada estática multilingüe (`show_on_front === 'page'` y `page_on_front`), sin IDs (`197`, `8356`, `8839`) ni slugs (`inicio`, `home`, `accueil`) cableados.
    - Filtrado contextual en tiempo de ejecución de `option_page_on_front` (`filtrar_opcion_page_on_front`) con semáforo antirrecursión y exclusión estricta de `is_admin()`, `REST_REQUEST` / `wp_is_json_request()`, `wp_doing_cron()`, `wp_doing_ajax()` y `WP_CLI`.
    - Normalización de la consulta principal (`normalizar_consulta_principal_portada` en `parse_query` y `pre_get_posts` prioridad 5) cuando la solicitud corresponde a la raíz de un idioma activo (`/` o `/{lang}/`), restaurando la promoción nativa de WordPress Core (`is_page = true`, `is_home = false`, `is_singular = true`, `page_id = $id_portada_idioma`).
    - Política explícita ante ausencia de traducción publicada de portada en un idioma secundario activo: cero fallback silencioso al español y cero degradación a archivo de blog, produciendo un `HTTP 404` limpio (`is_404() === true`, `_tfml_portada_traducida_ausente = true`).
    - Filtrado de `page_link` (`filtrar_enlace_pagina`) y de `redirect_canonical` (`filtrar_redirect_canonical`) para normalizar los enlaces de portada a la raíz de cada idioma (`/` o `/{lang}/`), redirigir con `HTTP 301` el acceso directo al slug de la portada traducida (`/en/home/` -> `http://lab.ecoterra.test/en/`), evitar que `/en/` redirija a `/` y cancelar redirecciones adivinadas entre idiomas distintos en respuestas `404` (`/en/old-blog/` permanece en `404` sin redirigir a `/old-blog/`).
  - **Filtrado Dinámico Idempotente de `option_rewrite_rules` en `RewriteManager`:**
    - Conexión del filtro `option_rewrite_rules` (`filter_option_rewrite_rules`) con guarda de idempotencia en `generate_multilingual_rules` para que WordPress disponga siempre en memoria de `^(en)/?$ => index.php?tfml_lang=$matches[1]` y de las reglas prefijadas sin requerir `flush_rewrite_rules()` en `init` ni mutar `wp_options`.
  - **Normalización Canónica de Portada en URLs, SEO, Sitemaps y Menús:**
    - `LocalizedUrlGenerator`: integración de `set_resolvedor_portada()` e `is_front_page_for_language()` para que `get_post_translation_url()` devuelva siempre `home_url($code)` en portadas estáticas.
    - `CanonicalUrlManager`: retorno de `''` en `is_404()` y normalización en `localize_post_canonical()` a `home_url($lang_code)` cuando el post es portada estática de su idioma.
    - `HreflangGenerator`: delegación de `get_front_page_hreflang_variants()` en `get_post_hreflang_variants($page_on_front)` en modo página estática y normalización de variantes de portada a `home_url($lang_code)`, emitiendo simétricamente `es -> http://lab.ecoterra.test/`, `en -> http://lab.ecoterra.test/en/` y `x-default -> http://lab.ecoterra.test/` en `/` y `/en/`, y omitiendo traducciones no publicadas o en idiomas inactivos (`fr`).
    - `CoreSitemapsFilter` y `NavMenuFrontendFilter`: normalización de entradas de sitemap e ítems de menú de portada estática a `home_url($lang_code)`.
  - **Pruebas Automatizadas y Certificación HTTP en Vivo:**
    - Nueva suite `tests/Unit/ResolvedorPortadaMultilingueTest.php` (**22 pruebas / 101 aserciones**) más ampliación de `tests/Unit/RewriteManagerTest.php` (**1 prueba / 6 aserciones**), alcanzando **603 pruebas totales en PHPUnit (602 ejecutadas + 1 omitida de integración, 2.160 aserciones, 0 fallos)**.
    - Verificador reproducible `scratch/verify_fase_3_33.php`: **23/23 controles PASS (0 FAIL)** sobre `http://lab.ecoterra.test/` e invariancia total de `cms_ect` y `cms_ect_lab`.
- **Restauración Controlada del Renderizado Visual y Auditoría de Integraciones (Fase 3.34 / Directiva DT-TFML-064 — ADR-060):**
  - **Diagnóstico y Corrección de Dependencias Visuales Físicas y Tablas de Soporte:**
    - Identificación de la causa raíz de la ausencia de maquetación en el laboratorio: ausencia física de los directorios de `elementor`, `elementor-pro` y `revslider` en `wp-content/plugins/` a pesar de estar listados en la opción heredada `active_plugins`.
    - Copia física controlada e incremental de las dependencias autorizadas desde `cms.ecoterra` hacia `lab.ecoterra`: `elementor` (v4.3.5), `elementor-pro` (v4.1.0) y `revslider` (v7.0.15), uniéndose a `ova-framework` (v1.0.0).
    - Transferencia no destructiva de las 16 tablas `etk_t_revslider_*` desde `cms_ect` hacia `cms_ect_lab`, permitiendo a Slider Revolution 7.0 inicializar y renderizar el slider `home-es-1` (`Home Video`) sin tocar tablas WPML, Core ni TFML.
    - Preservación estricta del aislamiento de seguridad: cuarentena de plugins prescindibles o de riesgo (`sitepress-multilingual-cms`, `duplicator`, `akismet`, etc.) y blindaje permanente en `mu-plugins/lab-isolation-guard.php` (`DISABLE_WP_CRON = true`, `WP_HTTP_BLOCK_EXTERNAL = true`, `blog_public = 0`).
  - **Procedimiento de Respaldo Previo y Restauración Verificable:**
    - Generación y custodia del respaldo previo de laboratorio `D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_34_visual_restoration_20261017_125254.sql` (16.79 MB, SHA-256: `51b5cb3c413e4595caba684dc4ae430aa7be9851ec76b842f8876ed0556d1715`).
    - Registro de metadatos `.meta.json` vinculando el micro-baseline oficial de entrada (`f554595110712228a73dda1a17322cbc098ae864`).
    - Certificación de la restauración al 100% en la base de datos efímera `cms_ect_lab_restore_verify` y posterior purga limpia.
    - Cero escrituras en la base de datos protegida original `cms_ect` (3.403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`).
  - **Activación Determinista del Adaptador de Compatibilidad WPML en Laboratorio:**
    - Activación de `AdaptadorCompatibilidadWpml` vía `tfml_enable_wpml_compatibility_adapter = '1'` en `etk_t_options`.
    - Operatividad verificada de las funciones globales `icl_get_languages()` e `icl_object_id()`, exponiendo los idiomas activos (`es`, `en`, `pt`) y excluyendo estrictamente el idioma retenido (`fr:8839`).
    - Certificación de que la constante global `ICL_LANGUAGE_CODE` se mantiene **estrictamente no definida** (`defined('ICL_LANGUAGE_CODE') === false`).
  - **Certificación del Renderizado Visual Completo en Portadas ES (`/`) y EN (`/en/`):**
    - Hidratación completa de la presentación visual: tamaño HTML expandido a ~120-130 KB, confirmando la ejecución de Elementor Builder, secciones y columnas.
    - Cabecera fija (`_header_sticky`), logotipo oficial y menús de navegación bilingües (`menu-menu-principal` en español y `menu-menu-es-ingles` en inglés) completamente funcionales.
    - Selector de idiomas (`[top-idiomas]`) renderizado con banderas e indicador contextual `class="activo"`.
    - Slider Revolution (`<sr7-module data-alias="home-es-1">`) renderizado sin filtraciones textuales del shortcode crudo `[rev_slider]`.
    - Purga de la caché obsoleta `_elementor_element_cache` en `etk_t_postmeta` para reactivar los widgets visuales.
    - Encolado verificado de más de 25 hojas de estilo CSS y más de 25 scripts JS.
    - Respuesta HTTP 200 y 0 errores PHP en ambas portadas.
  - **Métricas de Calidad y Verificación:**
    - Suite PHPUnit: **603 tests, 2.160 assertions, 0 errors, 0 failures** (100% PASS).
    - PHPCS / WPCS: **180/180 archivos analizados, 0 errors, 0 warnings** (100% PASS).
    - Verificador reproducible de Fase 3.34 (`scratch/verify_fase_3_34.php`): **22/22 controles PASS (100%)**.
    - Invarianza total: 121 grupos TFML, 135 elementos activos, 1 retenido (`fr:8839`), 12 manifiestos históricos y centinela intacto en `cms_ect` y `cms_ect_lab`.
  - **Subsanación de Evidencias y Cierre Formal de Fase 3.34 (Directiva DT-TFML-064A — ADR-060):**
    - Conciliación documental del commit real de Fase 3.34 en GitHub (`518a144cf08ec4138ca1c86e818783c939b17d40`), rectificando el hash inexistente reportado previamente (`518a1444d3ba9f032aa2f1d93540be774bc07452`).
    - Incorporación de la excepción `!/scratch/verify_fase_3_34.php` en `.gitignore` y versionado efectivo del verificador reproducible de solo lectura `scratch/verify_fase_3_34.php` (`22/22 PASS`).
    - Certificación de paridad tabla por tabla (`COUNT(*)` y `CHECKSUM TABLE` CRC32) de las 16 tablas `etk_t_revslider_*` entre `cms_ect` y `cms_ect_lab`, auditoría de ausencia de claves comerciales y delimitación explícita entre validación HTTP/DOM/assets (`PASS`) e hidratación JS interactiva en navegador gráfico (`NOT_TESTED`).
- **Certificación Visual, Interactiva y Lingüística del Frontend en Navegador Real (Fase 3.35 / Directiva DT-TFML-065 — ADR-061):**
  - **Inspección Real en Navegador Chromium/Edge vía CDP (Escritorio `1440x900` y Móvil `390x844`):**
    - Ejecución de auditoría visual e interactiva automatizada con `msedge.exe` (v155.0.4283.45) sobre `http://lab.ecoterra.test/` y `http://lab.ecoterra.test/en/`, capturando 10 capturas PNG e inspeccionando consola JS, red, layout y DOM en vivo.
    - Certificación `PASS` de la inicialización JavaScript en cliente de Slider Revolution 7.0 (`window.SR7.M['SR7_6_1']`, `<canvas width="1430" height="900">`, `3` `<sr7-slide>`, `0` excepciones JS) y del menú móvil off-canvas (`ecoCMS/assets/js/elementor/menu-canvas.js`, apertura y cierre de `<nav class="menu-canvas">`).
    - Identificación de incidencias ajenas al núcleo de TFML: embeds externos en cliente dentro del slide `27` de Slider Revolution (YouTube y URL demo `demo.arrowpress.net` no resoluble), ajuste `"menu_slug": "primary"` en el widget móvil `qomfort_elementor_menu_canvas` de las cabeceras `4804`/`8383` que provoca fallback a `wp_page_menu()`, subcarpetas de `wp-content/uploads` (`2023/10`, `2025/08`, `2026/06`) no copiadas aún al laboratorio, y regla CSS del tema sobre `.wpa-sidebar-socials` (`font-family: "Fira Sans Condensed"` en lugar de `"Font Awesome 5 Brands"`).
    - Identificación de dos hallazgos propios de TFML (P1 para próxima fase autorizada): ausencia física del directorio `/wp-content/plugins/tf-multilingual/assets/images/flags/{es,en,pt}.png` referenciado por `AdaptadorCompatibilidadWpml::obtener_url_bandera()`, y desalineación del nombre de clave `tfml_suppress_filters` en `CoreSitemapsFilter::filter_posts_query_args()` frente a `tfml_suppress_language_filter` en `QueryLanguageFilter::is_query_eligible()`.
  - **Auditoría del Idioma Portugués (`pt`), Navegación Bilingüe y Textos Heredados de `ecoCMS`:**
    - Verificación de `pt` activo en `LanguageRegistry` con `0` elementos en `etk_t_tfml_group_elements` y `0` en `etk_t_icl_translations`: expuesto en `[top-idiomas]` vía `icl_get_languages()`, responde `HTTP 404` limpio en `GET /pt/` por política estricta de no-fallback silencioso y se excluye de `hreflang`.
    - Clasificación rigurosa de textos en español observados en `/en/`: fallback a `'es'` de `traducir()` e `ICL_LANGUAGE_CODE` en `ecoCMS/footer.php` e `inc/short-codes.php` (`[habitaciones_aleatorias]`), cadena cableada en `footer.php` y textos `"Nuestro Blog"` / `"Ver más"` guardados en `_elementor_data` del post `8356`.
  - **Verificador Reproducible e Invariancia Total:**
    - Versionado de `scratch/verify_fase_3_35.php` (**17/17 controles PASS, 0 FAIL**), PHPUnit (**603 tests, 2.160 assertions, 0 failures**), PHPCS (**180/180 archivos PASS**) y `composer validate --strict` PASS, manteniendo 100% intactas `cms_ect` y `cms_ect_lab`.
- **Endurecimiento del Núcleo: Sitemaps, Banderas y Disponibilidad Real de Traducciones (Fase 3.36 / Directiva DT-TFML-066 — ADR-062):**
  - **Alineación de Claves de Supresión y Exclusión de Retenidos en `CoreSitemapsFilter`:**
    - Reemplazo de `'tfml_suppress_filters'` por las constantes canónicas `QueryLanguageFilter::QUERY_VAR_SUPPRESS` (`'tfml_suppress_language_filter'`) en `CoreSitemapsFilter::filter_posts_query_args()` y `TermQueryLanguageFilter::QUERY_VAR_SUPPRESS` (`'tfml_suppress_language_filter'`) en `CoreSitemapsFilter::filter_taxonomies_query_args()`.
    - Integración de `RetainedTranslationRegistry` en `CoreSitemapsFilter` y `Plugin::get_core_sitemaps_filter()` para excluir determinísticamente de `post__not_in` y `exclude` todos los elementos retenidos en idiomas inactivos (`fr:8839`).
    - Certificación de que `WP_Sitemaps_Posts('page')` incluye simultáneamente las portadas española (`http://lab.ecoterra.test/`) e inglesa (`http://lab.ecoterra.test/en/`) sin duplicarlas (`1` ocurrencia cada una) y sin exponer `8839`, preservando `blog_public = 0` (`GET /wp-sitemap.xml -> 404` en acceso público directo).
  - **Insignias Lingüísticas SVG Locales (`first-party`) y Degradación Segura sin `404`:**
    - Incorporación de activos vectoriales propios `assets/images/flags/es.svg`, `assets/images/flags/en.svg` y `assets/images/flags/pt.svg` (HTTP 200 `image/svg+xml`).
    - Endurecimiento de `AdaptadorCompatibilidadWpml::obtener_url_bandera()` para priorizar `.svg` sobre `.png`, verificar existencia física en disco mediante `is_file()`, devolver `''` cuando no existe archivo físico (cero `HTTP 404`), rechazar URLs de dominios externos en `tfml_wpml_compat_country_flag_url` y exponer representación textual accesible (`language_badge` y `accessible_label`).
  - **Disponibilidad Real de Traducciones en `ServicioTraduccionPublico` e `icl_get_languages()` y Caso Portugués (`pt`):**
    - Evaluación contextual real en `ServicioTraduccionPublico::obtener_idiomas_activos()` (`front_page`, singular `post`/`page`/CPT, `term`, o archivo general) comprobando publicación (`post_status === 'publish'`), ausencia en `RetainedTranslationRegistry` y existencia de contenido traducido real.
    - Asignación exacta de `missing = 0` vs `missing = 1` y `url = ''` cuando `missing = 1`, evitando enlaces engañosos a rutas `404` como `http://lab.ecoterra.test/pt/` o `/en/old-blog/`.
    - Respeto estricto de `skip_missing=1` (omite `pt` en portada y `en`/`pt` en páginas monolingües españolas como `old-blog`) y `skip_missing=0` (incluye `pt` con `missing=1` y `url=''`).
    - Saneamiento no invasivo del shortcode heredado `[top-idiomas]` / `[top-header]` vía filtro `do_shortcode_tag` (`AdaptadorCompatibilidadWpml::filtrar_salida_shortcode_selector_heredado()`), transformando `<a href="">` en `<a aria-disabled="true" data-tfml-missing="1">` sin atributo `href` y sin modificar el tema `ecoCMS`.
  - **Aislamiento de Red en Navegador Real, Pruebas y Verificación:**
    - Aplicación de `--host-resolver-rules="MAP * ~NOTFOUND , EXCLUDE lab.ecoterra.test , EXCLUDE 127.0.0.1 , EXCLUDE localhost"` en auditoría Chromium/Edge (`0` conexiones externas exitosas, `3/3` insignias SVG cargadas y solo `es`/`en` navegables en el selector público).
    - Ampliación de `tests/Unit/CoreSitemapsFilterTest.php` y `tests/Unit/Compatibilidad/AdaptadorCompatibilidadWpmlTest.php` hasta **609 pruebas PHPUnit (2.214 aserciones, 0 fallos)**, **180/180 archivos PHPCS PASS** y verificador reproducible `scratch/verify_fase_3_36.php` con **14/14 controles PASS** e invarianza total en `cms_ect` y `cms_ect_lab`.
- **Auditoría del Universo Editorial, Sitemaps y Preparación del Siguiente Lote Multilingüe (Fase 3.37 / Directiva DT-TFML-067 — ADR-063):**
  - **Conciliación Exacta de las 61 Páginas de `WP_Sitemaps_Posts('page')` y Demás Subtipos del Sitemap:**
    - Conciliación individual de las 62 páginas publicadas en `etk_t_posts` (`61` incluidas en el sitemap + `1` excluida `8839:fr` vía `RetainedTranslationRegistry`): `3` páginas migradas en TFML (`197:es`, `8356:en`, `4338:es`), `21` páginas `es` no migradas (`20` en `BLOCKED_BY_LANGUAGE` + `1` en `CONFLICT` `ID 3`), `21` páginas `en` no migradas expuestas sin prefijo `/en/` y servidas bajo contexto lingüístico español (`<html lang="es-ES">`), y `16` páginas `fr` publicadas no migradas expuestas en un idioma inactivo.
    - Identificación exacta de las **3 URLs duplicadas** en `WP_Sitemaps_Posts('page')` (`5` entradas colisionando en `/services/`, `/services/restaurant/` y `/blog/`) y auditoría de los demás proveedores (`post=27`, `rooms=33` con `1` duplicado, `ova_framework_hf_el=6` plantillas internas de cabecera, `category=2`, `post_tag=19`).
  - **Inventario de Traducciones Francesas No Migradas y Estrategia Conceptual de Sitemaps:**
    - Clasificación completa de las `1.005` filas `fr` en `etk_t_icl_translations` y de los `50` grupos `BLOCKED_BY_LANGUAGE` restantes (`21 page`, `11 rooms`, `16 nav_menu_item`, `2 ova_framework_hf_el`), más `TRID 3` (`CONFLICT` por colisión cruzada de `post:3` con `term:3`) y `TRID 34637` (`ORPHAN`).
    - Diseño conceptual de la estrategia de endurecimiento de `CoreSitemapsFilter` (inclusión estricta de entidades gestionadas en idiomas activos o filtro SQL de propiedad lingüística sin acoplar el runtime a `icl_translations`, desduplicación determinista de URLs y exclusión de CPTs técnicos internos como `ova_framework_hf_el`).
  - **Auditoría de Menús Principales (`menu-principal` `43` y `menu-es-ingles` `98`) y Preparación del Siguiente Lote Parcial:**
    - Inventario completo de los 16 ítems de `menu-principal` (`es`) y los 16 ítems de `menu-es-ingles` (`en`), identificando los **14 TRIDs de páginas del menú** más el **TRID `432` del CTA de cabecera** (`15` TRIDs candidatos).
    - Identificación preventiva del hallazgo crítico en `NavMenuFrontendFilter::localize_menu_item()` (`src/Domain/Navigation/NavMenuFrontendFilter.php`), donde `get_post_permalink($trans_id)` requiere pasar por `LocalizedUrlGenerator::localize_url()` para que las páginas secundarias migradas emitan `/en/<slug>/` en los menús.
    - Derivación en memoria (`derive_partial_plan_with_retained_languages()` sin ejecutar migración) y cálculo de huellas SHA-256 para tres opciones escalonadas: **Lote 11A** (`4` TRIDs prioritarios: `1712e825c8231edf2ca68767061f3c2fd507c009e0bf1cf922c02ed282b616da`), **Lote 11B** (`9` TRIDs raíz: `a3e9136e031ee9f3fea0b6d233c33b4abefbbf39d85cb35c9c26780165f15693`) y **Lote 11C Recomendado** (`15` TRIDs completos de navegación principal + CTA: `64e3bcbeea7fcaa09a229828c1e180fd0ae82eee324786131f02ed7dee9a876d`).
    - Versionado del verificador reproducible `scratch/verify_fase_3_37.php` (**11/11 controles PASS, 0 FAIL**) con cero escrituras en `cms_ect` y `cms_ect_lab` (`121` grupos, `135` elementos activos, `1` retenido `fr:8839`).
- **Integridad y Elegibilidad de Sitemaps: Elegibilidad Editorial, Exclusión de Idiomas Inactivos y Consistencia de Sitemaps (Fase 3.38 / Directiva DT-TFML-068 — ADR-064):**
  - **Contrato Determinista de Elegibilidad en 6 Categorías (`A..F`) en `CoreSitemapsFilter` (`src/Domain/Seo/CoreSitemapsFilter.php`):**
    - Implementación de `evaluate_post_eligibility()` y `evaluate_term_eligibility()` con clasificación determinista en las categorías `tfml_accredited` (A), `accredited_monolingual` (B), `unclassified` (C), `inactive_language` (D), `retained_translation` (E) y `technical_content` / `non_public_status` (F), sin heurísticas frágiles ni consultas a tablas `icl_*` de WPML (`0` consultas `icl_*` auditadas en ejecución).
    - Adopción de la política conservadora por defecto (`POLICY_CONSERVATIVE_ACCREDITED_ONLY`) con soporte explícito para acreditación monolingüe verificable (`accredit_monolingual_element()`, filtros `tfml_sitemaps_accredited_monolingual_ids` / `tfml_is_accredited_monolingual_element` y conmutación controlada a `POLICY_MONOLINGUAL_DEFAULT`).
  - **Filtrado SQL Nativo en `posts_clauses` y `terms_clauses` y Consistencia de Paginación:**
    - Inyección de restricciones SQL en `CoreSitemapsFilter::filter_sitemap_posts_clauses()` (`posts_clauses`) y `CoreSitemapsFilter::filter_sitemap_terms_clauses()` (`terms_clauses`) para consultas marcadas con `_tfml_sitemap_eligibility = true`, preservando la exactitud de `found_posts`, `max_num_pages`, `wp_count_terms()`, `offset`/`paged` y ordenación sin generar páginas de sitemap vacías.
    - Prevención de colisiones de colación MySQL (`utf8mb4_unicode_ci` vs `utf8mb4_unicode_520_ci`) entre `term_taxonomy` y `tfml_groups` mediante comparación de subtipo contra literal SQL escapado (`tfml_stg.subtype = '{$taxonomy}'`).
    - Sincronización en `Plugin::get_core_sitemaps_filter()` para devolver siempre la misma instancia registrada en los hooks de WordPress.
  - **Exclusión Nativa de Tipos Técnicos y Desduplicación Determinista de URLs:**
    - Filtrado de `wp_sitemaps_post_types`, `wp_sitemaps_posts_pre_max_num_pages` y `wp_sitemaps_posts_pre_url_list` para excluir CPTs técnicos de plantillas y maquetación (`ova_framework_hf_el`, `elementor_library`, `e-floating-buttons`, `e-landing-page`, `wpcf7_contact_form`) preservando CPTs comerciales (`rooms`, `tours`, `product`).
    - Registro de desduplicación determinista por pasada (`$seen_post_urls` y `$seen_term_urls`) y localización canónica de URLs (`/` para portada predeterminada, `/en/` para portada inglesa, `/en/<slug>/` para contenidos secundarios acreditados), garantizando `0` URLs duplicadas incluso ante slugs idénticos entre idiomas (`/blog/` vs `/en/blog/`).
  - **Matriz Conciliada Pre/Post Fase 3.38, Pruebas y Verificación:**
    - Reducción y saneamiento exacto en `cms_ect_lab`: `page` (`61 -> 3`: `/`, `/old-blog/`, `/en/`), `post` (`27 -> 3`, excluyendo las `24` entradas sintéticas `15576..15666`), `rooms` (`33 -> 0`, `max_num_pages = 0`), `ova_framework_hf_el` (`6 -> 0`, excluido), `category` (`2 -> 1`: `term 50 Blog`) y `post_tag` (`19 -> 19`), con `0` páginas francesas (`0/16` + `0/1` retenida `8839`), `0` páginas inglesas sin prefijo (`0/21`) y `0` URLs duplicadas.
    - Ampliación de `tests/Unit/CoreSitemapsFilterTest.php` hasta **12 pruebas / 73 aserciones** (total global: **615 pruebas PHPUnit / 2.263 aserciones, 0 fallos**), **180/180 archivos PHPCS PASS** y verificador reproducible `scratch/verify_fase_3_38.php` con **12/12 controles PASS (0 FAIL)** e invarianza absoluta en `cms_ect` y `cms_ect_lab`.
- **Localización Segura de Enlaces de Menú y Preparación del Piloto de Navegación Bilingüe (Fase 3.39 / Directiva DT-TFML-069 — ADR-065):**
  - **Localización Canónica de Permalinks en `NavMenuFrontendFilter::localize_menu_item()` (`src/Domain/Navigation/NavMenuFrontendFilter.php`):**
    - Paso obligatorio de `get_post_permalink($trans_id)` y `get_taxonomy_term_link($trans_id, $taxonomy)` por `$this->url_generator->localize_url($raw_url, $current_lang)` cuando existe traducción activa publicada en TFML, emitiendo `/en/<slug>/` y `/en/<parent>/<child>/` para idiomas secundarios activos y manteniendo URLs sin prefijo en el idioma predeterminado (`es`).
    - Preservación intacta de query strings (`?foo=bar`) y fragmentos (`#seccion`) del ítem de menú original en ítems `post_type`, `taxonomy` y `custom` mediante `preserve_url_decorations()`.
    - Normalización canónica de la portada estática a `/` (`es`) y `/en/` (`en`) en cualquier contexto lingüístico activo.
  - **Salvaguardas de Seguridad para Contenido No Migrado, Retenido, No Publicado y Esquemas Especiales:**
    - Preservación inalterada de ítems de menú cuyos objetos carecen de traducción activa en TFML (`resolve() === null`), garantizando que las `14` páginas internas aún no migradas de `menu-es-ingles` (`ID 98`) conserven sus URLs `HTTP 200` actuales y emitan `0` enlaces `/en/...` `HTTP 404` prematuros.
    - Integración de `RetainedTranslationRegistry` en `NavMenuFrontendFilter` y `Plugin::get_nav_menu_frontend_filter()` más verificación de `post_status === 'publish'` (`is_translated_post_publishable()`) e inhibición ante idiomas inactivos (`fr`), evitando sustituir menús o reescribir enlaces hacia traducciones retenidas o borradores.
    - Endurecimiento de `LocalizedUrlGenerator::localize_url()` (`src/Routing/LocalizedUrlGenerator.php`) para preservar sin alteración enlaces con esquemas no HTTP (`mailto:`, `tel:`), anclajes puros (`#...`), URLs externas y rutas administrativas o de sistema (`wp-admin`, `wp-login.php`, `wp-json`, `xmlrpc.php`, `wp-cron.php`), además de prevenir doble prefijado (`/en/en/...`).
  - **Auditoría de Jerarquías `post_parent`, Derivación en Memoria del Piloto de 2 TRIDs y Verificación:**
    - Verificación en `cms_ect_lab` de los `10` pares `es`/`en` (`TRID 9545` `Nosotros/About Us` + `3` hijas `TRIDs 424, 9838, 34823`; `TRID 9201` `Actividades/Activities`; `TRID 430` `Galería/Gallery` con slugs reales `galeria`/`gallery`; y `TRID 34652` `Experiencia Transformadora/Transformative Experience` + `3` hijas `TRIDs 34660, 34776, 34791`), acreditando que todos los `post_parent` apuntan al padre del mismo idioma en estado `publish`.
    - Derivación en memoria (`derive_partial_plan_with_retained_languages()` sin ejecutar migración) del piloto inicial de 2 TRIDs para Fase 3.40: **Opción A Recomendada** (`TRIDs 9201 y 430`: `2` grupos, `4` elementos activos, `2` retenidos `fr`, SHA-256 `1dc975df4324dc6ead697ca8d059626330a6143ef2e87368cc8cb8643c588655`) y **Opción B Alternativa** (`TRIDs 9545 y 9201`: `2` grupos, `4` elementos activos, `2` retenidos `fr`, SHA-256 `ce935eb308bee58ae9bf198b8eea03effbf32210e80006e58cabb2451275f9e0`).
    - Ampliación de `tests/Unit/NavMenuFrontendFilterTest.php` y `tests/Unit/LocalizedUrlGeneratorTest.php` cubriendo los **18 escenarios obligatorios** de `DT-TFML-069` (total global: **625 pruebas PHPUnit / 2.307 aserciones, 0 fallos**), **180/180 archivos PHPCS PASS** y verificador reproducible `scratch/verify_fase_3_39.php` con **10/10 controles PASS (0 FAIL)** e invarianza absoluta en `cms_ect` y `cms_ect_lab`.
- **Subsanación Bloqueante de Fase 3.39: Conciliación de TRIDs, Identidad Editorial y Seguridad del Piloto de Migración (Fase 3.39A / Directiva DT-TFML-069A — ADR-065A):**
  - **Conciliación SQL Directa contra `etk_t_icl_translations` y `etk_t_posts`:**
    - Verificación directa en `cms_ect_lab` y `cms_ect` de los TRIDs en disputa (`TRID 9545`: `Nosotros` `6237:es` / `About Us` `8354:en` / `Nous` `8873:fr`; `TRID 9201`: `Actividades` `4976:es` / `Activities` `8339:en` / `Activités` `8955:fr`; `TRID 430`: `Galería de Fotos` `430:es` / `Photo Gallery` `8329:en` / `Galerie` `8989:fr`; `TRID 9818`: `Confort y Tranquilidad` `8042:es` / `Comfort and Tranquility` `8613:en` / `Chambres` `8952:fr`) y de la totalidad de los **15 TRIDs candidatos de navegación** (`424, 430, 432, 9201, 9537, 9545, 9634, 9697, 9818, 9838, 34652, 34660, 34776, 34791, 34823`).
    - Certificación de que los IDs `9864/9863` corresponden en realidad a `TRID 34823` (`Términos y Condiciones / Terms and Conditions`, hijos de `6237/8354`) y `9614/9615` corresponden a `TRID 34776` (`Lek de Gallito de las Rocas / Andean Cock of the Rock Lek`, hijos de `9218/9219`).
  - **Diagnóstico Forense de Causa Raíz Dual:**
    - Identificación de la **Causa Raíz A** en el informe narrativo de Fase 3.37: `scratch/verify_fase_3_37.php` validó conjuntos de TRIDs sin asertar tuplas individuales `(trid => es_id, en_id, fr_id, slug)`, y tras la compactación de contexto (`Step 17188`) las tablas Markdown del informe (`Step 17210`) emparejaron por índice listas ordenadas de post IDs y TRIDs, mientras que los conjuntos y huellas SHA-256 de `ADR-063` sí provinieron de la BD real.
    - Identificación de la **Causa Raíz B** en las huellas del piloto de Fase 3.39: `scratch/verify_fase_3_39.php` derivó los dos planes piloto sobre `$plan_no_conf = $planner->generate_plan(0, 0, false)` (`$check_existing_conflicts = false`) en lugar del plan maestro canónico pre-ejecución `$master_plan = $planner->generate_plan()` (`$check_existing_conflicts = true`, `parent_fingerprint = 34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c`).
  - **Certificación de Planes Piloto Canónicos y Verificador Reproducible (`scratch/verify_fase_3_39a.php`):**
    - Recálculo y certificación en memoria desde `$planner->generate_plan()` del **Piloto Prioritario Recomendado (`pilot-nav-2-trids-9545-9201` — `Nosotros / About Us` `TRID 9545` + `Actividades / Activities` `TRID 9201`)** con huella SHA-256 canónica `ed8b9ede25ad4cf2886b8a1d3a438bfddf59cd9ed4ca3808f4daccbd3b86307d` y del **Piloto Alternativo (`pilot-nav-2-trids-9201-430` — `Actividades / Activities` `TRID 9201` + `Galería / Gallery` `TRID 430`)** con huella SHA-256 canónica `2e290df19eb9f25d364c1626bfc175bcd0885479b8125f94d81e96723923f755`.
    - Incorporación y versionado de `scratch/verify_fase_3_39a.php` con detector de las **7 clases de anomalías** (`trid_mismatch`, `language_mismatch`, `element_type_mismatch`, `missing_object`, `duplicate_object_across_groups`, `tfml_collision`, `plan_vs_db_inconsistency`) y **8/8 controles PASS (0 FAIL)**, manteniendo **625 pruebas PHPUnit**, **180/180 archivos PHPCS PASS** y **cero escrituras** en `cms_ect` y `cms_ect_lab`.
- **Importación Transaccional Controlada de Dos Grupos de Navegación (`pilot-nav-2-trids-9545-9201`) y Certificación de Menús y Sitemaps (Fase 3.40 / Directiva DT-TFML-070 — ADR-066):**
  - **Respaldo Específico Fuera del Webroot y Ensayo de Restauración, Idempotencia y Rollback en Base Desechable (`cms_ect_lab_restore_verify`):**
    - Generación del respaldo previo a Fase 3.40 en `D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_40_pilot_trids_9545_9201_20261018_175300.sql` (`17.891.229` bytes, `2026-10-18T22:56:06Z`, SHA-256 `8f2e9f6283ce49ad9352d9f3f8e794ec9a532f7436baf9eed4ca5eb26f200396`).
    - Restauración verificada en `cms_ect_lab_restore_verify` (`121` grupos, `135` elementos, `1` retenido `fr:8839`, `12` manifiestos, `3.403` filas WPML), prueba de re-ejecución idempotente (`imported_groups = 0, skipped_groups = 2 [idempotent_skipped]`) y reversión selectiva mediante `rollback_groups()` de vuelta a `121/135/1` seguida de `DROP DATABASE cms_ect_lab_restore_verify`.
  - **Derivación Canónica, Dry-Run Sin Escrituras y Ejecución Transaccional Única en `cms_ect_lab`:**
    - Derivación del plan parcial `pilot-nav-2-trids-9545-9201` desde `$planner->generate_plan()` (`parent_fingerprint = 34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c`, `fingerprint = ed8b9ede25ad4cf2886b8a1d3a438bfddf59cd9ed4ca3808f4daccbd3b86307d`), validación en seco sin escrituras (`validate_plan() = true`) y ejecución transaccional única en `cms_ect_lab`.
    - Creación del **grupo `588`** para `TRID 9201` (`Actividades 4976:es` [`id=675`] / `Activities 8339:en` [`id=676`], `canonical_element_id = 4976`) con retención de `8955:fr` (`Activités`), y creación del **grupo `589`** para `TRID 9545` (`Nosotros 6237:es` [`id=677`] / `About Us 8354:en` [`id=678`], `canonical_element_id = 6237`) con retención de `8873:fr` (`Nous`), consolidando **`123` grupos**, **`139` elementos activos**, **`3` traducciones francesas retenidas** (`8839, 8955, 8873`) y **`13` manifiestos** con `0` discrepancias en `reconcile()`.
  - **Certificación de Navegación Bilingüe, Regresión de Sitemaps y Verificador Reproducible (`scratch/verify_fase_3_40.php`):**
    - Certificación HTTP real (`200 OK`) de `/nosotros/`, `/en/about-us/`, `/actividades/`, `/en/activities/`, `/` y `/en/`, localización en vivo de `About Us -> http://lab.ecoterra.test/en/about-us/` y `Activities -> http://lab.ecoterra.test/en/activities/` en `menu-es-ingles` (`ID 98`) manteniendo protegidas las `12` páginas internas aún no migradas (`0` enlaces `/en/...` `HTTP 404` prematuros), y expansión exacta de `WP_Sitemaps_Posts('page')` de `3` a `7` entradas (`26 -> 30` entradas totales consolidadas, `0` francés, `0` duplicados).
    - Documentación de la observación técnica sobre el nombre del filtro en `CanonicalUrlManager::init_hooks()` (`wp_get_canonical_url` frente al hook nativo `get_canonical_url` de WordPress Core) y versionado de `scratch/verify_fase_3_40.php` (**10/10 controles PASS, 0 FAIL**) junto con la actualización dual pre/post-piloto de `scratch/verify_fase_3_39a.php` (**8/8 PASS**), manteniendo **625 pruebas PHPUnit / 2.307 aserciones (0 fallos)** y **180/180 archivos PHPCS PASS**.
- **Corrección Canonical, Diagnóstico de Acceso Administrativo al Laboratorio e Inventario del Panel TF Multilingual (Fase 3.41 / Directiva DT-TFML-071 — ADR-067):**
  - **Corrección SEO en `CanonicalUrlManager::init_hooks()` (`src/Domain/Seo/CanonicalUrlManager.php` y `src/Core/Plugin.php`):**
    - Registro del filtro nativo de WordPress Core `'get_canonical_url'` (prioridad `10`, `2` argumentos) en `CanonicalUrlManager::init_hooks()`, conservando `'wp_get_canonical_url'` (prioridad `10`), `'wpseo_canonical'` (prioridad `20`) y `'rank_math/canonical_url'` (prioridad `20`) sin duplicar etiquetas `<link rel="canonical">`.
    - Aseguramiento de `$url_generator->set_resolvedor_portada($this->resolvedor_portada_multilingue)` en `Plugin::get_canonical_url_manager()` para instanciación perezosa.
    - Certificación HTTP real en `http://lab.ecoterra.test`: `/en/about-us/` emite `1` única etiqueta `<link rel="canonical" href="http://lab.ecoterra.test/en/about-us/" />`, `/en/activities/` emite `1` única etiqueta `<link rel="canonical" href="http://lab.ecoterra.test/en/activities/" />`, y `/nosotros/`, `/actividades/`, `/` y `/en/` conservan sus etiquetas canonical únicas y sus `hreflang`, menús y `30` entradas de sitemap.
  - **Diagnóstico en Solo Lectura del Acceso a `wp-login.php` y Aislamiento de Usuarios en `cms_ect_lab`:**
    - Identificación de la pasarela perimetral `wpa_custom_login_gateway()` en `ecoCMS/functions.php` (líneas 49–91), por la cual el acceso directo sin cookie a `/wp-login.php` redirige (`HTTP 302`) a `/`, mientras que visitar **`http://lab.ecoterra.test/punko`** emite la cookie `wpa_login_token` y redirige a `/wp-login.php` (`HTTP 200 OK` con `#loginform`).
    - Verificación en solo lectura (sin leer `user_pass` y con `0` escrituras) de la cuenta exclusiva de laboratorio `admin_lab` (`ID = 1`, `admin@lab.ecoterra.test`, rol `administrator`, `2` filas en `etk_t_usermeta`) y su aislamiento total respecto a las `3` cuentas de `cms_ect`.
  - **Inventario Real del Panel Administrativo de TF Multilingual y Verificador Reproducible (`scratch/verify_fase_3_41.php`):**
    - Auditoría funcional y visual (6 capturas PNG reales en navegador headless Edge) de todos los menús, pantallas editoriales y rutas REST de TF Multilingual, clasificando cada componente como `IMPLEMENTADO`, `PARCIAL` o `PENDIENTE`, y documentando el hallazgo en `SettingsRepository::sanitize_payload()` respecto a `nav_menu_locations` y el slug padre duplicado `tf-multilingual` vs `tfml-strings`.
    - Ampliación de `tests/Unit/CanonicalUrlManagerTest.php` y `tests/Unit/SeoFrontendFilterTest.php` hasta **626 pruebas PHPUnit / 2.317 aserciones (0 fallos)**, **180/180 archivos PHPCS PASS** y versionado de `scratch/verify_fase_3_41.php` (**12/12 controles PASS, 0 FAIL**) con **cero escrituras** en `cms_ect` y `cms_ect_lab` (`123` grupos, `139` elementos activos, `3` traducciones francesas retenidas).
- **Coherencia del Panel Administrativo, Pantalla Funcional de Idiomas y Diagnóstico Enriquecido (Fase 3.42 / Directiva DT-TFML-073 — ADR-068):**
  - **Armonización de la Jerarquía de Menús de WP-Admin (0 Submenús Duplicados):**
    - Corrección de la duplicidad en el menú lateral de WordPress (`TF Multilingual` duplicando `Cadenas de texto`), estableciendo una jerarquía coherente de 3 pantallas especializadas: `TF Multilingual` / `Idiomas` (`admin.php?page=tf-multilingual`), `Cadenas de texto` (`admin.php?page=tfml-strings`) y `Diagnóstico` (`admin.php?page=tfml-diagnostic`).
    - Registro de `add_submenu_page( self::PARENT_SLUG, ..., 'Idiomas', ..., self::PARENT_SLUG, ... )` para sustituir el primer submenú automático generado por WordPress Core, erradicando entradas redundantes.
  - **Implementación de la Pantalla Funcional de Gestión de Idiomas (`LanguageEditorialUi`):**
    - Creación de `TF\Multilingual\Admin\LanguageEditorialUi` con maquetación nativa de dos columnas (32% formulario / 66% catálogo con layout responsivo estilo WordPress Taxonomías).
    - Selector predefinido ISO con autocompletado en cliente de código, nombre internacional, endónimo y locale gettext.
    - Soporte integral de banderas SVG locales con diseño soberano respetando la paleta de Ecoterra (`assets/images/flags/fr.svg`).
    - Invariantes estrictos de seguridad y dominio: validación obligatoria de `manage_options` y nonces individuales en todas las acciones mutadoras (`add_language`, `toggle_active`, `set_default`, `delete_language`, `update_language`).
    - Blindaje soberano del idioma predeterminado: protección técnica absoluta contra desactivación o eliminación del idioma predeterminado (`es`).
  - **Aclaración Diagnóstica de Navegación y Divulgación de Traducciones Retenidas:**
    - Desglose transparente en `DiagnosticService` y Tarjeta 5 de `DiagnosticUi` de las 3 métricas de navegación: `2` ubicaciones de tema detectadas (`primary`, `footer`), `18` grupos de menús traducidos en Core, y `0` mapeos manuales de sobrescritura en ajustes, eliminando la ambigüedad de *"0 ubicaciones configuradas"*.
    - Divulgación explícita de las `3` traducciones francesas retenidas (`8839, 8955, 8873`) gestionadas por `RetainedTranslationRegistry` en la Tarjeta 3 de `DiagnosticUi` y en el aviso superior de `LanguageEditorialUi`, confirmando que el francés no está activo pero permanece 100% custodiado.
  - **Matriz Modular Soberana y Verificación Automatizada:**
    - Incorporación de la matriz de arquitectura en `LanguageEditorialUi` mapeando servicios de dominio, REST API / Sitemaps y paneles WP-Admin, prescindiendo de pantallas *placeholder* vacías.
    - Ampliación de la suite de pruebas unitarias a **639 pruebas PHPUnit / 2.367 aserciones (0 fallos)** con 13 tests nuevos en `LanguageEditorialUiTest` y actualización de `DiagnosticServiceTest`.
    - Cumplimiento de **PHPCS 100% PASS**, `composer validate --strict` PASS, y certificación reproducible en `scratch/verify_fase_3_42.php` (**10/10 controles PASS, 0 FAIL**).
    - Invarianza total: **cero escrituras en producción `cms_ect`** (MD5 `4241ca7e7ec6399a594537cb04790c10` / 3.403 filas) y conservación íntegra de los `123` grupos y `139` elementos en `cms_ect_lab`.
- **Vinculación y Desvinculación Editorial de Traducciones Existentes en el Panel Administrativo (`PostEditorialUi`, `TranslationEditorialService`, REST API) (Fase 3.43 / Directiva DT-TFML-074 — ADR-069):**
  - **Servicios de Aplicación y Dominio Centralizados (`TranslationEditorialService`):**
    - Implementación de `search_link_candidates()`, `link_translation()` y `unlink_translation()` centralizados en `TF\Multilingual\Editorial\TranslationEditorialService`, operando de manera idéntica y unificada tanto para peticiones de la interfaz gráfica WP-Admin como para llamadas de la REST API.
    - Soporte integrado de `RetainedTranslationRegistry` para evitar vincular elementos retenidos en idiomas inactivos (ej. francés `8839, 8955, 8873`).
    - Protección estricta de invariantes de dominio: validación obligatoria de subtipos homogéneos (`post` vs `page`), detección de conflictos por idiomas duplicados en el grupo, prevención de asignaciones cruzadas de posts ya vinculados a otros grupos, prohibición de auto-vinculación (`source_id === target_id`) e idiomas inactivos.
  - **Experiencia de Usuario Editorial Avanzada en `PostEditorialUi`:**
    - Maquetación por pestañas independientes para idiomas faltantes: pestaña `[+ Crear nueva]` para generar borradores limpios clonando metadatos compartidos, y pestaña `[🔗 Vincular existente]` para conectar contenidos preexistentes sin duplicación.
    - Buscador dinámico de candidatos en vivo con AJAX (`admin-ajax.php?action=tfml_search_candidates`), control de *debounce* de 250ms, filtrado por tipo y subtipo de post y exclusión de elementos ya asociados a TFML.
    - Despliegue interactivo de candidatos mostrando título, ID numérico, slug y estado (`publish`, `draft`, etc.), acompañado de caja de previsualización (`tfml-candidate-preview-box`) y mecanismo de respaldo manual directo por ID numérico.
    - Botón de desvinculación `[Desvincular]` con icono `dashicons-editor-unlink`, confirmación JavaScript en navegador y procesamiento transaccional seguro con nonces individuales dedicados.
  - **Desvinculación Segura sin Destrucción de Entidades Core (`wp_posts`):**
    - `unlink_translation()` elimina limpiamente las filas relacionales en `tfml_group_elements` y actualiza/promueve el elemento canónico en `tfml_groups` si el elemento desvinculado era el canónico, garantizando CERO eliminaciones o mutaciones en `wp_posts` o `wp_terms`. El objeto de WordPress queda preservado al 100% como contenido libre e intacto.
  - **Exposición REST API (`TranslationsController`):**
    - Registro del endpoint `GET /wp-json/tf-multilingual/v1/translations/candidates` con validación estricta de capacidades (`edit_post`), sanitización de parámetros de búsqueda (`search`, `element_type`, `subtype`, `exclude_group_id`, `limit`) y respuestas JSON estructuradas.
  - **Suite de Pruebas Automatizadas y Calidad:**
    - Ampliación de la suite PHPUnit hasta **655 pruebas unitarias / 2.434 aserciones (0 fallos, 0 errores, 1 omitida)**, incorporando `tests/Unit/PostEditorialUiTest.php` (4 pruebas, 38 aserciones) y extendiendo `TranslationEditorialServiceTest` (10 pruebas nuevas), `TranslationsControllerTest` y `CanonicalUrlManagerTest`.
    - Cumplimiento de **PHPCS 100% PASS** en 181 archivos bajo los estándares de WordPress Core (`WordPress-Extra`, `WordPress-Docs`).
    - Certificación en el verificador reproducible `scratch/verify_fase_3_43.php` con **10/10 controles PASS (0 FAIL)**, captura visual de pantalla en `/brain/` (`fase_3_43_admin_post_link.png`) e invarianza absoluta en producción `cms_ect` (MD5 `4241ca7e7ec6399a594537cb04790c10` / 3.403 filas) y laboratorio `cms_ect_lab` (123 grupos, 139 elementos, 3 franceses retenidos).
- **Hardening de Seguridad Editorial: Desvinculación mediante POST Estricto, Protección Anti-Tampering y Transaccionalidad Atómica (Fase 3.43A / Directiva DT-TFML-075 — ADR-070):**
  - **Erradicación Absoluta de Peticiones Modificadoras vía GET (`PostEditorialUi`):**
    - Eliminación de todo enlace `<a>` que ejecutaba desvinculación vía solicitud GET.
    - Implementación de un formulario inline POST `<form class="tfml-unlink-form" method="post" action="admin-post.php">` con campos ocultos para `action`, `source_post_id`, `element_id` y nonce criptográfico individualizado (`tfml_unlink_nonce`), junto con botón submit nativo de WordPress (`button-small tfml-unlink-btn`) con icono `dashicons-editor-unlink` y diálogo de confirmación explícito al usuario en el evento `onsubmit`.
    - Blindaje incondicional en los controladores `handle_unlink_translation()`, `handle_create_translation()` y `handle_link_translation()`: cualquier solicitud entrante cuyo método sea `GET` (`$_SERVER['REQUEST_METHOD'] === 'GET'`) es interceptada y rechazada de inmediato con `wp_die( ..., 405 )` (Method Not Allowed), eliminando cualquier vector de ataque por pre-fetching o CSRF vía GET.
  - **Protección Anti-Tampering y Validación Contextual Cruzada (`TranslationEditorialService`):**
    - Actualización de la firma de `unlink_translation( string $element_type, int $element_id, ?int $new_canonical_id = null, ?int $context_source_id = null )` para aceptar e imponer la validación del contexto de origen `$context_source_id`.
    - Validación rigurosa de consistencia de grupo: el servicio verifica que `$context_source_id` pertenezca exactamente al mismo grupo de traducción de TFML que `$element_id`. Si se detecta un desfase o intento de manipulación cruzada entre grupos distintos, se arroja de forma inmediata `EditorialConflictException`.
    - Verificación estricta de capacidades editoriales (`edit_post`) tanto sobre el elemento destino `$element_id` como sobre el post de contexto `$context_source_id` (`EditorialPermissionException`).
  - **Integridad Relacional, Ciclo de Vida y Transaccionalidad Atómica Certificada:**
    - Verificación del ciclo de vida completo: desvinculación secundaria (remoción de `tfml_group_elements` preservando el objeto WordPress Core en `wp_posts` al 100%), desvinculación canónica (promoción automática de nuevo canónico), y desvinculación del último elemento restante con purga controlada del grupo huérfano vacío en `tfml_translation_groups` sin tocar el post de Core.
    - Certificación de custodia de traducciones retenidas: las 3 traducciones retenidas en francés (`8839, 8955, 8873`) en `RetainedTranslationRegistry` permanecen totalmente inmunes durante cualquier operación de desvinculación.
    - Idempotencia: los reintentos de desvinculación sobre elementos ya desvinculados arrojan limpiamente `EditorialValidationException`.
    - Rollback atómico en base de datos: encapsulamiento de toda mutación relacional en bloques transaccionales (`begin_transaction`, `commit_transaction`, `rollback_transaction`), garantizando la reversión total ante excepciones o fallos en base de datos.
  - **Alineación con el Endpoint REST (`TranslationsController`):**
    - Soporte del parámetro opcional `source_id` en la ruta `/translations/unlink`, validación de permisos en `unlink_permissions_check()` y prevención de manipulación cruzada entre grupos.
  - **Suite de Pruebas Unitarias y Calidad:**
    - Ampliación de la suite PHPUnit a **670 pruebas unitarias / 2.475 aserciones (100% PASS, 0 fallos, 0 errores, 1 omitida)**, sumando 15 nuevas pruebas distribuidas entre `PostEditorialUiTest`, `TranslationEditorialServiceTest` y `TranslationsControllerTest`.
    - Cumplimiento de **PHPCS 100% PASS en 183/183 archivos**.
    - Certificación en el verificador reproducible `scratch/verify_fase_3_43a.php` con **10/10 controles PASS (0 FAIL)**, captura visual de alta resolución `fase_3_43a_admin_post_link.png` y verificación frontend de 4/4 páginas con HTTP 200 y canonicals correctos.
    - Invarianza absoluta de datos: producción `cms_ect` intocada (MD5 `4241ca7e7ec6399a594537cb04790c10` / 3.403 filas) y laboratorio `cms_ect_lab` invariable (123 grupos, 139 elementos, 3 franceses retenidos).
- **Centro Administrativo de Traducciones y Resolución de Reserva de Fase 3.43A (Fase 3.44 / Directiva DT-TFML-076 — ADR-071):**
  - **Resolución Definitiva de la Reserva de Fase 3.43A (Preservación de Grupos con Retenidas):**
    - Implementación del parámetro opcional `$purge_empty_group = true` en `TranslationGroupRepository::remove_translation()`.
    - En `TranslationEditorialService::unlink_translation()`, se consulta si el grupo tiene registros en `RetainedTranslationRegistry::get_by_group_id($group_id)`. Si existen traducciones históricas retenidas (ej. francés), `$purge_empty_group` se establece en `false`, preservando la fila del grupo en `tfml_groups` con `canonical_element_id = null`.
    - Se garantiza la conservación de la clave foránea conceptual, la consistencia con el manifiesto de migración y la futura promovabilidad de la traducción retenida (`promote_retained_translation`) cuando se active el idioma francés.
  - **Nuevo Centro de Traducciones en el Panel de Administración (`TranslationsOverviewUi`):**
    - Creación de `TF\Multilingual\Admin\TranslationsOverviewUi`, registrando la pantalla de gestión centralizada `TF Multilingual → Traducciones` (`admin.php?page=tfml-translations`).
    - Calibración de la jerarquía de submenús mediante prioridades en `admin_menu`: Idiomas (9) -> Traducciones (10) -> Cadenas de texto (11) -> Diagnóstico (12), eliminando cualquier duplicidad o desorden en el menú lateral.
    - Control estricto de acceso basado en capacidades nativas (`edit_posts`).
  - **Resumen Ejecutivo y Métricas Globales (4 Tarjetas KPI):**
    - Encabezado con 4 tarjetas de estadísticas nativas de WordPress: Grupos Registrados (123), Elementos Vinculados (139), Idiomas Activos (ES, EN, PT) y Traducciones Retenidas (3 registros FR).
  - **Motor de Búsqueda, Filtrado Avanzado y Paginación (`query_groups_overview`):**
    - Implementación de `query_groups_overview()` en `TranslationGroupRepository` con consultas SQL paginadas (`LIMIT` y `OFFSET`) y cálculos de totales globales y filtrados.
    - Soporte de búsqueda textual por título, slug o ID (`s`), filtrado por subtipo exacto (`subtype`) mediante `get_distinct_subtypes()`, filtrado por estado editorial (`status`: todos, completas, incompletas, revisión) y por idioma presente (`lang`).
  - **Tabla Central de Traducciones e Indicadores Editoriales:**
    - Maquetación con `.wp-list-table.widefat.fixed.striped`, mostrando Grupo/Subtipo, Contenido Canónico (con enlace directo al editor y estado de publicación), etiqueta `Origen`, y una columna por cada idioma activo (`ES`, `EN`, `PT`).
    - Insignias de estado con iconos oficiales: `Actualizada` (verde), `Requiere revisión` (amarillo) y `— Sin traducción [+ Traducir]` (enlace rápido al editor).
  - **Sección Informativa de Custodia Histórica Francesa:**
    - Tarjeta `.postbox` dedicada que expone con total transparencia técnica las 3 traducciones retenidas de la migración WPML (`Accueil #8839`, `Activités #8955`, `Nous #8873`), sus TRIDs de origen (`197`, `9201`, `9545`), grupo TFML asociado y estado de custodia inactiva.
  - **Naturaleza Estrictamente de Solo Lectura y Aislamiento de Datos:**
    - Cero operaciones de escritura o mutación en la pantalla del Centro de Traducciones.
    - Invarianza total: producción `cms_ect` intocada (MD5 inmutable `4241ca7e7ec6399a594537cb04790c10` / 3.403 filas) y laboratorio `cms_ect_lab` invariable (123 grupos, 139 elementos, 3 franceses retenidos).
  - **Suite de Pruebas Unitarias y Calidad:**
    - Ampliación de la suite PHPUnit a **681 pruebas unitarias / 2.526 aserciones (100% PASS, 0 fallos, 0 errores, 1 omitida)**, sumando 11 nuevas pruebas entre `TranslationsOverviewUiTest` y `TranslationGroupRepositoryTest`.
    - Cumplimiento de **PHPCS 100% PASS en todo `src/` (102/102 archivos)**.
    - Certificación en el verificador reproducible `scratch/verify_fase_3_44.php` con **10/10 controles PASS (0 FAIL)**, capturas visuales de alta resolución `fase_3_44_admin_translations.png` y `fase_3_44_admin_translations_full.png`, y verificación frontend de 4/4 páginas con HTTP 200 y canonicals correctos.
- **Panel Administrativo de Migración WPML (Fase 3.45 / Directiva DT-TFML-077 — ADR-072):**
  - **Nueva Pantalla Administrativa de Migración WPML (`MigrationUi`):**
    - Creación de la clase `TF\Multilingual\Admin\MigrationUi`, registrando la pantalla de inspección y planificación `TF Multilingual → Migración WPML` (`admin.php?page=tfml-migration`) con capacidad `manage_options`.
    - Calibración y reordenamiento de prioridades en el hook `admin_menu`: Idiomas (9) -> Traducciones (10) -> Cadenas de texto (11) -> Migración WPML (12) -> Diagnóstico (13), estableciendo una secuencia lógica integral de administración del plugin.
  - **Garantía Estricta de Solo Consulta (Read-Only Security):**
    - Banner informativo prominente en la cabecera destacando que el panel opera exclusivamente en modo lectura y auditoría.
    - Rechazo inmediato de solicitudes POST con código de error HTTP 403.
    - Cero mutaciones en la base de datos de producción (`cms_ect`) ni en la de laboratorio (`cms_ect_lab`), garantizando la invarianza absoluta de los 123 grupos, 139 elementos y 3 traducciones retenidas.
  - **Reutilización Soberana de Servicios Certificados de Migración:**
    - Cero duplicación de motores: todos los datos del panel se obtienen directamente de `MigrationManager`, `WpmlMigrationPlanner`, `MigrationCheckpointRepository`, `RetainedTranslationRegistry`, `WpmlMigrationRollbackService` y `TranslationGroupRepository`.
  - **Cuatro Pestañas de Navegación Funcional:**
    - **Plan Determinista (`tab=plan`):** Despliega el resumen de 249 TRIDs WPML, la huella SHA-256 (`c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99`), estado `NEEDS_CONFIGURATION`, resumen del modelo de deduplicación de medios (Model B: 3.033 adjuntos), filtros por clasificación (`all`, `blocked_by_language`, `conflict`, `orphan`, `skipped`, `importable`), subtipo y búsqueda (`s`), con tabla paginada a 20 grupos por página.
    - **Historial de Lotes (`tab=history`):** Expone el punto de control activo (`status: completed`, `last_processed_trid: 9545`, `total_items: 2`, bloqueo libre) y la tabla histórica de los 13 manifiestos inmutables en `wp_options` (`tfml_migration_manifest_*`), sumando exactamente 123 grupos importados.
    - **Custodia Histórica (`tab=retained`):** Explica con total transparencia técnica el modelo de custodia sin polución activa para las 3 traducciones en francés (`Accueil #8839`, `Activités #8955`, `Nous #8873`) vinculadas a sus respectivos grupos TFML (`#587`, `#588`, `#589`).
    - **Propuesta Próximo Lote (`tab=next_batch`):** Análisis analítico de las 19 páginas en `BLOCKED_BY_LANGUAGE`, clasificándolas en 7 páginas independientes (TRIDs 430, 432, 9537, 9634, 9697, 9818, 34831) y 12 páginas jerárquicas con padres ya migrados en TFML (#6237 Nosotros, #6505 Servicios, #9218 Experiencia Transformadora), detallando las precondiciones mandatorias para una futura ejecución.
  - **Enriquecimiento del Servicio de Rollback (`WpmlMigrationRollbackService`):**
    - Se añadió el método público `get_all_execution_manifests(): array` para recuperar cronológicamente todos los manifiestos de ejecución persistidos en `wp_options`.
  - **Suite de Pruebas Unitarias y Calidad:**
    - Ampliación de la suite PHPUnit a **691 pruebas unitarias / 2.558 aserciones (100% PASS, 0 fallos, 0 errores, 1 omitida)** con 10 nuevas pruebas en `tests/Unit/MigrationUiTest.php`.
    - Cumplimiento de **PHPCS 100% PASS en todo el proyecto (0 errores, 0 avisos)**.
    - Certificación en el verificador reproducible `scratch/verify_fase_3_45.php` con **10/10 controles PASS (0 FAIL)**.
    - 4 capturas visuales de alta resolución generadas en el directorio de artefactos (`fase_3_45_admin_migration_plan.png`, `fase_3_45_admin_migration_history.png`, `fase_3_45_admin_migration_retained.png`, `fase_3_45_admin_migration_next_batch.png`).
    - Cero regresiones en frontend: HTTP 200 en 4/4 URLs analizadas, canonicals correctos y 30 entradas en sitemaps sin traducciones retenidas.
- **Conciliación Canónica del Panel de Migración y Preparación del Siguiente Lote (Fase 3.45A / Directiva DT-TFML-078 — ADR-073):**
  - **Resolución Integral de Discrepancias y Conciliación Forense en Solo Lectura:**
    - Auditoría SQL exhaustiva y conciliación de identidades canónicas de los 19 TRIDs en estado `BLOCKED_BY_LANGUAGE` directamente contra las tablas maestras `etk_t_icl_translations`, `etk_t_posts` y el registro de grupos de TFML en `cms_ect_lab`.
    - Corrección de las denominaciones espurias reportadas erróneamente en Fase 3.45 (títulos hallucinados en la narrativa de informe como "Legal", "Política Cookies", etc.), restaurando los títulos y slugs reales persistidos en base de datos.
    - Re-acreditación canónica e inmutable de los grupos históricos de navegación: Grupo TFML `588` = Actividades (`#4976` ES / `#8339` EN / `#8955` FR retenida) derivado del TRID 9201; Grupo TFML `589` = Nosotros (`#6237` ES / `#8354` EN / `#8873` FR retenida) derivado del TRID 9545.
    - Purga formal de los supuestos grupos TFML `593` y `594`: se acreditó matemáticamente que el ID máximo de grupo en `etk_t_tfml_groups` es `589` y que ni Servicios (`#6505` / TRID 9563) ni Experiencia Transformadora (`#9218` / TRID 34652) han sido migrados a TFML.
  - **Rediseño Arquitectónico de la Pestaña de Próximo Lote (`MigrationUi::render_tab_next_batch`):**
    - Despliegue de banner preventivo formal de bloqueo: indicación explícita de que el plan maestro se encuentra en estado `NEEDS_CONFIGURATION` (huella SHA-256 `c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99`) debido a los 48 grupos que contienen traducciones retenidas en francés, prohibiendo cualquier ejecución no autorizada.
    - Segregación estricta de las 19 páginas candidatas en tres tablas relacionales independientes:
      1. **9 Raíces Autónomas Candidatas (`post_parent = 0`):** TRIDs 430 (Galería de Fotos), 432 (Contáctenos), 9537 (Paquetes), 9563 (Servicios), 9634 (Naturaleza Auténtica), 9697 (Experiencia EcoTerra), 9818 (Confort y Tranquilidad), 34652 (Experiencia Transformadora) y 34831 (Blog), cada una con sus títulos reales, slugs, idiomas activos y francés en custodia.
      2. **4 Páginas Hijas Jerárquicas Acreditadas:** TRIDs 424 (¿Quiénes Somos?), 9838 (Reviews), 34632 (Accesibilidad y Transportes) y 34823 (Terms and Conditions), cuyos padres son miembros plenamente acreditados del Grupo TFML `#589` (Nosotros / About Us).
      3. **6 Páginas Hijas Jerárquicas Bloqueadas:** TRIDs 9326 y 9574 (hijas de Servicios `#6505`) y TRIDs 34660, 34776, 34790 y 34791 (hijas de Experiencia Transformadora `#9218`), cuyo padre aún no ha sido migrado a TFML.
    - Incorporación de métodos auxiliares `get_post_details( int $post_id ): array` y `get_element_tfml_group_id( int $element_id ): ?int` en `MigrationUi` para consulta en tiempo de renderizado de títulos, slugs y pertenencia de grupo.
  - **Derivación Canónica y Certificación del Piloto Mínimo:**
    - Propuesta y derivación analítica en memoria del piloto mínimo unificado de navegación (`pilot-nav-2-trids-430-432`) utilizando `MigrationPlan::derive_partial_plan_with_retained_languages()` sobre el plan maestro.
    - Cálculo y trazabilidad del linaje criptográfico: huella del plan maestro `c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99` y huella del piloto derivado `e18b00794e7ab339347c9fe101e067f2c95f339643a0957d765e1b135446c130`.
    - Composición canónica: 2 TRIDs (430 y 432), 4 elementos activos (2 ES, 2 EN), 2 traducciones en custodia francesa (`#8989` y `#8823`), cero conflictos y estado `READY`.
    - Prohibición explícita de ejecución en interfaz y código (`DETENIDO / NO AUTORIZADO`), manteniéndose en estricto modo de consulta.
  - **Gobernanza, Seguridad y Verificación Reproducible:**
    - Invarianza absoluta de datos: producción `cms_ect` intocada (MD5 `4241ca7e7ec6399a594537cb04790c10` / 3.403 filas) y laboratorio `cms_ect_lab` invariable (123 grupos, 139 elementos, 3 franceses retenidos, 13 manifiestos).
    - Suite de pruebas PHPUnit: **691 pruebas unitarias / 2.558 aserciones (100% PASS, 0 fallos, 0 errores, 1 omitida)**.
    - Cumplimiento de **PHPCS 100% PASS en 187/187 archivos (0 errores, 0 avisos)**.
    - Certificación en el verificador reproducible `scratch/verify_fase_3_45a.php` con **10/10 controles PASS (0 FAIL)**.
    - Generación de artefacto visual en alta resolución: `fase_3_45a_admin_migration_next_batch.png`.
- **Importación Transaccional Controlada del Piloto de Navegación TRID 430/432 (Fase 3.46 / Directiva DT-TFML-079 — ADR-074):**
  - **Compuertas Previas y Protocolo de Respaldo Fuera del Webroot:**
    - Verificación exitosa de compuertas previas: centinela WPML en producción `cms_ect` (3.403 filas / MD5 `4241ca7e7ec6399a594537cb04790c10`), centinela WPML en laboratorio `cms_ect_lab` (3.403 filas / MD5 `4241ca7e7ec6399a594537cb04790c10`), estado base de TFML verificado (123 grupos, 139 elementos, 3 FR retenidas, 13 manifiestos, checkpoint `completed` y lock libre).
    - Generación de respaldo físico SQL completo fuera de DocumentRoot en `D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_46_pilot_trids_430_432_20261019_063119.sql` (17.806.013 bytes, SHA-256 `5d6fceeccc37a6c26603898e440bfaa620786d880fb05144a5c8ce7d4bfb7e23`).
  - **Certificación y Pruebas en Base de Datos Desechable (`cms_ect_lab_restore_verify`):**
    - Restauración completa y certificación de volcado en base de datos efímera (`cms_ect_lab_restore_verify`) confirmando métricas base (123/139/3/13).
    - Ejecución de prueba del lote piloto `pilot-nav-2-trids-430-432`: 123 -> 125 grupos, 139 -> 143 elementos, 3 -> 5 retenidas, 13 -> 14 manifiestos.
    - Prueba de idempotencia estricta en segundo ciclo: 2 grupos saltados (`idempotent_skipped`), 0 grupos duplicados.
    - Prueba de reversión selectiva mediante `WpmlMigrationRollbackService::rollback_groups()`: retorno limpio al estado base previo (123 grupos, 139 elementos, 3 retenidas).
    - Destrucción segura de la base de datos efímera y persistencia de metadatos de auditoría en `meta.json`.
  - **Validación Criptográfica y Ejecución Transaccional Atómica en `cms_ect_lab`:**
    - Derivación canónica del plan parcial `pilot-nav-2-trids-430-432` con trazabilidad criptográfica: huella padre `c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99`, huella piloto `e18b00794e7ab339347c9fe101e067f2c95f339643a0957d765e1b135446c130`, 2 grupos, 4 elementos activos (2 ES, 2 EN), 2 retenidos en francés (#8989 y #8823), 0 conflictos, estado `READY`.
    - Ejecución atómica única vía `WpmlMigrationExecutor`:
      * TRID 430 -> Asignado al Grupo TFML #590 (canónico `#430` Galería de Fotos [ES], miembro `#8329` Photo Gallery [EN], `#8989` Galerie en custodia francesa inactiva en `RetainedTranslationRegistry`).
      * TRID 432 -> Asignado al Grupo TFML #591 (canónico `#432` Contáctenos [ES], miembro `#8327` Contact Us [EN], `#8823` Contactez-nous en custodia francesa inactiva en `RetainedTranslationRegistry`).
    - Generación de manifiesto inmutable `tfml_migration_manifest_pilot-nav-2-trids-430-432` y actualización del checkpoint a `completed` con lock libre.
  - **Consolidación de Métricas en Laboratorio:**
    - Grupos TFML: de 123 a **125**.
    - Elementos Activos: de 139 a **143**.
    - Traducciones Retenidas FR: de 3 a **5**.
    - Manifiestos de Migración: de 13 a **14**.
    - Cero elementos en francés activos (`fr_active = 0`) y cero duplicidades relacionales.
    - Reconciliación global con 0 discrepancias (`reconcile()`).
  - **Certificación Funcional de Enrutamiento, Menús y Sitemaps:**
    - Comprobación en servidor HTTP real (`http://lab.ecoterra.test`):
      * `/galeria/` (Post #430): HTTP 200, `lang="es-ES"`, canonical `/galeria/`, hreflang simétrico y alternativo.
      * `/en/gallery/` (Post #8329): HTTP 200, `lang="en-US"`, canonical `/en/gallery/`, hreflang simétrico y alternativo.
      * `/contactenos/` (Post #432): HTTP 200, `lang="es-ES"`, canonical `/contactenos/`, hreflang simétrico y alternativo.
      * `/en/contact-us/` (Post #8327): HTTP 200, `lang="en-US"`, canonical `/en/contact-us/`, hreflang simétrico y alternativo.
      * Cero regresiones en rutas previamente migradas: `/`, `/en/`, `/nosotros/`, `/en/about-us/`, `/actividades/`, `/en/activities/` (todas 200 OK); `/fr/` y `/pt/` responden 404 limpio.
    - Menú de escritorio `menu-menu-es-ingles` (ID 98): `Photo Gallery` enlaza automáticamente a `http://lab.ecoterra.test/en/gallery/`, mientras las 11 subpáginas pendientes se mantienen protegidas sin enlaces rotos 404 prematuros.
    - Sitemaps de Core: el subtipo `page` pasa de 7 a 11 entradas; total general pasa de 30 a 34 entradas únicas (0 francés, 0 duplicados, `blog_public = 0`).
  - **Invarianza Absoluta de Producción (`cms_ect`):**
    - La base de datos de producción `cms_ect` permanece 100% inalterada: 3.403 filas en `etk_t_icl_translations`, MD5 `4241ca7e7ec6399a594537cb04790c10` (delta cero absoluto).
  - **Gobernanza, Calidad y Verificación Reproducible:**
    - Suite de pruebas PHPUnit: **691 pruebas unitarias / 2.558 aserciones (100% PASS, 0 fallos, 0 errores, 1 omitida intencional)**.
    - Cumplimiento de **PHPCS 100% PASS en 187/187 archivos (0 errores, 0 avisos)**.
    - Verificador reproducible `scratch/verify_fase_3_46.php` certificado con **10/10 controles PASS (0 FAIL)**.
    - Artefactos visuales de alta resolución generados en `/brain/`: `fase_3_46_admin_migration_history.png` y `fase_3_46_admin_migration_retained.png`.
- **Conciliación del Piloto Anterior y Preparación Canónica del Siguiente Bloque (Fase 3.47 / Directiva DT-TFML-080 — ADR-075):**
  - **Conciliación Focalizada y Cierre de la No-Conformidad de Fase 3.46:**
    - Verificación forense en solo lectura de TRID 432 y Grupo TFML #591 en `cms_ect_lab`: confirmación plena de que el elemento en español es el Post `#432` ("Contactenos", slug `contactenos`), el elemento en inglés es el Post `#8327` ("Contact Us", slug `contact-us`) y la traducción retenida en francés es el Post `#8823` ("Contactez-nous", slug `contactez-nous`).
    - Descarte formal de la referencia `#8334`: se acreditó en base de datos que en WPML pertenece al TRID 26772 con idioma `es` y no existe registro físico en la tabla `wp_posts`.
    - Registro de la no-conformidad procedimental por haber auto-corregido la identidad sin detener la ejecución y endurecimiento preventivo de compuertas (parada obligatoria ante discrepancias nominales entre directiva y base de datos).
  - **Identidades Definitivas del Lote TRID 9537 y TRID 9563 directamente de Base de Datos:**
    - **TRID 9537 (Paquetes / Tour Packages):** ES Post `#6214` (`paquetes`, status `publish`, `post_parent = 0`), EN Post `#8335` (`tour-packages`, status `publish`, `post_parent = 0`), FR Post `#8974` (`forfaits-touristiques`, status `publish`, `post_parent = 0`, retenible); 0 registros previos en TFML.
    - **TRID 9563 (Servicios / Services):** ES Post `#6505` (`servicios`, status `publish`, `post_parent = 0`), EN Post `#8347` (`services`, status `publish`, `post_parent = 0`), FR Post `#8918` (`services`, status `publish`, `post_parent = 0`, retenible); 0 registros previos en TFML.
  - **Análisis Riguroso de Dependencias y Jerarquía (Padres e Hijos):**
    - TRID 9537 es una página raíz autónoma (`post_parent = 0`) sin dependencias previas ni páginas hijas en WPML.
    - TRID 9563 es una página raíz autónoma (`post_parent = 0`) sin dependencias previas, y actúa como padre de dos páginas hijas en WPML: TRID 9326 (Salón de Eventos) y TRID 9574 (Restaurante). Su futura migración satisfará íntegramente la precondición jerárquica de padre para ambas hijas en TFML.
  - **Determinismo Criptográfico y Derivación Canónica del Piloto:**
    - Huella del plan maestro actual en `cms_ect_lab` (125 grupos TFML): `d3b02acff32de4c2c95b8c8561615d04e243c2d6a200c5bd30e17d93d5393b67` (estado `NEEDS_CONFIGURATION`).
    - Huella del lote parcial derivado canónicamente `pilot-nav-2-trids-9537-9563`: `7a1b8992fe352472f8ae8177e12da60a4aa825a3814d23301e222ab2543fd844` (estado `READY`).
    - Composición: 2 grupos (TRIDs 9537 y 9563), 4 elementos activos (2 ES, 2 EN), 2 retenidos en custodia francesa (`#8974` y `#8918`), 0 conflictos, 0 omitidos, 0 huérfanos.
  - **Modo Estricto de Solo Consulta y Cero Escrituras:**
    - Prohibición formal de ejecución en esta fase.
    - Reconciliación global con 0 discrepancias (`reconcile()`) sobre los 125 grupos, 143 elementos, 5 FR retenidas y 14 manifiestos existentes.
    - Verificación HTTP en vivo de 10 rutas públicas (200 OK con lang y canonical exactos; 404 limpio en `/fr/` y `/pt/`).
    - Producción `cms_ect` 100% intacta (3.403 filas / MD5 `4241ca7e7ec6399a594537cb04790c10`).
  - **Gobernanza, Calidad y Verificación Reproducible:**
    - Suite de pruebas PHPUnit: **691 pruebas unitarias / 2.558 aserciones (100% PASS, 0 fallos, 0 errores, 1 omitida intencional)**.
    - Cumplimiento de **PHPCS 100% PASS en 187/187 archivos (0 errores, 0 avisos)**.
    - Verificador reproducible `scratch/verify_fase_3_47.php` certificado con **10/10 controles PASS (0 FAIL)**.







