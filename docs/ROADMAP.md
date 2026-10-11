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

### Fase 13.0: Motor de Migración Desacoplado (Fases 3.1 a 3.22 Certificadas)
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
