# ARQUITECTURA TÃ‰CNICA OFICIAL â€” TF MULTILINGUAL
**Documento:** ARQUITECTURA-TF-MULTILINGUAL.md
**VersiÃ³n:** 1.0.0 (Oficial Aprobada)
**Estado:** **APROBADA**
**Fecha:** Octubre 2026
**JerarquÃ­a:** ORLANDO (DirecciÃ³n Ejecutiva) â†’ CHATGPT (DirecciÃ³n TÃ©cnica / Arquitectura) â†’ GEMINI (IngenierÃ­a Senior Implementadora)
**Compatibilidad Runtime:** WordPress 6.8+ | PHP 8.1 - 8.3 (Desarrollo principal: PHP 8.3)

---

## 1. OBJETIVO DEL PRODUCTO
TF Multilingual (`tf-multilingual`) es un motor multilingÃ¼e independiente, profesional y de alto rendimiento para WordPress. Proporciona internacionalizaciÃ³n editorial sin alterar el Core de WordPress, sin depender de sistemas de terceros y bajo la filosofÃ­a central de producto:
**WordPress sigue siendo WordPress; TF Multilingual solo le enseÃ±a a hablar mÃ¡s de un idioma.**
La complejidad pertenece al motor; la simplicidad pertenece al usuario: **INSTALAR â†’ ELEGIR IDIOMAS â†’ TRADUCIR**.

---

## 2. PRINCIPIOS ARQUITECTÃ“NICOS FUNDAMENTALES
1. **Nativo en WordPress:** Cada traducciÃ³n editorial es un registro real en `wp_posts` o `wp_terms`. No se encapsulan mÃºltiples idiomas dentro de un mismo `post_content`.
2. **Aislamiento Estructural:** Las relaciones y grupos se almacenan en tablas dedicadas (`tfml_*`), sin degradar `wp_postmeta`.
3. **No Destructivo:** Desactivar o desinstalar el plugin nunca elimina, altera ni oculta posts, tÃ©rminos ni archivos fÃ­sicos de WordPress.
4. **Desacoplamiento Absoluto:** El Core desconoce implementaciones externas. Los adaptadores de migraciÃ³n (WPML, Polylang) y SEO son mÃ³dulos satÃ©lites independientes.
5. **Integridad de Dominio:** Ausencia de miembro en un grupo equivale a **SIN TRADUCIR**. Queda estrictamente prohibida la creaciÃ³n de filas ficticias para objetos inexistentes.
6. **Seguridad y Estabilidad de URLs:** El idioma principal opera sin prefijo en MVP (`/tours/camino-inca/`); los idiomas secundarios operan con prefijo ISO (`/en/tours/inca-trail/`). Las bases estructurales de CPT y taxonomÃ­as no se traducen en el MVP.

---

## 3. MODELO DE DATOS Y PERSISTENCIA (5 TABLAS MAESTRAS)

### 3.1 Idiomas y ConfiguraciÃ³n (`wp_options`)
- Option name: `tfml_settings` (Autoloaded).
- Almacena el idioma predeterminado, el array de idiomas activos (cÃ³digo ISO, locale oficial de WordPress, nombre nativo, bandera, direcciÃ³n LTR/RTL y orden de visualizaciÃ³n).
- Aprovecha el mecanismo nativo de carga y cache de `alloptions` de WordPress Core, evitando consultas SQL adicionales especÃ­ficas de configuraciÃ³n por request.

### 3.2 Esquema Relacional SQL Propio
El modelo relacional no utiliza restricciones `FOREIGN KEY` a nivel motor SQL para asegurar portabilidad en hostings compartidos, consistencia con herramientas de WordPress y control explÃ­cito del ciclo de vida en la capa de servicios PHP. La integridad referencial se garantiza mediante transacciones y restricciones `UNIQUE KEY` estrictas.

