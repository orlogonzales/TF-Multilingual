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

