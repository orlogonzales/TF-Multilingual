# ROADMAP TÃ‰CNICO OFICIAL â€” TF MULTILINGUAL
**Documento:** ROADMAP.md
**Proyecto:** TF Multilingual (`tf-multilingual`)
**VersiÃ³n:** 1.0.0
**Estado:** **APROBADO**

---

## 1. ALCANCE GENERAL Y CRITERIOS

El desarrollo de TF Multilingual estÃ¡ estructurado en microfases controladas y secuenciales. Cada fase requiere la superaciÃ³n de sus respectivos gates de calidad y la aprobaciÃ³n formal de la DirecciÃ³n TÃ©cnica de ChatGPT antes de iniciar la siguiente.

- **En Alcance MVP:** Sitios WordPress estÃ¡ndar, blogs, sitios corporativos y catÃ¡logos de Custom Post Types, taxonomÃ­as, medios compartidos, menÃºs, strings, Gutenberg y migraciÃ³n desde WPML/Polylang.
- **Fuera de Alcance Estricto:** WooCommerce (no forma parte del Core ni del MVP inicial).

---

## 2. ETAPA I: CORE / MVP

### Fase 1.0: InicializaciÃ³n y Micro-Baseline *(En ejecuciÃ³n)*
- Repositorio Git local y remoto sincronizados en branch `main`.
- DocumentaciÃ³n oficial consolidada (`docs/`).
- Tooling de desarrollo y calidad: Composer, PHPCS (WPCS), PHPUnit.
- Bootstrap mÃ­nimo ejecutable y seguro (`tf-multilingual.php`).
- Prueba de humo: activaciÃ³n, frontend, admin y desactivaciÃ³n sin errores.

### Fase 2.0: Persistencia y GestiÃ³n del Esquema de Base de Datos
- Instalador y actualizador del esquema SQL (`tfml_schema_version`).
- CreaciÃ³n de las 5 tablas maestras: `tfml_groups`, `tfml_group_elements`, `tfml_media_translations`, `tfml_strings`, `tfml_string_translations`.
- InicializaciÃ³n segura y desinstalaciÃ³n controlada (`uninstall.php`).

### Fase 3.0: Dominio de Idiomas y ConfiguraciÃ³n
- Servicio `LanguageRepository` sobre `wp_options` (`tfml_settings`).
- Registro de idiomas predeterminados y secundarios (cÃ³digos ISO, locales, banderas).
- InyecciÃ³n en `alloptions` y soporte de cache en memoria.

### Fase 4.0: Grupos de TraducciÃ³n y Relaciones de Contenido
- Repositorio de grupos `TranslationGroupRepository`.
- AsignaciÃ³n atÃ³mica de posts y pÃ¡ginas a grupos de traducciÃ³n con restricciÃ³n de unicidad.
- ResoluciÃ³n de estado `untranslated` (derivado) y membresÃ­as.

### Fase 5.0: Enrutamiento y ResoluciÃ³n de URLs
- DetecciÃ³n determinista de idioma mediante `RequestLanguageDetector`.
- ConmutaciÃ³n limpia de locale en `pre_determine_locale` para frontend.
- Prefijado de reglas en `rewrite_rules_array` para idiomas secundarios.
- IntercepciÃ³n quirÃºrgica de `redirect_canonical`.

### Fase 6.0: Motor de Versionado LÃ³gico y TranslatableFingerprint
- ImplementaciÃ³n del hash canÃ³nico normalizado sobre campos traducibles.
- Control de versiones atÃ³micas en `wp_after_insert_post`.
- DeterminaciÃ³n de estados: `up_to_date` vs `needs_review`.

### Fase 7.0: TaxonomÃ­as MultilingÃ¼es
- Mapeo de tÃ©rminos por `term_id + taxonomy`.
- Tipado estricto en `tfml_groups.subtype`.
- Filtrado en frontend y sincronizaciÃ³n de tÃ©rminos en objetos traducidos.

