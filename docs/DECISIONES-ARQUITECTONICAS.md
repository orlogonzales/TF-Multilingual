# REGISTRO DE DECISIONES ARQUITECTÃ“NICAS (ADR)
**Documento:** DECISIONES-ARQUITECTONICAS.md
**Proyecto:** TF Multilingual
**Estado:** **CONSOLIDADO Y APROBADO**

---

### ADR-001: Cada TraducciÃ³n es un Objeto WordPress Nativo
- **DECISIÃ“N:** Cada traducciÃ³n editorial de un post, pÃ¡gina, CPT o tÃ©rmino es un registro real independiente en `wp_posts` o `wp_terms`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Se evaluÃ³ si almacenar mÃºltiples idiomas dentro del mismo `post_content` (mediante etiquetas de bloque o serializaciones) o usar objetos nativos.
- **JUSTIFICACIÃ“N:** El principio rector es "WordPress sigue siendo WordPress". Usar objetos nativos preserva la compatibilidad con el ecosistema (temas, Gutenberg, Elementor, REST, slugs nativos) sin alterar el renderizado de Core.
- **CONSECUENCIAS:** Cada idioma tiene su propio ID, slug y metadatos nativos. Requiere sincronizar campos compartidos y mapear relaciones en tablas dedicadas.

---

### ADR-002: Grupos de TraducciÃ³n en Tablas Propias Dedicadas
- **DECISIÃ“N:** Las relaciones entre traducciones se gestionan mediante las tablas dedicadas `tfml_groups` y `tfml_group_elements`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Se evaluÃ³ el uso de `wp_postmeta`, taxonomÃ­as internas de WordPress (estilo Polylang) o tablas propias.
- **JUSTIFICACIÃ“N:** `wp_postmeta` carece de integridad relacional y degrada el rendimiento al requerir mÃºltiples JOINs para resolver hermanos. Las taxonomÃ­as internas ensucian las tablas de tÃ©rminos de Core. Las tablas dedicadas permiten Ã­ndices compuestos Ãºnicos e inserciones/consultas de alta velocidad.
- **CONSECUENCIAS:** Mayor integridad referencial, soporte de catÃ¡logos masivos y aislamiento completo de la base de datos de WordPress.

---

### ADR-003: Almacenamiento de Idiomas y ConfiguraciÃ³n en Options API
- **DECISIÃ“N:** La configuraciÃ³n del plugin y los idiomas activos se almacenan en la Option serializada `tfml_settings` con `autoload = yes`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Se considerÃ³ crear una tabla propia `tfml_languages` frente a Options API.
- **JUSTIFICACIÃ“N:** La cardinalidad de idiomas es mÃ­nima (2 a 6 idiomas promedio). Crear una tabla SQL dedicada para 3 filas que deben leerse en cada request es un antipatrÃ³n en WordPress.
- **CONSECUENCIAS:** Aprovecha el mecanismo nativo de carga y cache de `alloptions` de WordPress Core sin aÃ±adir consultas SQL de configuraciÃ³n especÃ­ficas por request.

---

### ADR-004: Estado SIN TRADUCIR como Ausencia Derivada
- **DECISIÃ“N:** El estado `untranslated` no se almacena como fila en la base de datos; se deriva en tiempo de ejecuciÃ³n por la ausencia de registro en `tfml_group_elements` para ese idioma.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** DiseÃ±os preliminares contemplaban almacenar filas con `status = untranslated`.
- **JUSTIFICACIÃ“N:** Si el objeto traducido no existe en WordPress, no debe existir una fila en la base de datos con IDs nulos o falsos.
- **CONSECUENCIAS:** Base de datos limpia, sin registros fantasma ni corrupciÃ³n de integridad referencial.

---

### ADR-005: Modelo CanÃ³nico de Versionado LÃ³gico
- **DECISIÃ“N:** Cada grupo posee un `canonical_element_id`. Todas las traducciones no canÃ³nicas se comparan exclusivamente contra `canonical.current_content_version`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Se evaluÃ³ genealogÃ­a directa entre traducciones vs modelo canÃ³nico de grupo.
- **JUSTIFICACIÃ“N:** El modelo canÃ³nico evita grafos de dependencias complejos y garantiza una semÃ¡ntica de revisiÃ³n editorial predecible y consistente.
- **CONSECUENCIAS:** Si el canÃ³nico actualiza su contenido traducible (`TranslatableFingerprint`), todas las traducciones dependientes pasan automÃ¡ticamente al estado `needs_review`.

---

### ADR-006: ExclusiÃ³n de Foreign Keys a Nivel Motor SQL
- **DECISIÃ“N:** No utilizar restricciones `FOREIGN KEY` a nivel motor en las tablas `tfml_*`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Compatibilidad con `dbDelta()`, herramientas de migraciÃ³n/WXR y entornos de hosting heterogÃ©neos.
- **JUSTIFICACIÃ“N:** Mantener coherencia con la arquitectura del Core de WordPress, asegurar portabilidad total y evitar fallos en herramientas de duplicaciÃ³n de base de datos.
- **CONSECUENCIAS:** La integridad referencial y las operaciones en cascada se garantizan estrictamente en la capa de servicios y repositorios PHP mediante transacciones.

---

### ADR-007: Identidad de TÃ©rminos basada en `term_id + taxonomy`
- **DECISIÃ“N:** La identidad de un tÃ©rmino en TFML es `element_id = term_id`, contextualizado soberanamente por `tfml_groups.subtype = taxonomy`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Se evaluÃ³ usar `term_taxonomy_id` (TTID) frente a `term_id`.
- **JUSTIFICACIÃ“N:** Las APIs pÃºblicas editoriales de WordPress (`get_term`, `wp_set_object_terms`) operan primordialmente con `term_id` y su nombre de taxonomÃ­a. El TTID permanece como detalle de implementaciÃ³n interna de Core.
- **CONSECUENCIAS:** Interfaz de dominio limpia y alineada con la programaciÃ³n estÃ¡ndar de plugins y temas de WordPress.

---

