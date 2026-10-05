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

### Fase 13.0: Motor de MigraciÃ³n Desacoplado
- ImplementaciÃ³n del motor batch reanudable e independiente del transporte (`MigrationBatchRunner`).
- Adaptador de migraciÃ³n de solo lectura para WPML (validado contra el laboratorio `cms.ecoterra`).
- Adaptador de migraciÃ³n para Polylang.

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