### Fase 8.0: Medios y MediaTranslationResolver
- ImplementaciÃ³n de `MediaModel B Refinado`: attachment post Ãºnico reutilizado.
- Almacenamiento de metadatos lingÃ¼Ã­sticos en `tfml_media_translations`.
- InyecciÃ³n de ALT, tÃ­tulo, leyenda y descripciÃ³n en frontend con fallback nativo.

### Fase 9.0: Custom Fields y SincronizaciÃ³n
- Gestor de polÃ­ticas por metakey (**TRADUCIR**, **COMPARTIR**, **IGNORAR**).
- SincronizaciÃ³n de campos escalares compartidos con semÃ¡foro antirrecusiÃ³n.

### Fase 10.0: MenÃºs y NavegaciÃ³n
- Soporte para MenÃºs ClÃ¡sicos (`nav_menu_item`) y reasociaciÃ³n de enlaces internos.
- Soporte para Navigation Block de Gutenberg (`wp_navigation`).

### Fase 11.0: Cadenas de Texto de Interfaz (Strings)
- Registro explÃ­cito mediante clave semÃ¡ntica inmutable (`domain.key`).
- Almacenamiento en `tfml_strings` y `tfml_string_translations`.
- DetecciÃ³n de colisiones de registro y control de huÃ©rfanas mediante `last_seen_at`.

### Fase 12.0: Interfaz Editorial (Gutenberg y Admin)
- IntegraciÃ³n en Gutenberg mediante `PluginDocumentSettingPanel`.
- Columnas personalizadas en listados de posts y tÃ©rminos.
- Endpoints REST de soporte editorial bajo `/wp-json/tf-multilingual/v1/`.