### ADR-008: Media Model B Refinado (Attachment Ãšnico Compartido + Tabla LingÃ¼Ã­stica)
- **DECISIÃ“N:** Un archivo fÃ­sico en disco corresponde a un Ãºnico attachment post de WordPress. Las traducciones de posts reutilizan el mismo attachment ID. Los textos editoriales traducibles (ALT, tÃ­tulo, leyenda, descripciÃ³n) residen en `tfml_media_translations`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Se evaluÃ³ duplicar attachment posts compartiendo archivo fÃ­sico (Model A) vs attachment Ãºnico con metadatos traducidos (Model B).
- **JUSTIFICACIÃ“N:** Model A obligaba a interceptar permanentemente `wp_delete_file` y gobernar el borrado fÃ­sico de archivos. Model B permite que `wp_delete_attachment()` conserve su ciclo de vida nativo de WordPress sin interferencias.
- **CONSECUENCIAS:** Cero duplicaciÃ³n fÃ­sica de archivos, cero duplicaciÃ³n de posts attachments en la biblioteca de medios, y ciclo de vida de medios 100% nativo.

---

### ADR-009: PolÃ­tica IGNORAR por Defecto para Custom Fields Desconocidos
- **DECISIÃ“N:** Cualquier metakey no registrada explÃ­citamente se clasifica por defecto como **IGNORAR**.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Evaluar si asumir TRADUCIR, COMPARTIR o IGNORAR ante metadatos no reconocidos.
- **JUSTIFICACIÃ“N:** Asumir COMPARTIR puede sobrescribir textos editoriales privados; asumir TRADUCIR puede arrastrar basura tÃ©cnica o locks transitorios. IGNORAR es la postura mÃ¡s conservadora y segura.
- **CONSECUENCIAS:** MÃ¡xima seguridad e integridad de datos ante plugins y temas de terceros no configurados previamente.

---

### ADR-010: Enrutamiento Apoyado en Rewrite API de WordPress
- **DECISIÃ“N:** TFML inyecta reglas prefijadas en `rewrite_rules_array` para idiomas secundarios (`^([a-z]{2})/`), permitiendo que el router nativo de WordPress resuelva query vars y templates.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Evaluar la creaciÃ³n de un router paralelo capturando rutas genÃ©ricas vs aprovechar la Rewrite API nativa.
- **JUSTIFICACIÃ“N:** Recrear el parser de URLs de WordPress obliga a duplicar lÃ³gica de pÃ¡ginas jerÃ¡rquicas, paginaciones, feeds y endpoints de CPTs.
- **CONSECUENCIAS:** Compatibilidad total con WordPress, rendimiento Ã³ptimo y eliminaciÃ³n de parsers paralelos.

---

### ADR-011: Idioma Principal sin Prefijo en MVP
- **DECISIÃ“N:** En el MVP, el idioma predeterminado se sirve en la raÃ­z sin prefijo de idioma (`/tours/camino-inca/`); los secundarios usan prefijo (`/en/tours/inca-trail/`).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Definir estructura de URLs para preservar SEO preexistente del sitio anfitriÃ³n.
- **JUSTIFICACIÃ“N:** Preserva los permalinks histÃ³ricos y el posicionamiento SEO del idioma principal sin requerir migraciones complejas de URLs para el contenido base.
- **CONSECUENCIAS:** Arquitectura simple y directa; extensibilidad futura preservada sin sobrecarga inicial.

---

### ADR-012: Bases Estructurales de CPT y TaxonomÃ­as No Traducidas en MVP
- **DECISIÃ“N:** Las bases de reescritura de CPTs y taxonomÃ­as permanecen fijas en todos los idiomas durante el MVP (ej. `/tours/...` y `/en/tours/...`).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Evaluar traducciÃ³n de bases estructurales (ej. `/en/tours-en/...` o `/en/tours-catalog/...`).
- **JUSTIFICACIÃ“N:** Traducir bases de rewrite multiplica exponencialmente la complejidad de las reglas de reescritura y colisiones de enrutamiento.
- **CONSECUENCIAS:** Menor complejidad en rewrite rules, mayor estabilidad y foco en la traducciÃ³n de contenido y slugs individuales.

---

### ADR-013: Identidad Estable para Strings de Interfaz (`domain.key`)
- **DECISIÃ“N:** Las cadenas de interfaz se identifican mediante una clave semÃ¡ntica inmutable `domain.key` en lugar de hashes de su valor original.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Se evaluÃ³ `md5(context . original)` frente a claves jerÃ¡rquicas estables.
- **JUSTIFICACIÃ“N:** Un hash del original rompe la identidad y deja huÃ©rfanas las traducciones si se corrige una errata en el texto fuente.
- **CONSECUENCIAS:** Claves inmutables, preservaciÃ³n de traducciones y control de cambios en el texto fuente mediante versiones.

---

### ADR-014: Adaptadores de MigraciÃ³n Desacoplados del Core
- **DECISIÃ“N:** El Core no contiene cÃ³digo ni dependencias de WPML o Polylang. La migraciÃ³n se implementa mediante adaptadores satÃ©lites independientes.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** CÃ³mo estructurar la transiciÃ³n desde sitios preexistentes con otros motores multilingÃ¼es.
- **JUSTIFICACIÃ“N:** Preserva la pureza arquitectÃ³nica del Core y evita deuda tÃ©cnica ligada a esquemas obsoletos de terceros.
- **CONSECUENCIAS:** El Core opera de forma autÃ³noma. Los adaptadores leen los datos externos en modo solo lectura y generan entidades nativas de TFML.

---

### ADR-015: Motor de MigraciÃ³n Batch Independiente del Transporte
- **DECISIÃ“N:** El motor de migraciÃ³n se implementa como un servicio de procesamiento por lotes reanudable e idempotente, desacoplado del canal de transporte.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** SelecciÃ³n entre WP-CLI, AJAX o Background Cron.
- **JUSTIFICACIÃ“N:** Separar la lÃ³gica de negocio del transporte permite invocar el mismo motor desde el navegador (Admin AJAX/REST), desde la consola (WP-CLI) o en tareas programadas sin duplicar cÃ³digo.
- **CONSECUENCIAS:** Procesamiento confiable, reanudable ante caÃ­das de servidor y disponible en cualquier entorno de hosting.

---

### ADR-016: Runtime de Composer MÃ­nimo en DistribuciÃ³n
- **DECISIÃ“N:** Composer se utiliza como herramienta de desarrollo; el paquete final de distribuciÃ³n ZIP incluirÃ¡ el runtime mÃ­nimo necesario (`vendor/autoload.php` y `vendor/composer/*` generado con `--no-dev --optimize-autoloader`), sin herramientas de test o linters.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Garantizar instalaciÃ³n lista para usar sin obligar al usuario final a ejecutar Composer.
- **JUSTIFICACIÃ“N:** Plugins de WordPress para usuarios finales deben funcionar tras descomprimir el ZIP, manteniendo a su vez buenas prÃ¡cticas de autoloading PSR-4.
- **CONSECUENCIAS:** Autoloader de alto rendimiento, cero fricciÃ³n para el usuario final y paquetes livianos sin basura de desarrollo.