#### Tabla 1: Cabecera del Grupo de TraducciÃ³n (`{$wpdb->prefix}tfml_groups`)
Define la existencia atÃ³mica del grupo, su tipado y el elemento canÃ³nico de referencia.
```sql
CREATE TABLE {$wpdb->prefix}tfml_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    element_type VARCHAR(20) NOT NULL, -- 'post', 'term'
    subtype VARCHAR(32) NOT NULL,      -- post_type ('post','page','tours') o taxonomy ('category','post_tag')
    canonical_element_id BIGINT UNSIGNED NULL, -- Elemento canÃ³nico de referencia soberana
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_type_subtype (element_type, subtype)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

#### Tabla 2: Miembros del Grupo (`{$wpdb->prefix}tfml_group_elements`)
Mapea los objetos reales de WordPress con su grupo, idioma y control de versiÃ³n.
```sql
CREATE TABLE {$wpdb->prefix}tfml_group_elements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NOT NULL,
    element_type VARCHAR(20) NOT NULL, -- 'post', 'term'
    element_id BIGINT UNSIGNED NOT NULL, -- post_id (para posts) o term_id (para terms)
    language_code VARCHAR(10) NOT NULL,
    source_version_at_translation INT UNSIGNED NOT NULL DEFAULT 1, -- VersiÃ³n del canÃ³nico al sincronizar
    current_content_version INT UNSIGNED NOT NULL DEFAULT 1,       -- VersiÃ³n propia del contenido
    translatable_fingerprint VARCHAR(128) NOT NULL DEFAULT '',     -- Hash canÃ³nico normalizado
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_element (element_type, element_id),
    UNIQUE KEY uq_group_language (group_id, language_code),
    KEY idx_lookup (element_type, language_code, element_id),
    KEY idx_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

#### Tabla 3: Traducciones LingÃ¼Ã­sticas de Medios (`{$wpdb->prefix}tfml_media_translations`)
Implementa el **Media Model B Refinado**. Un Ãºnico archivo fÃ­sico en disco corresponde a un Ãºnico attachment post nativo de WordPress. Las traducciones de posts reutilizan el mismo attachment ID. Los textos editoriales traducibles residen en esta tabla.
```sql
CREATE TABLE {$wpdb->prefix}tfml_media_translations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attachment_id BIGINT UNSIGNED NOT NULL, -- ID del attachment post Ãºnico en wp_posts
    language_code VARCHAR(10) NOT NULL,
    alt_text TEXT NULL,
    title TEXT NULL,
    caption LONGTEXT NULL,
    description LONGTEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attachment_language (attachment_id, language_code),
    KEY idx_language (language_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

#### Tabla 4: Registro de Strings de Interfaz (`{$wpdb->prefix}tfml_strings`)
Almacena cadenas de interfaz identificadas mediante claves semÃ¡nticas estables.
```sql
CREATE TABLE {$wpdb->prefix}tfml_strings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain VARCHAR(100) NOT NULL DEFAULT 'default',
    string_key VARCHAR(191) NOT NULL, -- Clave semÃ¡ntica inmutable (ej. domain.context.key)
    context VARCHAR(100) NOT NULL DEFAULT '',
    original_value LONGTEXT NOT NULL,
    source_language VARCHAR(10) NOT NULL DEFAULT 'es',
    string_version INT UNSIGNED NOT NULL DEFAULT 1,
    has_conflict TINYINT(1) NOT NULL DEFAULT 0, -- 1 si se detectÃ³ conflicto de registro
    last_seen_at DATETIME NOT NULL,            -- Control y detecciÃ³n de huÃ©rfanas
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_domain_key (domain, string_key),
    KEY idx_context (context),
    KEY idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