### Fase 13.0: Motor de Migración Desacoplado y Compatibilidad de Frontend (Fases 3.1 a 3.33 Certificadas)
- Implementación del motor transaccional reanudable, con control de concurrencia e idempotencia (`MigrationManager`, `WpmlMigrationPlanner`, `WpmlMigrationExecutor`, `WpmlMigrationRollbackService`).
- Adaptador de migración de solo lectura para WPML validado contra el laboratorio real `cms_ect_lab` (22 tablas WPML, 3.403 registros, 4.021 posts, 82 terms).
- Preflight dinámico, aislamiento físico, respaldo seguro fuera de DocumentRoot, certificación de restauración verificable y plan piloto determinista dry-run completados al 100% (Fase 3.10).
- Gobernanza de estados incompletos (`NEEDS_CONFIGURATION`), mecanismo formal de derivación de planes parciales (`derive_partial_plan`), preparación y validación dry-run del lote piloto `pilot-batch-1` (Fase 3.11).
- Ejecución piloto transaccional autorizada en `cms_ect_lab` (`pilot-batch-1`: 5 grupos, 9 elementos), certificación de idempotencia en base de datos real, reversibilidad total no destructiva (rollback completo) y capacidad de recuperación demostrada (Fase 3.12).
- Planificación incremental exhaustiva en 9 lotes semánticos (115 grupos restantes), catálogo formal de lotes versionado, 9 escenarios de estrés sintético y recuperación transaccional, y verificación de resolución en capas de dominio (Fase 3.13).
- Ejecución transaccional del segundo lote piloto (`batch-02-taxonomy-categories`: 3 grupos, 3 elementos), consolidación del estado acumulado en laboratorio (8 grupos, 12 elementos), verificación de idempotencia sin duplicación, reversibilidad aislada comprobada (`rollback_groups`) con preservación intacta del lote inicial, y certificación HTTP real en servidor web (`http://lab.ecoterra.test`) con 0 errores 500, resolución canónica 301 a 200 OK y cabeceras `X-Robots-Tag: noindex` (Fase 3.14).
- Saneamiento integral del catálogo de migración incremental en 9 lotes disjuntos (115 grupos / 124 elementos con cobertura del 100% del universo importable), conciliación anatómica certificada del Lote 3 WooCommerce (`product_type`, `product_visibility`, `product_cat`), verificación de existencia física de entidades en WordPress Core, y auditoría funcional de enrutamiento frontend `/en/` y `/fr/` (Fase 3.15).
- Ejecución transaccional del tercer lote piloto (`batch-03-taxonomy-woocommerce`: 16 grupos, 16 elementos), consolidación del estado acumulado en laboratorio (24 grupos, 28 elementos), verificación de idempotencia estricta (16 saltados, 0 duplicados), reversibilidad selectiva aislada (`rollback_groups` revertido a 8/12 sin tocar lotes previos y re-ejecutado a 24/28 con 100% de reconciliación) y validación de API de dominio y comprobación HTTP (Fase 3.16).
- Migración controlada del cuarto lote (`batch-04-taxonomy-tags-1`: 23 grupos, 23 elementos), consolidación acumulada en laboratorio (47 grupos, 51 elementos), trazabilidad dinámica de IDs autoincrementales y titularidad por manifiesto, verificación de idempotencia estricta (23 saltados, 0 duplicados), reversibilidad aislada comprobada (`rollback_groups` a 24/28 con preservación de 24 grupos previos y re-ejecución a 47/51 con 100% de conciliación), y validación de API de dominio y HTTP (Fase 3.17).
- Ratificación y migración controlada del quinto lote (`batch-05-taxonomy-tags-2`: 22 grupos, 22 elementos), levantamiento de STOP preventivo por divergencia textual, institucionalización de la regla soberana de catálogo inmutable, consolidación acumulada en laboratorio (69 grupos, 73 elementos, 57,5% de cobertura importable), verificación de idempotencia estricta (22 saltados, 0 duplicados), reversibilidad selectiva comprobada (`rollback_groups` a 47/51 con preservación íntegra de lotes 1 al 4 y re-ejecución a 69/73 con 100% de conciliación), y validaciones de API de dominio y HTTP (Fase 3.18).
- Migración controlada del sexto lote (`batch-06-elementor-templates`: 10 grupos, 11 elementos desglosados en 8 grupos de plantillas `elementor_library` [9 elementos] y 2 grupos de términos `elementor_library_type` [2 elementos]), verificación de invarianza criptográfica de metadatos y JSON Elementor (`_elementor_data`), resolución simétrica bilingüe cruzada ES <-> EN en plantillas de biblioteca, consolidación acumulada en laboratorio (79 grupos, 84 elementos, 65,8% de cobertura importable), verificación de idempotencia estricta (10 saltados, 0 duplicados), y reversibilidad aislada comprobada (`rollback_groups` a 69/73 y re-ejecución a 79/84 con 100% de reconciliación) (Fase 3.19).
- Migración controlada del séptimo lote (`batch-07-cpt-tours`: 12 grupos, 20 elementos de CPT `tours` desglosados en 8 grupos bilingües [16 elementos] y 4 monolingües [4 elementos]), verificación de invarianza criptográfica de metadatos de tours (Delta Cero absoluto en `posts`, `postmeta` y `term_relationships`), resolución simétrica bilingüe cruzada ES <-> EN en pares de tours, consolidación acumulada en laboratorio (91 grupos, 104 elementos, 75,8% de grupos y 78,2% de elementos importables del universo WPML), verificación de idempotencia estricta (12 saltados, 0 duplicados), y reversibilidad aislada comprobada (`rollback_groups` a 79/84 y re-ejecución a 91/104 con 100% de reconciliación) (Fase 3.20).
- Migración controlada del octavo lote (`batch-08-cpt-products`: 7 grupos, 7 elementos de CPT `product` correspondientes a alojamiento comercial y habitaciones), verificación de invarianza criptográfica de metadatos comerciales y de reservas (Delta Cero absoluto en `posts`, `postmeta` y `term_relationships`), resolución monolingüe limpia en API de dominio, consolidación acumulada en laboratorio (98 grupos, 111 elementos, 81,7% de grupos y 83,5% de elementos importables del universo WPML), verificación de idempotencia estricta (7 saltados, 0 duplicados), y reversibilidad aislada comprobada (`rollback_groups` a 91/104 y re-ejecución a 98/111 con 100% de reconciliación) (Fase 3.21).
- Migración controlada del noveno lote (`batch-09-core-posts-forms`: 4 grupos, 4 elementos desglosados en 3 de tipo `post` y 1 de tipo `wpcf7_contact_form`), verificación de invarianza criptográfica de entradas y formularios Contact Form 7 (Delta Cero absoluto en `posts`, `postmeta` y `term_relationships`), resolución monolingüe limpia en API de dominio, consolidación acumulada en laboratorio (102 grupos, 115 elementos, 85,0% de grupos y 86,5% de elementos importables del universo WPML), verificación de idempotencia estricta (4 saltados, 0 duplicados), y reversibilidad aislada comprobada (`rollback_groups` a 98/111 y re-ejecución a 102/115 con 100% de reconciliación) (Fase 3.22).
- Migración controlada del décimo lote (`batch-10-nav-menu-items`: 18 grupos, 18 elementos de tipo `post:nav_menu_item`), verificación de invarianza criptográfica de estructuras de menú y metadatos de navegación (Delta Cero absoluto en `posts`, `postmeta` y `term_relationships`), resolución lingüística limpia en API de dominio, consolidación acumulada en laboratorio de 120 grupos y 133 elementos (100,0% del universo importable saneado), verificación de idempotencia estricta (18 saltados, 0 duplicados), reversibilidad aislada comprobada (`rollback_groups` a 102/115 y re-ejecución a 120/133 con 100% de reconciliación), y preservación íntegra de entidades no importables (52 bloqueadas por idioma, 24 omitidas, 53 huérfanas) (Fase 3.23).
- Auditoría integral post-migración, congelamiento estricto del baseline de 120 grupos y 133 elementos (100% del universo importable), diagnóstico causa-raíz de la ausencia del tema `ecoCMS`, diagnóstico específico del TRID 197 (página de inicio y causa de 404 en `/en/`), auditoría estructural de los 129 grupos no importables (52 bloqueados por idioma, 24 omitidos, 53 huérfanas), descarte de WPBakery y confirmación de Elementor, y matriz de riesgos P0/P1/P2 para la Dirección Técnica (Fase 3.24).
- Restauración controlada del entorno visual en laboratorio mediante copia física aislada de `ecoCMS` (175 archivos) y `ova-framework` (6 archivos), verificación de paridad SHA-256 al 100%, arranque y reconocimiento del tema por WordPress, superación del bloqueo de renderizado HTML (52KB generados en `/`), ejecución de matriz de 11 rutas HTTP/HTML obligatorias, auditoría de menús y navegación, y preservación inmutable de centinelas de producción (Fase 3.25).
- Diagnóstico y diseño seguro de la portada bilingüe y compatibilidad del tema: identificación de la causa raíz de `<html lang="en-US">` en páginas en español (`WPLANG` vacío y omisión de hook `locale`), diseño de solución limpia desacoplada, diagnóstico de la condición dual para resolver `/en/` (importación de par activo `es` <-> `en` del TRID 197 e intercepción en `page_on_front`), evaluación de alternativas con selección formal de la Alternativa B (importación parcial explícita), auditoría estática exhaustiva de 109 referencias multilingües en `ecoCMS` (5 `ICL_LANGUAGE_CODE`, 96 `traducir()`, 4 `icl_object_id` y 4 `icl_get_languages` con sus guardas), caracterización de dependencia de Elementor en CPT `ova_framework_hf_el` para cabeceras y preservación inmutable de centinelas de producción (Fase 3.26).
- Corrección del idioma HTML y localización pública nativa de WordPress: diseño e integración del componente canónico `LocalizadorPublicoNativo`, intercepción nativa de los hooks `locale` y `determine_locale` (prioridad 10), resolución regional `es_ES` / `en_US` sin mutación persistente de `WPLANG` en BD, discriminación limpia de contextos públicos frente a WP-Admin, cron y AJAX, blindaje antirrecursión, acreditación empírica en servidor HTTP real (`http://lab.ecoterra.test`) con emisión de `<html lang="es-ES" >` en todas las páginas públicas en español, e invarianza absoluta de los centinelas de base de datos (Fase 3.27).
- Contratos y adaptadores limitados de compatibilidad con WPML: separación nítida en tres superficies arquitectónicas (Superficie A: API pública nativa en `ServicioTraduccionPublico`, Superficie B: adaptador opcional en `AdaptadorCompatibilidadWpml` desactivado por omisión, Superficie C: `traducir()` intacta en `ecoCMS`), cumplimiento estricto de la restricción categórica de NO definir `ICL_LANGUAGE_CODE` globalmente, auditoría formal de contratos en 175 archivos de `ecoCMS` (94 `traducir()`, 4 `icl_object_id()`, 4 `icl_get_languages()`, 3 `ICL_LANGUAGE_CODE`), soporte de contratos funcionales de traducción y listado lingüístico sin inclusión de francés inactivo, y certificación integral empírica (Fase 3.28).
- Blindaje de activación y ausencia de efectos secundarios del adaptador de compatibilidad WPML: aislamiento absoluto de la tabla de funciones de PHP mediante decisión determinista de carga en arranque (`function_exists('icl_object_id')` e `function_exists('icl_get_languages')` son estrictamente `false` con el adaptador desactivado), salvaguardas de detección y auto-inhibición limpia ante la presencia de WPML real (`SitePress`, `ICL_SITEPRESS_VERSION`, `WPML_PLUGIN_PATH`), prevención de colisiones con símbolos ajenos mediante centinela `TFML_WPML_FUNCTIONS_LOADED`, configuración jerárquica de activación (constante `TFML_ENABLE_WPML_COMPATIBILITY`, opción persistida y filtro), verificación formal en subprocesos PHP CLI independientes para los 14 escenarios obligatorios con 79/79 controles PASS e invarianza absoluta de centinelas de producción (Fase 3.29).
- Soporte transaccional para grupos WPML con idiomas inactivos retenidos y registro de traducciones retenidas: preservación inmutable de la clasificación `BLOCKED_BY_LANGUAGE` en el plan maestro para 52 grupos con idiomas inactivos (incluido el TRID 197), soporte formal de derivación de planes parciales con autorización técnica explícita y asignación de clasificación `PARTIAL_RETAINED`, creación del componente persistente y auditable `RetainedTranslationRegistry` en Options API (`tfml_retained_translations`) con validación de unicidad, prevención de colisiones relacionales, huella SHA-256 por entrada, promoción controlada futura y purga atómica en rollback (`remove_by_group_id`), extensión de `WpmlMigrationExecutor` y `WpmlMigrationRollbackService`, ejecución completa de batería de 15 escenarios automatizados de DT-TFML-060 con 53/53 controles PASS, no-regresión en servidor HTTP real, e invarianza absoluta con delta cero en `cms_ect` (3.403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`) y congelamiento del baseline en `cms_ect_lab` (120 grupos, 133 elementos, TRID 197 sin importar) (Fase 3.30).
- Blindaje transaccional y certificación de recuperación de traducciones retenidas: unificación de la conexión `$wpdb` compartida y persistencia SQL directa sobre `{$db->prefix}options` (motor InnoDB) para `RetainedTranslationRegistry`, `MigrationCheckpointRepository`, `MigrationExecutionLock` y `WpmlMigrationRollbackService`, eliminación de lecturas sucias en `WP_Object_Cache` tras `ROLLBACK` SQL, verificación post-escritura del estado persistido mediante huella SHA-256 (resolviendo la ambigüedad de `update_option() === false`), inclusión de checkpoint y manifiesto dentro de la transacción de `execute_batch()`, envoltura transaccional de `promote_retained_translation()`, detección de registros retenidos faltantes, corruptos y huérfanos en `reconcile()`, clarificación formal del alcance DDL (`tfml_schema_version = '1.0.0'` preservado), certificación de los 15 escenarios obligatorios de DT-TFML-061 en PHPUnit y sobre una base de datos MySQL InnoDB efímera y aislada (`cms_ect_lab_tx_test`) con 40/40 controles PASS, e invarianza absoluta en `cms_ect` y `cms_ect_lab` (Fase 3.31).
- Importación piloto controlada de la portada bilingüe (`TRID 197`) en laboratorio: validación de restauración en base desechable del respaldo específico pre-piloto de `cms_ect_lab`, derivación autorizada del plan parcial `pilot-trid-197-partial`, creación atómica del grupo TFML `587` (`197 -> es` canónico, `8356 -> en` activo) y retención del miembro francés (`8839 -> fr`) en `RetainedTranslationRegistry`, consolidación de **121 grupos y 135 elementos activos** con `0` discrepancias de reconciliación e idempotencia certificada, resolución bidireccional `197 <-> 8356` en `ContentTranslationResolver` y `ServicioTraduccionPublico`, emisión automática de `hreflang` bilingüe en `/`, y separación arquitectónica del enrutamiento de `/en/` (`BLOCKED BY ROUTING`) para su directiva específica (Fase 3.32).
- Resolución nativa de portada multilingüe, enrutamiento y SEO canónico: implementación del servicio `ResolvedorPortadaMultilingue` (`option_page_on_front`, `parse_query`, `pre_get_posts`, `page_link`, `redirect_canonical`) sin mutar `wp_options` ni cablear IDs/slugs, filtrado dinámico idempotente de `option_rewrite_rules` en `RewriteManager`, política estricta de no-fallback ante portadas secundarias ausentes o no publicadas (`HTTP 404` limpio), normalización canónica de enlaces de portada (`/` y `/en/`) en `LocalizedUrlGenerator`, `CanonicalUrlManager`, `HreflangGenerator`, `CoreSitemapsFilter` y `NavMenuFrontendFilter`, redirección `301` de `/en/home/` hacia `http://lab.ecoterra.test/en/` y de `/inicio/` hacia `http://lab.ecoterra.test/`, y certificación HTTP en vivo con **23/23 controles PASS** y **603 pruebas PHPUnit** (Fase 3.33).
- Adaptador de migración para Polylang (inventario analizado).

### Fase 14.0: Hardening, AuditorÃ­a y Cierre MVP
- Pruebas de integraciÃ³n, concurrencia y lÃ­mites de memoria.
- ValidaciÃ³n de herramientas de diagnÃ³stico y purga protegida de datos.
- CertificaciÃ³n final de la versiÃ³n MVP (1.0.0).

---

## 3. ETAPA II: POST-CORE / EXTENSIONES FUTURAS

- **Medios EspecÃ­ficos por Idioma:** Capacidad explÃ­cita para sustituir un attachment por otro archivo fÃ­sico especÃ­fico para un idioma determinado.
- **TraducciÃ³n de Bases de Rewrite:** Soporte configurable para traducir bases estructurales de CPTs y taxonomÃ­as.
- **Adaptadores SEO Dedicados:** IntegraciÃ³n formal y pruebas con Yoast SEO y Rank Math.
- **Adaptadores de Page Builders:** Filtros y decodificadores de estructuras complejas para Elementor, WPBakery, Divi, etc.
- **Integraciones de IA Desacopladas:** Conectores satÃ©lites opcionales para traducciÃ³n asistida (DeepL, OpenAI, Google Cloud Translation).