---

### ADR-017: Adopción Estricta de WordPress Coding Standards (WPCS)
- **DECISIÓN:** El estilo de código y estándares de calidad se rigen por WordPress Coding Standards (WPCS) y compatibilidad PHP 8.1 - 8.3 mediante PHPCS.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Convenciones de código entre PSR-12 y estándares nativos de WordPress.
- **JUSTIFICACIÓN:** TF Multilingual es un plugin para el ecosistema de WordPress. Seguir las convenciones oficiales de la comunidad garantiza legibilidad, seguridad y mantenibilidad.
- **CONSECUENCIAS:** Código homogéneo, validado por linters automatizados y alineado con los estándares del directorio oficial de plugins de WordPress.

---

### ADR-018: Motor Agnóstico de Políticas para Custom Fields con Adopción Progresiva (Default IGNORE) y Zero Auto-Cloning
- **DECISIÓN:** Los campos personalizados se gestionan bajo una única autoridad (`META KEY -> POLÍTICA TFML -> TRANSLATE | SHARE | IGNORE`) sin crear tablas paralelas de metadatos. Toda clave desconocida o no configurada aplica estrictamente la política por defecto `IGNORE`. Al crear una traducción, únicamente las claves configuradas como `SHARE` se inicializan desde el objeto de origen (respetando Zero Auto-Cloning para `TRANSLATE` e `IGNORE`). La sincronización bidireccional de claves `SHARE` opera mediante hooks nativos de WordPress (`added_post_meta`, `updated_post_meta`, `deleted_post_meta`) con guardia de reentrancia en memoria.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Definición del modelo de campos personalizados independiente de constructores o suites de terceros (ACF, Elementor, WPBakery, Yoast, etc.).
- **JUSTIFICACIÓN:** Mantener `wp_postmeta` como único origen soberano de datos evita fragmentación, mientras que la regla por defecto `IGNORE` previene mutaciones no intencionadas o corrupción en metadatos de terceros/constructores durante migraciones o instalaciones existentes.
- **CONSECUENCIAS:** Motor desacoplado, metadatos nativos transparentes, sincronización bidireccional O(1) inmediata entre hermanos de grupo y protección total contra bucles recursivos.

---

### ADR-019: Adaptador Desacoplado para Advanced Custom Fields (ACF) con Mapeo de Claves de Referencia y Limpieza de Caché de Runtime
- **DECISIÓN:** La integración con Advanced Custom Fields (ACF) se implementa mediante un adaptador satélite opcional y desacoplado (`IntegrationManager` y `AcfIntegration`), sin introducir dependencias duras hacia ACF. En la interfaz de edición de campos de ACF se añade la opción de configuración de política multilingüe (`tfml_policy`), la cual persiste directamente en el registro soberano `CustomFieldPolicyRegistry` usando la clave física de almacenamiento (`wp_postmeta`). Para cualquier campo configurado como `SHARE`, su metadato de referencia emparejado (`_{field_name}`) se sincroniza e inicializa automáticamente como `SHARE`, manteniéndose estrictamente encapsulado y oculto de la vista del usuario en las pantallas de configuración de campos personalizados. Durante las mutaciones de metadatos compartidos, el sincronizador invalida de forma inmediata la memoria de valores de ACF (`acf_flush_value_cache`) en los posts hermanos, garantizando lecturas frescas en el mismo ciclo de petición sin inconsistencias de estado.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Integración con ACF Free (versión 6.8.x) sin alterar la arquitectura soberana de TFML ni forzar dependencias si ACF no está instalado.
- **JUSTIFICACIÓN:** ACF almacena internamente la referencia del campo bajo el prefijo `_{name}` para resolver formateadores complejos y opciones de campo. Al emparejar transparentemente la clave de referencia con la clave de valor, `get_field()` funciona idénticamente en todas las traducciones hermanas, mientras que la invalidación explícita de `acf_flush_value_cache()` elimina lecturas estancadas en memoria.
- **CONSECUENCIAS:** Integración 100% transparente para el usuario de ACF, desacoplamiento estricto cuando ACF no está activo, soporte completo de campos simples, compuestos y anidados en grupos, y compatibilidad total con el motor soberano de políticas de TFML.

---

### ADR-020: Medios Multilingües con Archivo Físico Único y Variantes Editoriales Aisladas
- **DECISIÓN:** Cada medio en la biblioteca es un único attachment físico nativo de WordPress. Las variantes textuales multilingües (texto alternativo ALT, título, leyenda, descripción) se almacenan en la tabla relacional dedicada `tfml_media_translations` sin duplicar archivos en disco ni crear múltiples posts de adjunto. Si un idioma secundario no tiene traducción explícita, se aplica estrictamente fallback hacia los metadatos nativos del attachment en WordPress Core, sin contaminación cruzada hacia otros idiomas secundarios. La resolución en frontend se intercepta mediante filtros nativos (`wp_get_attachment_image_attributes`, `wp_get_attachment_caption`), respetando imágenes destacadas compartidas (`_thumbnail_id`) y WCAG para imágenes decorativas (`alt=""`).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Gestión de biblioteca de medios multilingüe sin fragmentación del almacenamiento físico ni multiplicación de adjuntos.
- **JUSTIFICACIÓN:** Duplicar archivos físicos multiplica el uso de almacenamiento y fragmenta la biblioteca de WordPress. Almacenar variantes en tabla propia con resolución contextual en tiempo de renderizado proporciona soporte multilingüe completo con cero sobrecarga en disco y total compatibilidad con constructores y plugins de optimización de imágenes.
- **CONSECUENCIAS:** Biblioteca limpia, compatibilidad nativa con `_thumbnail_id`, cero clonación de archivos físicos y soporte O(1) de lotes con pre-calentamiento en caché.

---