#### Tabla 5: Traducciones de Strings (`{$wpdb->prefix}tfml_string_translations`)
```sql
CREATE TABLE {$wpdb->prefix}tfml_string_translations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    string_id BIGINT UNSIGNED NOT NULL,
    language_code VARCHAR(10) NOT NULL,
    translated_value LONGTEXT NULL,
    source_version_translated INT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'up_to_date', -- 'up_to_date', 'needs_review'
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_string_lang (string_id, language_code),
    KEY idx_lang (language_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

---

## 4. MODELO DE VERSIONADO LÃ“GICO Y ESTADOS

### 4.1 Fuente CanÃ³nica Ãšnica
Cada grupo posee un Ãºnico `canonical_element_id`. Toda traducciÃ³n no canÃ³nica se compara exclusivamente contra la versiÃ³n actual del elemento canÃ³nico del grupo.
- **`untranslated` (Sin Traducir):** Estado derivado por la ausencia de registro en `tfml_group_elements` para ese idioma dentro del grupo.
- **`up_to_date` (Actualizada):** `source_version_at_translation == canonical.current_content_version` (o el objeto es el canÃ³nico).
- **`needs_review` (Revisar):** `source_version_at_translation < canonical.current_content_version`.

### 4.2 TranslatableFingerprint
Para garantizar que una ediciÃ³n en Gutenberg o Classic Editor no incremente la versiÃ³n editorial arbitrariamente por hooks repetidos:
1. Se recopilan los campos traducibles editoriales: tÃ­tulo, contenido, extracto, metadatos clasificados explÃ­citamente como `TRADUCIR`, y tÃ©rminos de taxonomÃ­as traducibles.
2. Se realiza una **normalizaciÃ³n canÃ³nica previa**: `ksort` recursivo de arrays asociativos, ordenamiento determinista de listas de IDs de tÃ©rminos, y exclusiÃ³n de metadatos tÃ©cnicos (`_edit_lock`, etc.) y campos `IGNORE`/`SHARE`.
3. Se genera un hash determinista en `translatable_fingerprint VARCHAR(128)`.
4. Si y solo si el fingerprint difiere del almacenado, se actualiza el fingerprint y se incrementa `current_content_version++` en `wp_after_insert_post`.

---

## 5. ENRUTAMIENTO, REQUEST Y LOCALE

### 5.1 Enrutamiento apoyado en Rewrite API
- El idioma principal opera en la raÃ­z sin prefijo (`/tours/camino-inca/`).
- Los idiomas secundarios operan con prefijo ISO (`/en/tours/inca-trail/`).
- Las reglas de reescritura prefijadas se inyectan dinÃ¡micamente mediante el filtro Core `rewrite_rules_array`.
- **Invariante de Rendimiento:** `flush_rewrite_rules()` se ejecuta Ãºnicamente en eventos de ciclo de vida (activaciÃ³n, desactivaciÃ³n, cambio de configuraciÃ³n de idiomas en Admin). Nunca en peticiones ordinarias de frontend.

### 5.2 Redirect Canonical QuirÃºrgico
TFML intercepta `redirect_canonical` Ãºnicamente para prevenir que WordPress Core intente eliminar el prefijo de idioma secundario vÃ¡lido o redirigir hacia el slug del idioma predeterminado. Cualquier redirecciÃ³n canÃ³nica legÃ­tima de WordPress (ej. aÃ±adir barra final `/`) continÃºa operando normalmente.

### 5.3 SeparaciÃ³n de Contextos LingÃ¼Ã­sticos
- **Frontend:** La URL detectada por `RequestLanguageDetector` establece el Content Language y conmuta el locale del entorno de frontend vÃ­a `pre_determine_locale`.
- **WP-Admin:** Rige el locale del perfil de usuario de WordPress. TFML no altera el idioma de la administraciÃ³n salvo en la superficie editorial especÃ­fica del post o tÃ©rmino que se estÃ¡ traduciendo.
- **REST:** El parÃ¡metro explÃ­cito `?lang=` determina el idioma del contenido.
- **Cron / CLI:** Sin inferencia automÃ¡tica desde URLs.

---

## 6. GESTIÃ“N DE CONTENIDOS Y SUBSISTEMAS

### 6.1 TaxonomÃ­as
- **Identidad Soberana:** `element_type = 'term'`, `element_id = term_id`, y taxonomÃ­a definida en `tfml_groups.subtype`.
- Coherencia con las APIs editoriales nativas de WordPress (`get_term( $term_id, $taxonomy )`, `wp_set_object_terms()`).
- Un grupo de traducciÃ³n de tÃ©rminos estÃ¡ tipado por su `subtype`, impidiendo que tÃ©rminos de taxonomÃ­as incompatibles compartan grupo.

### 6.2 Media (Media Model B Refinado y MediaTranslationResolver)
- Un archivo fÃ­sico = un attachment post en WordPress.
- ReutilizaciÃ³n de attachments entre traducciones editoriales (ej. misma imagen destacada).
- La resoluciÃ³n de metadatos traducidos (ALT, tÃ­tulo, leyenda, descripciÃ³n) se gestiona mediante la clase de servicio `MediaTranslationResolver`.
- La integraciÃ³n se realiza a travÃ©s de los puntos pÃºblicos y especÃ­ficos de WordPress segÃºn cada atributo en frontend, preservando la integridad de la biblioteca de medios y del panel administrativo.
- `wp_delete_attachment()` opera con su ciclo de vida nativo sin intervenciÃ³n invasiva sobre el borrado fÃ­sico de archivos.

### 6.3 Custom Fields
- **PolÃ­ticas Registradas:** **TRADUCIR** (independiente), **COMPARTIR** (sincronizado entre miembros del grupo), **IGNORAR** (sin intervenciÃ³n).
- **PolÃ­tica Predeterminada:** **IGNORAR** para metakeys desconocidas (mÃ¡xima seguridad contra corrupciÃ³n de datos).
- **Valores Complejos:** Campos escalares se sincronizan directamente. Estructuras complejas (JSON, arrays serializados de page builders) requieren un adaptador registrado; en su defecto, no se alteran.
- **AntirrecusiÃ³n:** SemÃ¡foro en memoria durante la propagaciÃ³n de campos en hooks atÃ³micos de metadata (`added_post_meta`, `updated_post_meta`).

### 6.4 MenÃºs y NavegaciÃ³n
- **MenÃºs ClÃ¡sicos:** Cada menÃº es un tÃ©rmino `nav_menu` con su grupo. Los `nav_menu_item` de tipo post o taxonomÃ­a reasocian sus punteros al elemento traducido. Custom links internos se transforman a la URL equivalente; externos permanecen intactos. En frontend, `theme_mod_nav_menu_locations` conmuta el ID al idioma activo.
- **Navigation Block (`wp_navigation`):** Cada idioma dispone de su propio post `wp_navigation`. `render_block_data` conmuta el atributo `ref` del bloque `core/navigation` al menÃº traducido.

### 6.5 REST API
- Registro formal del parÃ¡metro `?lang=` en `rest_{$post_type}_collection_params` para todos los post types traducibles.
- Peticiones singulares (`/wp/v2/posts/123`): Devuelven deterministamente el objeto 123 exponiendo `tfml_language`, `tfml_group_id` y `tfml_translations`.

### 6.6 SEO y Sitemaps
- **Hreflang:** GeneraciÃ³n estricta en `wp_head` exclusivamente para hermanos del grupo en estado `publish`, incluyendo etiqueta `x-default`.
- **Sitemaps:** Cada traducciÃ³n publicada es un objeto WordPress real elegible para los providers nativos de Core Sitemaps. TFML asegura mediante los filtros pÃºblicos de Core que las URLs generadas incluyan el prefijo de idioma correspondiente, sin crear providers propietarios redundantes.

---

## 7. SEGURIDAD, CAPABILITIES Y CICLO DE VIDA

### 7.1 Matriz de Capabilities EspecÃ­ficas
Asignadas al rol `administrator` en Single-Site:
- `tfml_manage_settings`: AdministraciÃ³n de configuraciÃ³n, idiomas y URLs.
- `tfml_translate_content`: CreaciÃ³n y ediciÃ³n de traducciones en posts y taxonomÃ­as.
- `tfml_manage_strings`: GestiÃ³n y traducciÃ³n de cadenas de interfaz.
- `tfml_run_migrations`: Escaneo, validaciÃ³n y ejecuciÃ³n de migraciones externas.
- `tfml_run_cleanup`: Purga destructiva controlada de datos de TFML.

### 7.2 Ciclo de Vida del Software
- **ActivaciÃ³n:** VerificaciÃ³n de requisitos (PHP 8.1+, WP 6.8+), ejecuciÃ³n idempotente de migraciones de esquema SQL (`tfml_schema_version`), asignaciÃ³n de capabilities y registro de reglas de reescritura.
- **DesactivaciÃ³n:** Limpieza de transitorios y `flush_rewrite_rules()`. **Cero destrucciÃ³n de datos.**
- **DesinstalaciÃ³n (`uninstall.php`):** Preserva tablas y relaciones por defecto. Ãšnicamente si el usuario activÃ³ explÃ­citamente la opciÃ³n de borrado completo, se eliminan las 5 tablas `tfml_*` y las opciones de configuraciÃ³n.
- **Purga ExplÃ­cita:** Herramienta administrativa protegida por capability `tfml_run_cleanup`, Nonce y confirmaciÃ³n textual requerida en UI. VacÃ­a exclusivamente tablas y opciones de TFML; **jamÃ¡s elimina posts, tÃ©rminos ni archivos fÃ­sicos de WordPress**.

---

## 8. MOTOR DE MIGRACIÃ“N DESACOPLADO
- **AgnÃ³stico del Transporte:** La clase `MigrationBatchRunner` opera por lotes reanudables e independientes del canal de ejecuciÃ³n (Admin AJAX, REST API o WP-CLI).
- **No Destructivo:** Las tablas de origen (ej. WPML `icl_*`) se leen en modo estrictamente de solo lectura.
- **Mapeo de Identificadores:** No se asume igualdad de IDs entre sistemas. Se genera un manifiesto de migraciÃ³n que mapea `source_trid` hacia el nuevo `tfml_groups.id`, preservando intactos los IDs nativos de posts, tÃ©rminos y contenidos.

---

## 9. BUILD, EMPAQUETADO Y ESTÃNDARES
- **EstÃ¡ndares:** WordPress Coding Standards (WPCS) verificados mediante PHPCS con reglas de compatibilidad PHP 8.1+.
- **Runtime Composer:** El artefacto distribuible final (ZIP) incluye el runtime optimizado de Composer: `vendor/autoload.php` y `vendor/composer/*` generado con `--no-dev --optimize-autoloader`.
- El usuario final nunca necesita ejecutar `composer install`.
- No se incluyen en producciÃ³n herramientas de desarrollo (`phpunit`, `wpcs`, `phpcs`).