### ADR-021: Versionado Lógico Basado en Huella Criptográfica Determinista (TranslatableFingerprint) y Estados Editoriales Derivados
- **DECISIÓN:** El estado de vigencia de una traducción (`UNTRANSLATED`, `UPDATED`, `REVIEW`) se determina exclusivamente mediante la comparación de versiones lógicas (`source_version_at_translation >= canonical.current_content_version`) y huellas criptográficas deterministas SHA-256 (`TranslatableFingerprint`), prohibiendo terminantemente el uso de marcas de tiempo (`post_modified`, `updated_at`, etc.) como autoridad de cambio. En términos taxonómicos, el fingerprint evalúa estrictamente `name`, `slug` y `description` (`slug ∈ fingerprint`), garantizando que cualquier cambio en la estructura de URLs o permalinks del término canónico alerte a las traducciones dependientes para su revisión editorial.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Distinguir de forma determinista entre una traducción actualizada y una traducción desactualizada tras modificaciones editoriales en el contenido original.
- **JUSTIFICACIÓN:** Las marcas de tiempo de WordPress (`post_modified`) mutan ante eventos no editoriales (revisiones automáticas, cambios de estado, plugins de seguridad, optimizaciones de base de datos) provocando falsos positivos masivos de revisión. Una huella digital determinista evaluando exclusivamente campos traducibles (título, contenido, extracto y metadatos con política explícita `TRANSLATE` en posts; nombre, slug y descripción en términos) garantiza que la versión sólo avance cuando el texto editorial realmente haya cambiado. El slug taxonómico se incluye formalmente (`slug ∈ fingerprint`) porque define el permalink público, es lingüísticamente traducible y afecta directamente a la navegación y SEO; modificar el slug canónico es una alteración semántica que requiere marcar las traducciones como `REVIEW`.
- **CONSECUENCIAS:** Cero falsos positivos en re-guardados sin cambios, autosaves o revisiones; O(1) propagación de estado a traducciones dependientes sin requerir actualizaciones en lote en base de datos; soporte explícito para confirmación de revisión sin alteración de texto (`mark_translation_reviewed`); detección determinista de cambios en taxonomías (incluyendo renombrado de slug); e indicadores visuales contextuales en listados de administración y editores nativos.

---

### ADR-022: Menús y Navegación Multilingüe con Resolución Contextual de Ubicaciones, Localización de Items y Renderizado Efímero en Bloques FSE
- **DECISIÓN:** Los menús de navegación se gestionan como términos nativos de WordPress bajo la taxonomía `nav_menu`, agrupados mediante `TranslationGroup` en las tablas soberanas existentes `tfml_groups` y `tfml_group_elements` (`element_type = 'term'`, `subtype = 'nav_menu'`) con **cero tablas adicionales**. Para las ubicaciones de tema (`theme_mod_nav_menu_locations`):
  1. En el idioma predeterminado, la configuración nativa de WordPress se mantiene 100% intacta sin intermediación ni mutación.
  2. En idiomas secundarios, `NavMenuFrontendFilter` intercepta los hooks de WordPress (`theme_mod_nav_menu_locations`, `pre_wp_nav_menu`, `wp_nav_menu_args`) sustituyendo dinámicamente el ID del menú por el asignado en `NavMenuLocationRepository` o resuelto automáticamente a través del grupo de traducción. Si no existe traducción ni mapeo, se aplica fallback al menú del idioma predeterminado si la opción de fallback está habilitada.
  3. Los elementos individuales del menú (`wp_get_nav_menu_items`) se localizan contextual al idioma del request:
     - Objetos de post/página se reasignan a su traducción (`ID` y `url`) mediante `ContentTranslationResolver`.
     - Objetos de taxonomía (`category`, `post_tag`, etc.) se reasignan a su término traducido.
     - URLs personalizadas internas se prefijan con el código de idioma activo (respetando la regla de no duplicar prefijos `/en/en/` y preservando query strings y fragmentos). URLs externas permanecen inalteradas.
  4. Para evitar N+1 consultas al renderizar menús grandes, `NavMenuFrontendFilter` ejecuta pre-calentamiento por lotes (`batch prefetch`) de todos los post targets y term targets en O(1) consultas fijas (exactamente 3 consultas SQL para todos los posts y 3 consultas SQL para todos los términos) antes de procesar los items.
  5. En bloques de navegación FSE (`core/navigation`), `BlockNavigationFrontendFilter` intercepta `render_block_data` reemplazando efímeramente el atributo `ref` (que apunta al post `wp_navigation`) por su traducción correspondiente en memoria, con **cero mutaciones** en base de datos y sin alterar la estructura del bloque en memoria.
  6. La administración de ubicaciones multilingües se centraliza en `NavMenuEditorialUi` bajo *Apariencia > Ubicaciones de Menú Multilingüe*, protegida con `edit_theme_options` y nonces criptográficos de WordPress.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Gestión de menús de navegación y ubicaciones de tema en sitios clásicos y basados en bloques (FSE / Gutenberg).
- **JUSTIFICACIÓN:** Los menús de navegación son estructuras complejas que combinan términos (`nav_menu`), posts (`nav_menu_item`, `wp_navigation`) y opciones de tema. Reutilizar la arquitectura relacional existente `TranslationGroup` para términos y posts evita esquemas paralelos, mantiene compatibilidad nativa con `wp_nav_menu()` y el bloque `core/navigation`, y asegura que los enlaces internos siempre dirijan a la versión en el idioma actual del visitante con rendimiento O(1).
- **CONSECUENCIAS:** Menús clásicos y de bloques perfectamente localizados en frontend; cero impacto en la configuración nativa del idioma predeterminado; sustitución efímera sin degradación del contenido almacenado; prevención estricta de N+1 consultas en menús grandes; interfaz de administración nativa integrada en Apariencia.

---

### ADR-023: Traducción de Cadenas de Texto de Interfaz (Strings) con Identidad Semántica Inmutable, Versionado Lógico, Pre-calentamiento O(1) y Estricto Fallback Canónico
- **DECISIÓN:** Las cadenas de texto de interfaz se gestionan bajo una API explícita de registro (`StringRepository::register`) identificadas por la clave compuesta inmutable `domain + string_key` (respetando ADR-013).
  1. **Persistencia y Esquema Físico:** Se activan las tablas maestras existentes `tfml_strings` y `tfml_string_translations` sin alteraciones estructurales del esquema físico.
  2. **Paridad Conceptual con Fase 2.3:** Se establece correspondencia formal e inequívoca entre los estados físicos del esquema y el modelo homologado en Fase 2.3: `untranslated` ↔ `UNTRANSLATED`, `up_to_date` ↔ `UPDATED`, `needs_review` ↔ `REVIEW`.
  3. **Versionado Lógico de Cadenas:** El texto fuente no constituye identidad. Si el texto original muta, `string_version` avanza de $N \to N+1$, y todas las traducciones existentes se degradan deterministamente a `status = 'needs_review'` (REVIEW) sin ser eliminadas ni sobreescritas. Re-registros con idéntico texto fuente son estrictamente idempotentes ($0$ incremento de versión).
  4. **Política Estricta de Fallback y Cero Fallback Lateral:**
     - En el idioma predeterminado, se devuelve directamente el texto fuente sin requerir filas redundantes de traducción en base de datos.
     - En idiomas secundarios activos, se devuelve la traducción existente; si la traducción se encuentra en estado `needs_review` (REVIEW), se sigue sirviendo en frontend para garantizar la continuidad visual y experiencia de usuario mientras espera validación editorial.
     - Si la cadena no posee traducción (`UNTRANSLATED`), o si el idioma consultado está inactivo, se devuelve estrictamente el texto fuente predeterminado. Queda terminantemente prohibido el fallback lateral entre idiomas secundarios (e.g. una traducción faltante en EN jamás degrada a PT o FR).
  5. **Rendimiento Zero N+1 con Pre-calentamiento por Dominio:** `StringRepository::preload_domain` carga en memoria todas las cadenas y traducciones del dominio en $O(1)$ consultas SQL fijas (1-2 consultas), logrando que todas las traducciones posteriores en el ciclo de ejecución se resuelvan en memoria con exactamente **cero consultas SQL**.
  6. **Contrato de Helpers Globales y Escaping:** Se separan formalmente las responsabilidades de traducción y escaping (`tfml__()` devuelve texto sin escapar; `tfml_esc_html__()` y `tfml_e()` aplican `esc_html()`; `tfml_esc_attr__()` aplica `esc_attr()`; `tfml_n()` define el contrato extensible para singulares/plurales sin acoplamientos rígidos).
  7. **UI Administrativa:** `StringEditorialUi` se ubica bajo *TF Multilingual > Cadenas de texto*, utilizando WordPress Admin nativo (sin SPA ni frameworks externos), protegido por `manage_options` (filtrable vía `tfml_manage_strings_capability`) y nonces de seguridad.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Gestión de cadenas de interfaz dinámicas y de plugins/temas sin interceptar globalmente gettext ni reemplazar archivos `.po/.mo` de WordPress Core.
- **JUSTIFICACIÓN:** Separar las cadenas explícitas de la infraestructura general de gettext previene sobrecarga masiva en memoria y colisiones con cadenas internas de WordPress, al tiempo que proporciona control editorial de versiones y actualizaciones sin fricción.
- **CONSECUENCIAS:** API predecible y ultrarrápida; rendimiento O(1) comprobado en listas de más de 100 cadenas; preservación de traducciones ante correcciones tipográficas del original; interfaz nativa para traductores y administradores.

---

### ADR-024: Arquitectura SEO Multilingüe Soberana (Hreflang, Canonical Unívoco y Sitemaps Nativos de WordPress Core)
- **DECISIÓN:** Se implementa la suite técnica de SEO Multilingüe de TF Multilingual estructurada en tres pilares inseparables:
  1. **Generación Soberana de Hreflang (`HreflangGenerator`):**
     - Emite etiquetas `<link rel="alternate" hreflang="..." href="..." />` en `<head>` para contenido singular, taxonomías y portada.
     - **Invariante de Publicación:** Solo las variantes de traducción con estado de publicación `'publish'` (`$post->post_status === 'publish'`) en idiomas activos son indexables e incluidas en `hreflang`. Contenidos en borrador (`draft`), privado, papelera o asociados a idiomas inactivos son estrictamente descartados.
     - **Invariante de Frescura Editorial vs Publicación:** El estado `REVIEW` (`needs_review`) derivado del versionado lógico (Fase 2.3) es un indicador de frescura editorial interna, **no** un estado de visibilidad o despublicación. Por tanto, un contenido publicado (`'publish'`) que requiera revisión permanece plenamente indexable y debe ser emitido en `hreflang` y `sitemaps`.
     - **Invariante Estricta de `x-default`:** La etiqueta `x-default` apunta de forma determinista y exclusiva a la URL de la variante en el idioma predeterminado del sitio (e.g. `es`), **únicamente** cuando existe una variante publicada válida en dicho idioma. Si un grupo de traducción solo cuenta con variantes publicadas en idiomas secundarios (e.g. `en` y `pt`, pero no `es`), la etiqueta `x-default` queda **estrictamente ausente** (jamás se inventa, jamás apunta a la portada de fallback, ni a un idioma secundario arbitrario).
  2. **Gestión Unívoca de Canonical (`CanonicalUrlManager`):**
     - Cada variante de traducción indexable cuenta con su propia URL canónica localizada (`rel="canonical"`). Queda prohibido apuntar todas las traducciones al idioma predeterminado.
     - Se intercepta el hook canónico de WordPress Core (`wp_get_canonical_url`) y los hooks canónicos de suites SEO de terceros populares (`wpseo_canonical` para Yoast SEO y `rank_math/canonical_url` para Rank Math), garantizando compatibilidad sin acoplamiento duro y evitando etiquetas canónicas duplicadas o conflictivas.
  3. **Integración con XML Sitemaps Nativos de WordPress Core (`CoreSitemapsFilter`):**
     - Se reutiliza la infraestructura nativa de WordPress Core (`wp_sitemaps`) sin crear sitemaps paralelos.
     - Para evitar que el aislamiento de consultas de TFML oculte las traducciones de otros idiomas durante la generación del sitemap, se interceptan las consultas de posts y taxonomías (`wp_sitemaps_posts_query_args` y `wp_sitemaps_taxonomies_query_args`) estableciendo `tfml_suppress_filters => true`, y se excluye el contexto sitemap (`is_sitemap()`, query var `sitemap`) en `QueryLanguageFilter` y `TermQueryLanguageFilter`.
     - Las entradas del sitemap (`wp_sitemaps_posts_entry` y `wp_sitemaps_taxonomies_entry`) localizan dinámicamente `$entry['loc']` al idioma correspondiente de cada elemento y descartan entradas en borrador o pertenecientes a idiomas inactivos retornando un array vacío (`array()`).
  4. **Frontend Hooking (`SeoFrontendFilter`):**
     - Centraliza la inicialización de los componentes y conecta la salida HTML de `hreflang` a la acción `wp_head` con prioridad temprana (prioridad 2), exponiendo el filtro extensible `tfml_hreflang_variants`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Indexación en motores de búsqueda, señalización multilingüe internacional y prevención de canibalización de contenidos o contenido duplicado entre idiomas.
- **JUSTIFICACIÓN:** Cumple rigurosamente las especificaciones de Google Search Central y Bing Webmaster Guidelines para sitios multilingües, manteniendo la soberanía de la URL y evitando sobrecarga al no reinventar el motor de sitemaps de WordPress Core.
- **CONSECUENCIAS:** Señalización internacional precisa y conforme a estándares; sitemaps automáticos y completos para todos los idiomas; compatibilidad sin fricción con Yoast SEO y Rank Math; cero bit drift y cero escrituras en bases de datos externas o tablas centinela.

---

### ADR-025: Arquitectura REST API Multilingüe Nativa de WordPress con Protección Anti-IDOR, Versionado Lógico y Paginación O(1)
- **DECISIÓN:** Se implementa el subsistema REST API de TF Multilingual bajo el namespace soberano `tf-multilingual/v1`, reutilizando la arquitectura de controladores nativa de WordPress Core (`WP_REST_Controller`):
  1. **Estructura de Controladores y Registro (`RestApiRegistrar`):**
     - Centraliza la registración de endpoints conectándose a la acción nativa `rest_api_init`.
     - Orquesta tres controladores especializados: `LanguagesController`, `TranslationsController`, y `StatusController`.
  2. **Endpoint Público de Idiomas (`LanguagesController`):**
     - `GET /wp-json/tf-multilingual/v1/languages`: lectura pública (`__return_true`) de idiomas registrados.
     - Parámetro opcional `all=true` para incluir idiomas inactivos (por defecto retorna únicamente idiomas activos).
     - Retorna: `code`, `locale`, `name`, `native_name`, `is_default`, `active`, `order`.
  3. **Endpoints de Traducciones y Grupos (`TranslationsController`):**
     - `GET /wp-json/tf-multilingual/v1/translations`: colección paginada con headers `X-WP-Total` y `X-WP-TotalPages`, filtros por `element_type` y `subtype`, y paginación en base de datos en O(1) mediante `TranslationGroupRepository::paginate_groups`.
     - `GET /wp-json/tf-multilingual/v1/translations/(post|term)/{id}`: inspección del grupo de traducción, elementos hermanos, estados, URLs canónicas/edit y lista de idiomas sin traducir. Si el elemento no tiene grupo, responde estructura estándar sin error con `group_id => null`.
     - `POST /wp-json/tf-multilingual/v1/translations/link`: vinculación de elementos existentes en un grupo (o creación de grupo si el elemento fuente aún no está asignado).
     - `POST /wp-json/tf-multilingual/v1/translations/unlink`: desvinculación transaccional de un elemento de su grupo de traducción con reasignación opcional de elemento canónico.
  4. **Endpoints de Estado Editorial y Versionado Lógico (`StatusController`):**
     - `GET /wp-json/tf-multilingual/v1/status/(post|term)/{id}`: consulta detallada de estado editorial (`updated`, `review`, `untranslated`), versiones de contenido (`current_version`, `source_version`), fingerprints criptográficos y versiones canónicas.
     - `POST /wp-json/tf-multilingual/v1/status/reviewed`: marcado editorial de traducción revisada, alineando `source_version_at_translation` con la versión actual del canónico y retornando el estado actualizado `updated` (`needs_review => false`).
  5. **Gobernanza de Seguridad Estricta y Prevención Anti-IDOR:**
     - Todo endpoint declara explícitamente `permission_callback`.
     - En endpoints de lectura (`GET`), si el post no está publicado (`draft`, `pending`, `private`, `trash`), se requiere `read_post` o `edit_post` sobre el ID específico (`current_user_can('edit_post', $id)`), impidiendo la divulgación indebida de contenidos no públicos.
     - En el endpoint de vinculación (`POST /link`), se exige autorización de edición sobre **ambos** elementos (`source_id` y `target_id`), impidiendo ataques de suplantación o manipulación cruzada de contenidos protegidos.
     - En endpoints de escritura/mutación (`POST /unlink`, `POST /status/reviewed`), se verifica la capacidad editorial sobre el elemento específico (`current_user_can('edit_post', $id)` o `current_user_can('edit_term', $id)`).
     - Esquemas OpenAPI y JSON Schema declarados con callbacks estrictos de sanitización (`sanitize_callback`) y validación (`validate_callback`).
  6. **Reutilización de Lógica de Negocio y Cero Escrituras Externas:**
     - Los controladores no duplican lógica de dominio; delegan a `TranslationEditorialService`, `TranslationGroupRepository`, `ContentTranslationResolver`, `TranslationStatusResolver` y `LanguageRegistry`.
     - Garantía de Cero mutaciones en bases de datos externas o tablas centinela (`*_icl_*`).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Consumo de metadatos multilingües, sincronización desacoplada, soporte headless y administración editorial mediante interfaces REST estándar.
- **JUSTIFICACIÓN:** El ecosistema moderno de WordPress exige que las capacidades multilingües sean plenamente accesibles vía REST API sin comprometer la seguridad (anti-IDOR) ni el modelo determinista de versionado y grupos.
- **CONSECUENCIAS:** Interfaz REST nativa, documentada y estandarizada; integración fluida con Gutenberg, editores headless y herramientas de traducción externas; cero drift en tablas centinela; seguridad verificada en laboratorio real y pruebas unitarias exhaustivas.

---

### ADR-026: Diagnóstico Administrativo Soberano, Integración con Site Health de WordPress Core y Cierre Transversal del Core
- **DECISIÓN:** Se formaliza el subsistema de Diagnóstico del Sistema y Health Check de TF Multilingual junto con la certificación de cierre del Core del plugin:
  1. **Servicio Desacoplado de Diagnóstico de Solo Lectura (`DiagnosticService`):**
     - Ubicado en el namespace soberano `TF\Multilingual\Diagnostic`.
     - Ejecuta auditorías bajo demanda sin sobrecarga en la navegación rutinaria del sitio.
     - Cobertura transversal de seis ejes de salud del Core:
       a) Entorno de ejecución: compatibilidad de PHP (>= 8.1), WordPress (>= 6.8) y extensiones requeridas (`mbstring`, `json`, `hash`).
       b) Tablas maestras SQL: verificación física de existencia y recuentos de filas en las 5 tablas oficiales (`tfml_groups`, `tfml_group_elements`, `tfml_media_translations`, `tfml_strings`, `tfml_string_translations`) y correspondencia de versión de esquema (`SchemaManager::SCHEMA_VERSION`).
       c) Catálogo de idiomas: validación de configuración (`is_configured`), existencia y estado activo del idioma predeterminado, y discriminación de idiomas activos/inactivos.
       d) Integridad relacional libre de N+1: detección mediante consultas agregadas únicas de grupos vacíos, duplicados por grupo, asignaciones múltiples, elementos huérfanos sin post/término correspondiente y canonicals desalineados.
       e) Enrutamiento y reescritura de URLs: verificación de permalinks bonitos y prefijos lingüísticos secundarios.
       f) Módulos funcionales del Core: reporte de estado operativo de Media, Strings, Menús, SEO y REST API.
     - Clasificación determinista de salud: `good` (óptima), `warning` (atención recomendada) y `critical` (inconsistencia grave).
     - Principio de No Invasión: estrictamente de solo lectura; cero reparaciones destructivas automáticas.
  2. **Interfaz Administrativa Dedicada y Segura (`DiagnosticUi`):**
     - Submenú nativo bajo *TF Multilingual > Diagnóstico* (`tfml-diagnostic`).
     - Protección exclusiva mediante la capability `manage_options` y nonces de WordPress.
     - Cero exposición de rutas internas absolutas, credenciales, hashes ni datos sensibles.
     - Botón interactivo para ejecutar auditorías en vivo.
  3. **Integración Nativa con WordPress Core Site Health (`site_status_tests`):**
     - Registra tres pruebas directas oficiales en *Herramientas > Salud del sitio*:
       - `tfml_tables_integrity`: Integridad de las 5 tablas maestras.
       - `tfml_default_language`: Configuración y vitalidad del idioma predeterminado.
       - `tfml_relations_integrity`: Integridad relacional y consistencia de grupos.
  4. **Protección Anti-IDOR Reforzada en Endpoints REST:**
     - Endpoints públicos `/translations` y `/status` validan visibilidad elemento por elemento, ocultando posts borradores o privados y enmascarando canonicals protegidos ante usuarios no autorizados.
  5. **Certificación y Cierre Oficial del Core de TF Multilingual:**
     - Auditoría superada en persistencia, configuración (`tfml_settings`), ciclo de vida (`Lifecycle::activate()`, `Lifecycle::deactivate()`), desinstalación segura (`uninstall.php`), seguridad anti-IDOR, estándares WPCS y compatibilidad integral.
     - Cero bit drift e invariancia absoluta en el centinela externo WPML.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Finalización del Core multilingüe antes de la apertura del ciclo Post-Core (Builders e integraciones externas).
- **JUSTIFICACIÓN:** El sistema requiere capacidades soberanas de auditoría y diagnóstico para garantizar que el núcleo es sólido, consistente, auditable y seguro antes de interactuar con integraciones de terceros.
- **CONSECUENCIAS:** Supervisión en tiempo real de la integridad del CMS; cero consultas N+1 en la administración; compatibilidad nativa con WordPress Site Health; cierre formal del Core de TF Multilingual.

---

### ADR-027: Adaptador de Compatibilidad para WPBakery Page Builder y Formalización del Ciclo de Vida (Hardening de Desinstalación y Retención)
- **DECISIÓN:**
  1. **Hardening del Ciclo de Vida y Política de Retención (`Lifecycle` & `SchemaManager`):**
     - La desactivación de TF Multilingual es estrictamente no destructiva: preserva al 100% las 5 tablas maestras, esquemas, relaciones, cadenas y opciones.
     - La desinstalación (`uninstall.php` y `Lifecycle::uninstall()`) aplica por defecto una **política de retención segura de datos** sin eliminar tablas ni configuraciones.
     - Se implementa una opción explícita de purga (`tfml_purge_data_on_uninstall`). Únicamente cuando esta opción esté configurada en `true`, `Lifecycle::uninstall()` invoca `SchemaManager::drop_tables()` eliminando las 5 tablas maestras en orden estricto inverso de dependencia (`tfml_string_translations`, `tfml_strings`, `tfml_media_translations`, `tfml_group_elements`, `tfml_groups`) y elimina las opciones maestras (`tfml_settings`, `tfml_schema_version`, `tfml_purge_data_on_uninstall`).
  2. **Adaptador Desacoplado para WPBakery Page Builder (`WPBakeryIntegration`):**
     - Se integra un adaptador modular en `TF\Multilingual\Integration\WPBakery\WPBakeryIntegration` coordinado a través de `IntegrationManager`.
     - Políticas soberanas de metadatos registradas automáticamente en `CustomFieldPolicyRegistry`:
       - `_wpb_vc_js_status`: Política `SHARE` (sincroniza el estado del editor visual para que las traducciones abran el builder de forma nativa).
       - `_wpb_post_custom_css`: Política `TRANSLATE` (independencia editorial para estilos CSS específicos de cada idioma).
       - `_wpb_shortcodes_custom_css`: Política `TRANSLATE` (independencia editorial para opciones de diseño de shortcodes individuales).
     - Respeto de configuraciones previas: el registro por defecto no sobreescribe políticas personalizadas previamente fijadas por el administrador.
  3. **Duplicación Asistida de Estructura y Zero-Cloning Selectivo:**
     - Se preserva el principio de Zero-Cloning para posts regulares y bloques Gutenberg estándar (`post_content` inicial vacío en borradores de traducción).
     - Para posts que contienen estructura de WPBakery (`[vc_row]`, `[vc_column]`), el hook `tfml_initial_translation_post_content` inicializa el borrador de traducción con la jerarquía de shortcodes clonada y localiza los atributos de medios, permitiendo al editor traducir textos en pantalla sin reconstruir la cuadrícula visual.
     - El hook `tfml_post_translation_created` duplica el CSS personalizado base al borrador inicial, manteniendo absoluta independencia en mutaciones posteriores (`TRANSLATE`).
  4. **Localización de Media en Shortcodes y Pre-calentamiento O(1):**
     - El método `localize_shortcode_media` detecta atributos `image="ID"`, `background_image="ID"` y listas `images="ID1,ID2"`.
     - Extrae los IDs de medios y pre-calienta la caché mediante `MediaTranslationResolver::prime_cache()` evitando consultas N+1.
     - Permite mapeo de IDs traducidos mediante el filtro extensible `tfml_localize_attachment_id`.
  5. **Fronteras Estrictas Post-Core:**
     - Elementor queda estrictamente aislado para la Fase 3.2 sin mezclar código en esta fase.
     - Cero escrituras directas sobre tablas externas ni sobre el centinela WPML (`*_icl_*`).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Apertura del ciclo Post-Core (Fase 3.1) enfocado en constructores visuales basados en shortcodes (`js_composer` / WPBakery).
- **JUSTIFICACIÓN:** Los usuarios editoriales de WPBakery requieren conservar la arquitectura de filas, columnas y opciones de diseño al traducir páginas, sin romper el principio de independencia editorial de TF Multilingual.
- **CONSECUENCIAS:** Soporte estructural completo para páginas maquetadas con WPBakery; retención segura garantizada en el ciclo de vida; certificación unitaria y de laboratorio real con cero regresiones en el Core.

---

### ADR-028: Adaptador de Compatibilidad para Elementor y Elementor Pro con Localización Segura del Árbol de Elementos JSON y Zero-Cloning Selectivo
- **DECISIÓN:**
  1. **Adaptador Desacoplado para Elementor (`ElementorIntegration`):**
     - Implementación modular en `TF\Multilingual\Integration\Elementor\ElementorIntegration` coordinado a través de `IntegrationManager`.
     - Detección dinámica y reactiva del entorno mediante comprobación de las constantes oficiales `ELEMENTOR_VERSION` y `ELEMENTOR_PRO_VERSION`.
  2. **Políticas Soberanas de Metadatos de Elementor:**
     - Se registran automáticamente políticas por defecto en `CustomFieldPolicyRegistry` respetando configuraciones previas del usuario:
       - `_elementor_edit_mode`: Política `SHARE` (sincroniza el flag de edición visual 'builder' para que las traducciones abran el lienzo de Elementor de forma nativa).
       - `_elementor_template_type`: Política `SHARE` (sincroniza la tipología de plantilla, e.g., 'wp-post', 'wp-page').
       - `_elementor_version`: Política `SHARE` (garantiza coherencia de versión del motor).
       - `_elementor_pro_version`: Política `SHARE` (garantiza compatibilidad de versión de Elementor Pro).
       - `_wp_page_template`: Política `SHARE` (sincroniza la plantilla de página seleccionada, e.g., `elementor_canvas`, `elementor_header_footer`).
       - `_elementor_data`: Política `TRANSLATE` (independencia editorial absoluta del árbol JSON de secciones, columnas y widgets).
       - `_elementor_page_settings`: Política `TRANSLATE` (independencia de ajustes visuales y tipográficos específicos de cada idioma).
       - `_elementor_css`: Política `IGNORE` (caché de CSS generado por Elementor tratada como efímera, regenerándose en demanda por idioma sin contaminaciones cruzadas).
  3. **Preservación y Localización Segura del Árbol JSON (`_elementor_data`):**
     - Procesamiento estructurado mediante decodificación JSON (`json_decode`) y persistencia protegida mediante `wp_slash(wp_json_encode())` para evitar la eliminación de barras invertidas por parte de `update_post_meta`.
     - Recorrido recursivo exhaustivo preservando rigurosamente la jerarquía visual (`elements`, `widgetType`, `elType`) y los identificadores técnicos (`id: "..."`). Se preservan los IDs de elemento para no invalidar reglas de CSS personalizado asociadas a los selectores `.elementor-element-{id}`.
     - Localización asistida y tipada de campos dentro del diccionario `settings`:
       - **Medios individuales** (`image`, `photo`, `background_image`, `icon`, etc.): mapeo de `id` y `url` mediante `MediaTranslationResolver` con fallback determinista.
       - **Galerías** (`gallery`, `carousel`, etc.): iteración y localización de arrays de objetos de medios (`['id' => X, 'url' => Y]`).
       - **Enlaces internos** (`url` en configuraciones tipo enlace): resolución automática de IDs de post o enlaces directos cuando apuntan a contenidos con traducción existente en el idioma destino.
       - **Referencias a plantillas** (`template_id` en widgets de plantilla o globales): mapeo al ID de la plantilla traducida correspondiente.
       - **Prohibición de regex ciegas:** Cero reemplazos globales por expresiones regulares en strings JSON para evitar corrupciones de sintaxis o fallos de escape.
  4. **Duplicación Asistida y Zero-Cloning Selectivo:**
     - Para posts estándar o bloques Gutenberg no maquetados con Elementor, se mantiene estrictamente el principio de Zero-Cloning (`post_content` inicial vacío y cero metadatos de Elementor).
     - Para posts maquetados con Elementor (`_elementor_edit_mode === 'builder'`), el hook `tfml_post_translation_created` duplica y localiza el árbol `_elementor_data` y clona `_elementor_page_settings`, dejando la traducción lista para edición en el lienzo de Elementor sin necesidad de reconstruir la composición visual.
  5. **Independencia Editorial y Verificación en Runtime Real:**
     - Modificaciones en la traducción no alteran el post fuente original (validado experimentalmente en laboratorio).
     - Verificación directa sobre el entorno de ejecución activo de WordPress con Elementor 4.3.4 y Elementor Pro 4.1.0, renderizando en vivo con `\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id)` demostrando contenido localizado e independiente.
     - Cero escrituras directas sobre tablas externas ni sobre el centinela WPML (`*_icl_*`).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.2 — Integración y certificación de compatibilidad con constructores visuales basados en árbol JSON (Elementor y Elementor Pro).
- **JUSTIFICACIÓN:** Elementor almacena la maquetación en un árbol serializado JSON (`_elementor_data`). Una traducción requiere duplicar inicialmente la estructura de contenedores y widgets localizando los medios y enlaces asociados, manteniendo a la vez absoluta independencia editorial posterior y compatibilidad con el motor de renderizado frontend de Elementor.
- **CONSECUENCIAS:** Compatibilidad transparente y robusta con Elementor y Elementor Pro; cero interferencia en posts que no usan Elementor; renderizado frontend fiel; y certificación completa con el centinela WPML inalterado.





