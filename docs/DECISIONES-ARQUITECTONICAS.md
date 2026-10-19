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

---

### ADR-029: Subsistema de Migración Desacoplado: Principio Estricto de Solo Lectura, Inventario Diagnóstico y Simulación Dry-Run
- **DECISIÓN:**
  1. **Principio Absoluto de Solo Lectura sobre Sistemas de Origen:**
     - El subsistema de migración (`TF\Multilingual\Migration\`) opera de forma estrictamente no invasiva sobre las tablas de sistemas multilingües externos (WPML `*_icl_*`, Polylang `term_taxonomy`).
     - Queda terminantemente prohibida cualquier mutación, alteración estructural o eliminación (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`) sobre tablas ajenas a TFML.
  2. **Detección Dinámica de Fuentes (`MigrationSourceDetector`):**
     - Inspección de tablas y taxonomías disponibles para identificar fuentes instaladas (`wpml`, `polylang`) con recuentos globales y metadatos de configuración.
  3. **Inventario Diagnóstico e Inmutable (`MigrationInventoryReport`):**
     - Detección exhaustiva de idiomas de origen y evaluación de correspondencia con el catálogo soberano de TFML (`LanguageRegistry`), señalando idiomas ausentes como advertencia previa a cualquier importación.
     - Clasificación y conteo de elementos traducibles desglosados por tipo de contenido (`post_page`, `post_post`, `post_tours`, `tax_category`, `tax_post_tag`, etc.).
     - Conteo diferenciado de grupos de traducción (`trid`) separando grupos de contenido editorial de elementos adjuntos (`post_attachment`), asegurando que la futura importación respete la arquitectura Media Model B Refinado de TFML (adjuntos compartidos sin duplicación física de posts).
     - Identificación de conflictos potenciales (elementos ya registrados previamente en `tfml_group_elements`) y registros huérfanos en la fuente (elementos que ya no existen en `wp_posts` o `wp_terms`).
  4. **Simulación de Migración (Dry-Run):**
     - Los analizadores de origen (`WpmlSourceAnalyzer`, `PolylangSourceAnalyzer`) implementan simulaciones estructuradas en memoria (`simulate_migration()`).
     - Identifican el elemento canónico (`source_language_code IS NULL` o primer elemento) y proyectan la estructura de grupos de traducción de TFML sin ejecutar escrituras en base de datos.
  5. **Gobernanza de Ejecución:**
     - No se ejecutan migraciones masivas ni destructivas sin un entorno controlado, respaldo verificado y autorización explícita.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.3 — Apertura del subsistema de migración desde plugins multilingües de terceros.
- **JUSTIFICACIÓN:** Una migración fiable requiere comprender exactamente el estado relacional de los datos preexistentes, validar los idiomas activos y verificar posibles inconsistencias antes de alterar cualquier tabla de TFML, manteniendo total inmunidad en el centinela WPML.
- **CONSECUENCIAS:** Capacidad de diagnóstico predictivo y simulaciones de migración 100% seguras; cero riesgo de corrupción en las tablas originales de WPML o Polylang; base sólida para futuros runners de importación por lotes idempotentes.

---

### ADR-030: Planificador Determinista de Migración, Clasificación Semántica, Checkpoints Idempotentes y Política de Retención Lingüística (Dry-Run)
- **DECISIÓN:**
  1. **Planificador Determinista (`WpmlMigrationPlanner`, `MigrationPlan`):**
     - Convierte el inventario de solo lectura en un manifiesto ejecutable inmutable (`MigrationPlan`) que clasifica deterministamente cada grupo de traducción de origen en una de cinco categorías semánticas:
       - `IMPORTABLE`: Grupos íntegros cuyos elementos existen en WordPress Core, pertenecen a idiomas activos en TFML y no colisionan con asignaciones previas.
       - `BLOCKED_BY_LANGUAGE`: Grupos que contienen una o más traducciones en idiomas no soportados o inactivos en TFML (ej. francés `fr`).
       - `SKIPPED`: Elementos expresamente excluidos del modelo editorial soberano (comentarios, prioridades de paquetes, tipos técnicos).
       - `ORPHAN`: Grupos cuyos elementos referenciados en WPML fueron eliminados de `wp_posts` o `wp_terms`.
       - `CONFLICT`: Elementos que ya pertenecen a un grupo existente en `tfml_group_elements` con asignación incompatible.
  2. **Política Soberana de Retención de Idiomas Pendientes (`MISSING_LANGUAGE_DEPENDENCY`):**
     - La presencia de traducciones en idiomas no activos en TFML (como `fr` en `cms.ecoterra`) **NUNCA** provocará su descarte silencioso ni su pérdida de datos.
     - El planificador retiene explícitamente estas traducciones en el mapa `pending_translations[<lang>]` del grupo, mientras proyecta en `translations[<lang>]` las pertenecientes a idiomas activos (`es`, `en`).
     - Queda estrictamente prohibido que el planificador registre o active de manera automática o silenciosa idiomas en `LanguageRegistry` o `tfml_settings`. La configuración lingüística requiere autorización explícita.
     - El manifiesto declara el estado general `NEEDS_CONFIGURATION` si detecta dependencias lingüísticas no resueltas.
  3. **Gobernanza de Medios bajo Media Model B Refinado:**
     - Los 3.033 adjuntos detectados en WPML no se planifican como posts independientes ni como grupos en `tfml_groups`.
     - Se planifica su deduplicación física hacia un único attachment en WordPress, proyectando sus textos localizados (título, alt, leyenda, descripción) en la tabla `tfml_media_translations`.
  4. **Arquitectura de Checkpoints e Idempotencia (`MigrationCheckpoint`):**
     - Objeto de estado inmutable y serializable que registra el progreso de lotes (`processed_items`, `last_processed_trid`, `errors`, `status`).
     - Garantiza la reanudación segura de procesos por lotes interrumpidos sin duplicar grupos ni corromper relaciones.
  5. **Modo Estricto de Solo Lectura y Dry-Run:**
     - La planificación opera enteramente en memoria y consultas `SELECT`. Cero escrituras en la base de datos de WordPress ni en el centinela WPML (`*_icl_*`).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.4 — Planificador y Motor de Simulación Determinista de Migración WPML → TFML.
- **JUSTIFICACIÓN:** Transformar miles de registros legados sin riesgo de corrupción exige clasificar semánticamente cada grupo, retener sin pérdidas las traducciones en idiomas pendientes, aplicar el modelo de medios deduplicado y asegurar que cualquier lote futuro sea idempotente y reanudable.
- **CONSECUENCIAS:** Transparencia total antes de cualquier mutación; preservación íntegra de traducciones en francés para su importación futura; cumplimiento de los invariantes de WordPress y del centinela WPML inalterado.

---

### ADR-031: Ejecutor Transaccional de Migraciones, Idempotencia, Checkpoints Coordinados y Control de Concurrencia
- **DECISIÓN:**
  1. **Ejecutor Transaccional Desacoplado (`WpmlMigrationExecutor`):**
     - Procesamiento de lotes (`batch_size`) configurables envueltos atómicamente dentro de transacciones de base de datos (`START TRANSACTION`, `COMMIT`, `ROLLBACK`), coordinadas con niveles de anidamiento y `SAVEPOINT`s en `TranslationGroupRepository`.
     - Garantiza que cualquier fallo o colisión relacional revierta de forma limpia e íntegra el lote en curso, evitando residuos, huérfanos o estados corruptos.
  2. **Gobernanza y Autorización Estricta de Entorno:**
     - El ejecutor permanece cerrado por defecto (`is_authorized() = false`). Requiere autorización explícita (`set_authorized(true)` o constante `TFML_MIGRATION_EXECUTION_AUTHORIZED = true`) previa a cualquier operación de mutación.
     - Verificación de frescura e integridad previa al lote: detecta si publicaciones o términos referenciados en grupos `IMPORTABLE` han sido modificados o eliminados de Core entre la planificación y la ejecución, abortando de inmediato (`MigrationPlanValidationException`).
     - Cero exposición pública: no se añade interfaz REST, AJAX, WP-CLI ni acción administrativa que permita invocar la migración sobre `cms.ecoterra`.
  3. **Idempotencia y Preservación de Asignaciones:**
     - Antes de crear un grupo, inspecciona si los elementos ya pertenecen a `tfml_group_elements`.
     - Si todos los elementos del grupo ya están asignados al mismo grupo en TFML, omite la creación (`idempotent_skipped`) sin duplicar filas.
     - Si los elementos se encuentran divididos o asignados a otros grupos, detecta la colisión y genera una excepción relacional.
  4. **Control de Concurrencia (`MigrationExecutionLock`):**
     - Sistema de bloqueo exclusivo por fuente basado en Options API con token de ejecución y expiración (TTL).
     - Bloquea intentos concurrentes arrojando `MigrationConcurrencyException`.
  5. **Gobernanza de Medios bajo Media Model B Refinado:**
     - Los adjuntos (`attachment`, `post_attachment`) quedan terminantemente excluidos de la creación de posts editoriales en `tfml_groups`.
  6. **Trazabilidad y Retención de Idiomas Pendientes:**
     - Se documenta formalmente la diferencia técnica entre los 52 grupos `BLOCKED_BY_LANGUAGE` y los 53 elementos franceses retenidos: los 52 grupos corresponden a contenido editorial/taxonomías estándar, mientras que el elemento 53 (ID 106) pertenece a un grupo técnico `tax_translation_priority` clasificado como `SKIPPED`, cuya traducción francesa se retiene intacta en `pending_translations` para prevenir pérdidas de datos.
     - El idioma francés (`fr`) se mantiene pendiente de configuración sin registrarse ni activarse automáticamente.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.5 — Ejecutor transaccional de migraciones, idempotencia y recuperación ante fallos.
- **JUSTIFICACIÓN:** La ejecución de planes de migración complejos exige garantías matemáticas de atomicidad, control de concurrencia para evitar condiciones de carrera, salvaguardas contra datos obsoletos, y estricta idempotencia para que cualquier reintento o reanudación sea 100% seguro.
- **CONSECUENCIAS:** Capacidad de procesar lotes, recuperarse de interrupciones y evitar duplicaciones sin poner en riesgo la base de datos real de `cms.ecoterra`.

---

### ADR-032: Endurecimiento del Ejecutor Transaccional, Fencing Tokens, Validación Criptográfica y Reconciliación Profunda
- **DECISIÓN:**
  1. **Control de Concurrencia y Fencing Tokens (`verify_ownership` & `renew`):**
     - Incorporación de renovación activa de locks (`renew()`) y verificación de posesión (`verify_ownership()`) como fencing tokens tanto al inicio de cada lote como inmediatamente antes de confirmar cada `COMMIT` transaccional.
     - Si un proceso pierde el lock por expiración (TTL) o desincronización antes de escribir, la transacción es abortada inmediatamente y revertida (`ROLLBACK`) arrojando `MigrationConcurrencyException`, impidiendo escrituras en conflicto o situaciones split-brain.
  2. **Huellas Digitales Criptográficas Deterministas del Plan (`MigrationPlan::get_fingerprint`):**
     - Cálculo de un hash SHA-256 canónico y determinista sobre la totalidad del manifiesto del plan (`source`, `status`, dependencias de idiomas, métricas de resumen, estrategia de medios y grupos planificados).
     - Validación obligatoria de huella opcional (`verify_fingerprint`) para garantizar que el plan ejecutado sea idéntico al plan auditado durante el dry-run.
  3. **Detección Rigurosa de Planes Obsoletos y Divergencias de Datos:**
     - En `validate_plan()`, se comprueba de forma exhaustiva antes de cualquier lote:
       - Existencia física en WordPress Core mediante `WordPressElementValidator`.
       - Detección de elementos enviados a la papelera (`get_post_status() === 'trash'`), abortando con error explícito de obsolescencia.
       - Detección de divergencias en la fuente WPML: verificación de que el elemento conserve el mismo TRID e idioma en `icl_translations`.
       - Detección de colisiones relacionales sobrevenidas en TFML introducidas tras la fase de planificación (asignaciones parciales o conflictivas).
  4. **Recuperación Idempotente ante Interrupciones Post-COMMIT:**
     - En caso de una interrupción abrupta (corte de servidor o timeout) ocurrida tras el `COMMIT` en base de datos pero antes de la persistencia del checkpoint en `wp_options`, la reanudación del proceso identifica que los elementos ya pertenecen al grupo (`count($translations) === $found_count && 1 === count($existing_groups)`), clasifica el lote como `idempotent_skipped` y avanza el checkpoint sin generar inserciones duplicadas en `tfml_groups` ni en `tfml_group_elements`.
  5. **Reconciliación Profunda de Integridad Relacional:**
     - El método `reconcile()` valida exhaustivamente elemento por elemento: correspondencia de tipo y subtipo, coincidencia del ID canónico, coincidencia del idioma canónico, paridad numérica de elementos y correspondencia exacta de cada traducción por código de idioma, reportando cualquier anomalía en una lista estructurada de discrepancias (`discrepancies`).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.6 — Endurecimiento del ejecutor y pruebas de recuperación.
- **JUSTIFICACIÓN:** Antes de realizar importaciones en entornos reales, el ejecutor transaccional debe blindarse frente a interrupciones imprevistas de infraestructura, evitar escrituras obsoletas si los datos en WordPress Core o WPML cambiaron tras el dry-run, y garantizar trazabilidad criptográfica absoluta.
- **CONSECUENCIAS:** Motor de migración robusto, tolerante a fallos, protegido contra desincronizaciones de concurrencia y completamente verificable mediante reconciliación profunda.



---

### ADR-033: Motor de Rollback Transaccional, Reversibilidad No Destructiva y Atomicidad en Bloqueos de Migración
- **DECISIÓN:**
  1. **Motor de Rollback Transaccional No Destructivo (`WpmlMigrationRollbackService`):**
     - Capacidad de revertir completamente las ejecuciones de migración mediante `rollback_execution()` y `rollback_groups()`.
     - Reversión atómica y acotada estrictamente a las tablas de TF Multilingual (`tfml_groups` y `tfml_group_elements`).
     - Cero modificaciones y cero eliminaciones en las tablas y entidades del WordPress Core (`wp_posts`, `wp_postmeta`, `wp_terms`, `wp_term_taxonomy`).
     - Cero escrituras (0 INSERT / UPDATE / DELETE) en las tablas de WPML (`icl_translations` permanece 100% de solo lectura e intocada).
     - Limpieza automática del checkpoint de migración asociado y liberación forzada del lock tras reversión exitosa.
  2. **Protección contra Modificaciones Editoriales Posteriores:**
     - Detección previa en `inspect_dependencies()` comparando la versión de contenido del elemento (`current_content_version > source_version_at_translation`).
     - Si un grupo ha sido editado por redactores tras la migración, el rollback estándar (`force = false`) es bloqueado automáticamente para evitar pérdida accidental del trabajo editorial posterior.
     - Posibilidad de forzado explícito (`force = true`) por parte del administrador cuando se requiere reversión incondicional.
  3. **Atomicidad de Locks con Fast-Path `add_option`:**
     - Endurecimiento de `MigrationExecutionLock::acquire()` implementando inserción atómica directa vía `add_option()`.
     - Garantía de exclusión mutua a nivel de almacenamiento MySQL, eliminando carreras en adquisición en frío entre múltiples workers concurrentes.
  4. **Inspección de Entorno y Salvaguarda de Producción:**
     - En conformidad con las precondiciones de la Directiva DT-TFML-037, se formaliza la regla de detención si no existe un clon independiente de staging provisto.
     - La validación integral del motor se ejecuta en laboratorio sintético aislado con fixtures efímeros, preservando la base de datos viva de `cms.ecoterra` intacta.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.7 — Validación integral del migrador en entorno aislado y reversibilidad.
- **JUSTIFICACIÓN:** Toda migración debe ser inherentemente reversible sin dejar basura residual en TFML ni dañar las entidades del sitio origen, proporcionando un canal de marcha atrás seguro si se detectan anomalías tras la migración.
- **CONSECUENCIAS:** Seguridad operacional garantizada: la migración puede ejecutarse sabiendo que existe un mecanismo formal y probado de reversión total sin impacto en WordPress Core ni en WPML.

---

### ADR-034: Preparación y Certificación de Laboratorio Aislado, Acreditación de Propiedad en Rollback y Salvaguarda de Datos Reales
- **DECISIÓN:**
  1. **Aislamiento Técnico del Laboratorio Staging (`lab.ecoterra` y `cms_ect_lab`):**
     - Creación de un entorno de ejecución completamente desacoplado del sitio de producción `cms.ecoterra`:
       - Directorio propio e independiente: `d:\laragon\www\lab.ecoterra`.
       - Base de datos MySQL exclusiva e independiente: `cms_ect_lab` (separada de `cms_ect` y de cualquier base de datos de otros proyectos).
       - Archivo `wp-config.php` propio con credenciales y sales criptográficas exclusivas.
       - URL local soberana: `http://lab.ecoterra.test`.
       - Bloqueo total de comunicaciones externas salientes: `WP_HTTP_BLOCK_EXTERNAL = true`, `WP_ACCESSIBLE_HOSTS = localhost,127.0.0.1`.
       - Bloqueo estricto de envíos de correo: filtro prioritario en `pre_wp_mail` forzado a `false`.
       - Protección contra indexación: `blog_public = 0`, cabecera HTTP `X-Robots-Tag: noindex, nofollow, noarchive` y meta robots.
       - Desactivación de ejecución en background y auto-actualizaciones: `DISABLE_WP_CRON = true`, `DISALLOW_FILE_EDIT = true`, `AUTOMATIC_UPDATER_DISABLED = true`.
  2. **Auditoría Avanzada de Propiedad de Ejecución en el Motor de Rollback:**
     - Implementación del registro de manifiesto de ejecución (`tfml_migration_manifest_{execution_id}`) que almacena formalmente la lista estricta de IDs de grupos creados durante una corrida (`imported_group_ids`).
     - `WpmlMigrationRollbackService::inspect_dependencies()` valida la titularidad: si un grupo no fue creado por la ejecución en cuestión, es clasificado como `not_owned` y bloqueado.
     - **Inmunidad de Grupos Preexistentes y Ajenos frente a `force = true`:** La bandera `$force = true` únicamente anula bloqueos por modificaciones editoriales posteriores (`editorial_edits`) sobre grupos legítimamente propios, pero **NUNCA** permite eliminar grupos no pertenecientes a la ejecución ni grupos preexistentes (`not_owned`).
  3. **Salvaguarda Soberana del Sitio Origen y Protocolo de Copia:**
     - La base de datos original `cms_ect` permanece como fuente estrictamente de solo lectura (`0 INSERT / UPDATE / DELETE`).
     - Se prohíbe taxativamente la copia de tablas reales de producción a `cms_ect_lab` sin la autorización previa y expresa del usuario.
     - La Fase 3.8 queda certificada con esquemas limpios y datos sintéticos en el laboratorio, preparando la infraestructura técnica para la fase subsiguiente de respaldo y copia consentida.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.8 — Preparación y certificación del laboratorio aislado.
- **JUSTIFICACIÓN:** Validar migraciones con datos reales exige un entorno estéril, contenido y protegido contra filtraciones (correos a clientes reales, webhooks o indexación), junto con un motor de reversión blindado contra la eliminación accidental de datos de otras operaciones.
- **CONSECUENCIAS:** Entorno seguro y reproducible listo para recibir datos reales una vez autorizados, con garantías matemáticas de que el rollback no destruirá grupos preexistentes ni sobrepasará los límites de su propia ejecución.

---

### ADR-035: Preflight Dinámico, Aislamiento Físico de Código, Respaldo Verificable y Clonación Anonimizada en Laboratorio
- **DECISIÓN:**
  1. **Aislamiento Físico del Repositorio de Código (Retiro de Reparse Point / Junction NTFS):**
     - Retiro seguro del enlace NTFS junction (`cmd /c rmdir`) en `d:\laragon\www\lab.ecoterra\wp-content\plugins\tf-multilingual` para prevenir cualquier afectación cruzada accidental al árbol de trabajo de desarrollo.
     - Despliegue de un árbol físico de código completamente independiente mediante clon y checkout fijado estrictamente en el baseline certificado `af46d65a226568e6653549ed4f1eea0956189a44`.
     - Autonomía de dependencias mediante autoloader local de Composer en el laboratorio aislado.
  2. **Descubrimiento Dinámico de Prefijo y Estructuras:**
     - Prohibición estricta de asumir prefijos estándar `wp_`.
     - Descubrimiento programático del prefijo real del sitio origen (`etk_t_`) desde `wp-config.php` y comprobación de las 72 tablas del esquema.
     - Configuración consistente de `$table_prefix = 'etk_t_';` en el `wp-config.php` del laboratorio `lab.ecoterra`.
  3. **Respaldo Streaming de Solo Lectura y Protección contra Acceso HTTP:**
     - Extracción streaming de bajo consumo de memoria (MYSQLI_USE_RESULT) de las 22 tablas relacionales de WPML (`etk_t_icl_*`), 5 tablas de contenido de WordPress Core (`etk_t_posts`, `etk_t_postmeta`, `etk_t_terms`, `etk_t_term_taxonomy`, `etk_t_term_relationships`) y la tabla de configuración (`etk_t_options`).
     - Almacenamiento del respaldo SQL en directorio protegido `d:\laragon\www\lab.ecoterra\backups\preflight_backup_cms_ect_snapshot.sql`, estrictamente fuera del repositorio Git del plugin y con `.gitignore` propio.
     - Blindaje integral del directorio de respaldos contra peticiones web directas mediante salvaguardas concurrentes: `.htaccess` (`Require all denied` y `Deny from all`), `index.php` con respuesta forzada HTTP 403 Forbidden, y `web.config` (`<deny users="*"/>` para entornos IIS).
  4. **Anonimización Estricta, Sanitización Profunda y Blindaje en `cms_ect_lab` (DT-TFML-039A):**
     - Purgado total de usuarios productivos (`etk_t_users` y `etk_t_usermeta`). Generación de un único usuario administrador sintético de pruebas: `admin_lab` (`admin@lab.ecoterra.test`).
     - Anonimización profunda de pedidos y metadatos de clientes en `etk_t_postmeta`: campos `_billing_%` y `_shipping_%` sobrescritos con datos ficticios (`_billing_first_name = 'Lab'`, `_billing_email = 'customer@lab.ecoterra.test'`, etc.).
     - Sanitización universal de correos electrónicos en `etk_t_postmeta` (incluyendo arrays serializados PHP con recálculo de longitudes y JSON de `_elementor_data`), reescritos a `@lab.ecoterra.test`.
     - Anonimización de correos electrónicos en `etk_t_posts` (`post_content` y `post_excerpt`), eliminando direcciones corporativas o personales en páginas legales y formularios de contacto.
     - Purgado de claves API, tokens y secretos de opciones en `etk_t_options` (`stripe_%`, `paypal_%`, `%token%`, `%secret%`, etc.).
     - Exclusión confirmada de la tabla `etk_t_comments` del entorno de laboratorio.
     - Validación matemática de invariancia de WPML en `cms_ect_lab`: exactamente 3.403 filas en `etk_t_icl_translations` con MD5 `4241ca7e7ec6399a594537cb04790c10`.
  5. **Trazabilidad y Versionado Seguro de Herramientas de Laboratorio (DT-TFML-039A):**
     - Configuración de `.gitignore` para registrar formalmente los scripts certificados de la fase: `scratch/verify_fase_3_9.php`, `scratch/clonar_a_lab_aislado.php`, `scratch/preflight_discovery.php` y `scratch/ExternalPluginIsolationGuard.php`.
     - Parametrización segura de credenciales de base de datos vía variables de entorno (`TFML_DB_*`) con valores predeterminados seguros para desarrollo local, sin credenciales de producción ni datos reales embebidos en el repositorio.
  6. **Frontera Estricta de Fase (Migración WPML → TFML No Ejecutada):**
     - Creación e inicialización del esquema TFML (`etk_t_tfml_*`) en `cms_ect_lab` con conteo verificado de 0 filas.
     - Prohibición categórica de ejecutar la migración transaccional en la Fase 3.9; la ejecución con datos reales queda reservada exclusivamente para la Fase 3.10.
     - Cero escrituras en la base de datos de producción `cms_ect` (Delta = 0 verificado por centinela pre y post-flight).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.9 / Directiva DT-TFML-039A — Cierre correctivo de preflight, aislamiento físico, respaldo, anonimización profunda y clonación controlada.
- **JUSTIFICACIÓN:** El paso a pruebas con datos relacionales reales requiere aislamiento absoluto de código, respaldo blindado contra acceso web, anonimización profunda en metadatos y contenido para garantizar la privacidad, y versionado formal de scripts para asegurar la reproducibilidad de las pruebas sin exponer credenciales.
- **CONSECUENCIAS:** El laboratorio `lab.ecoterra` cuenta con una réplica exacta, completamente anonimizada y fiel de los 3.403 registros de WPML sobre base de datos `cms_ect_lab`, código físico independiente, respaldo blindado y herramientas versionadas, sin que se haya alterado un solo byte en la producción `cms_ect`.

---

### ADR-036: Reubicación de Respaldo Fuera de DocumentRoot, Certificación de Restauración Verificable, Corrección de Divergencias WPML y Planificación Dry-Run Piloto (Fase 3.10)
- **DECISIÓN:**
  1. **Reubicación Física del Respaldo SQL Fuera de DocumentRoot:**
     - Traslado permanente del snapshot SQL `preflight_backup_cms_ect_snapshot.sql` (17.19 MB, SHA-256 `f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a`) al directorio del sistema operativo `d:\laragon\backup\tfml_staging\`, ubicado de forma estricta fuera de `d:\laragon\www` (DocumentRoot del servidor web).
     - Eliminación del archivo `.sql` dentro del DocumentRoot de `lab.ecoterra\backups\`, conservando las barreras de protección de carpeta contra peticiones HTTP (`.htaccess` con `Require all denied`, `index.php` con 403 Forbidden y `web.config` con `<deny users="*"/>`).
  2. **Certificación de Restauración Verificable en Base Temporal:**
     - Implementación y ejecución del procedimiento automatizado `scratch/test_restore_verify.php`, que recrea una base de datos efímera (`cms_ect_restore_verify`), ejecuta la restauración de las 154 sentencias DDL/DML en 3.58 segundos, valida la integridad criptográfica idéntica de las 28 tablas (3.403 registros WPML con checksum MD5 `4241ca7e7ec6399a594537cb04790c10`, 4.021 posts y 82 términos), y destruye limpiamente la base temporal al culminar la certificación.
  3. **Corrección de Divergencia en WPML Migration Executor (`validate_plan` vs `element_type`):**
     - En `WpmlMigrationExecutor::validate_plan()`, se corrigió la consulta de verificación de cambios sobre `icl_translations` para incorporar el discriminador de tipo `AND element_type = %s` (`post_` + subtype, `tax_` + subtype o tipo nativo). Esta modificación previene falsas alarmas de divergencia en TRID causadas por colisiones naturales de IDs de elementos entre posts, taxonomías y comentarios en WordPress Core.
  4. **Resiliencia ante Taxonomías No Registradas en Contextos de CLI / Aislamiento:**
     - En `WordPressElementValidator::validate_term()`, cuando taxonomías personalizadas no registradas por hooks nativos de WordPress en entornos de CLI (como `tours_cat` o `product_type`) retornan `WP_Error('invalid_taxonomy')`, se implementó una consulta directa de respaldo a `$wpdb->term_taxonomy` para confirmar la existencia física real de la taxonomía y el término en la base de datos sin requerir el arranque completo de plugins de terceros.
  5. **Inventario Determinista y Generación de Plan de Migración Piloto (Dry-Run):**
     - Ejecución del planificador determinista sobre los datos reales de WPML en `cms_ect_lab`, confirmando concordancia matemática idéntica con el inventario de la Fase 3.4 (Delta = 0 en las 6 categorías): 249 grupos totales analizados (120 IMPORTABLE, 52 BLOCKED_BY_LANGUAGE por `fr`, 24 SKIPPED, 53 ORPHAN, 0 CONFLICT) y 3.033 adjuntos bajo `MEDIA_MODEL_B_DEDUPLICATION`. Huella digital SHA-256 determinista inmutable: `08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`.
     - El plan permanece en `STATUS_NEEDS_CONFIGURATION` reteniendo el francés (`fr`) sin auto-registro forzado, respetando el control editorial y la gobernanza de idiomas.
  6. **Retención Estricta de Migración Efectiva (Cero Escrituras en TFML):**
     - Durante la Fase 3.10 se ejecutó exclusivamente la validación formal del plan (`validate_plan`), resultando 100% válida con 0 errores.
     - Cero invocaciones a `execute_plan()`; las 5 tablas propias de TFML (`etk_t_tfml_*`) permanecen con exactamente 0 filas.
     - Cero modificaciones sobre la base productiva `cms_ect` (Delta = 0 verificado por centinela pre y post-flight).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.10 / Directiva DT-TFML-040 — Preflight final y planificación de migración piloto WPML → TFML.
- **JUSTIFICACIÓN:** Garantizar la seguridad total del respaldo trasladándolo fuera de cualquier directorio web, demostrar la recuperabilidad mediante restauración verificable en base de datos temporal, afinar la detección de divergencias en WPML y taxonomías, y generar un plan de migración matemáticamente determinista y validado al 100%, manteniendo las tablas TFML en cero escrituras hasta la autorización formal de la ejecución piloto.
- **CONSECUENCIAS:** El laboratorio `cms_ect_lab` y las herramientas de migración quedan completamente certificados, respaldados, probados contra reversión y listos para la autorización y ejecución del lote piloto real en la fase posterior.

---

### ADR-037: Gobernanza de Planes Incompletos, Derivación Criptográfica de Planes Piloto/Parciales y Preparación de Ejecución Piloto Transaccional (Fase 3.11)
- **DECISIÓN:**
  1. **Auditoría y Blindaje del Contrato de `STATUS_NEEDS_CONFIGURATION` en el Ejecutor:**
     - Se establece la regla soberana de que un plan de migración con estado `NEEDS_CONFIGURATION` (o `BLOCKED`) **NUNCA** puede ser ejecutado directamente en su totalidad por `WpmlMigrationExecutor::execute_batch()` o `MigrationManager::execute_plan()`.
     - La presencia de grupos importables en un plan no confiere autorización implícita para ignorar dependencias lingüísticas no configuradas (`fr`). Todo intento de ejecutar un plan incompleto es rechazado de inmediato arrojando `MigrationPlanValidationException`.
  2. **Mecanismo Formal de Derivación de Planes Parciales / Piloto (`derive_partial_plan`):**
     - Se implementa el método soberano `MigrationPlan::derive_partial_plan(array $selected_trids, string $label = 'pilot')`:
       - Preserva intacto e inmutable el plan matriz original (sus 249 grupos, sus clasificaciones y su huella SHA-256 `08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`).
       - Valida que todos los TRIDs solicitados existan en el plan matriz y pertenezcan estrictamente a la clasificación `CLASSIFICATION_IMPORTABLE` (rechazando con `InvalidArgumentException` cualquier grupo bloqueado, huérfano o excluido).
       - Otorga al plan derivado el estado `STATUS_READY`, con dependencias de idioma vacías (`missing_languages = []`) ya que sus elementos solo utilizan idiomas activos (`es` y `en`).
       - Establece un linaje criptográfico verificable vinculando `parent_fingerprint` con la huella del plan matriz, y marcando `is_partial = true`.
       - Genera su propia huella digital SHA-256 inmutable calculada deterministamente sobre sus grupos seleccionados ordenados por TRID ascendente.
  3. **Selección del Lote Piloto Representativo (5 grupos / 9 elementos):**
     - Se selecciona un lote piloto calibrado y verificable de 5 grupos editoriales reales:
       1. `TRID 4575`: Término de taxonomía `tours_cat`, Canónico ID 41 (`es`), Traducción ID 47 (`en`).
       2. `TRID 9238`: Término de taxonomía `tours_cat`, Canónico ID 46 (`es`), Traducción ID 48 (`en`).
       3. `TRID 9239`: CPT `tours`, Canónico ID 5286 (`es`), Traducción ID 5287 (`en`).
       4. `TRID 9247`: CPT `tours`, Canónico ID 5303 (`es`), Traducción ID 5304 (`en`).
       5. `TRID 4338`: Post type `page`, Canónico ID 4338 (`es`).
     - Huella digital SHA-256 del plan piloto: `30742abbc3af9267c826aef6c24875756e829971df65993553ec75d2aa55f711`.
  4. **Comprobación Previa de Correspondencia Core y WPML:**
     - Verificación exhaustiva de que los 5 TRIDs existen en `etk_t_icl_translations`, que los posts 5286, 5287, 5303, 5304, 4338 existen en `etk_t_posts` con estado activo (no papelera), y que los términos 41, 47, 46, 48 existen físicamente en `etk_t_terms` y `etk_t_term_taxonomy`.
  5. **Validación Dry-Run del Lote Piloto y Retención de Escrituras:**
     - Validación formal mediante `WpmlMigrationExecutor::validate_plan()` certificada al 100% (0 errores).
     - Retención absoluta de llamadas a `execute_plan()`; las 5 tablas maestras `etk_t_tfml_*` en `cms_ect_lab` permanecen con exactamente 0 registros.
     - Cero escrituras en `cms_ect` (Centinela WPML pre y post-flight verificado con Delta = 0 absoluto).
  6. **Infraestructura de Checkpoints, Manifiestos y Protección de Rollback:**
     - Checkpoint configurado y serializable para el lote piloto de 5 elementos.
     - Protección de reversión validada: todo grupo no registrado en el manifiesto de la ejecución es estrictamente bloqueado como `not_owned`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.11 / Directiva DT-TFML-041 — Preparación de ejecución piloto transaccional.
- **JUSTIFICACIÓN:** Separar formalmente el inventario matriz incompleto del plan piloto ejecutable previene ejecuciones inadvertidas de datos con dependencias no resueltas, garantiza la trazabilidad criptográfica completa de cualquier subconjunto y deja preparado un lote piloto representativo, verificado y reversible.
- **CONSECUENCIAS:** El sistema cuenta con capacidad formal para derivar planes parciales auditables y autorizados, manteniendo intacto el inventario general y garantizando que la ejecución piloto real solo procederá tras la autorización expresa del usuario.

---

### ADR-038: Ejecución Piloto Transaccional WPML → TFML en Laboratorio Real Aislado, Idempotencia Criptográfica y Reversibilidad Completa (Fase 3.12)
- **DECISIÓN:**
  1. **Ejecución Piloto Autorizada en Laboratorio Aislado (`cms_ect_lab`, prefijo `etk_t_`):**
     - Ejecución real transaccional acotada estrictamente al lote `pilot-batch-1` (5 grupos de traducción y 9 elementos editoriales reales):
       1. `TRID 4575`: Taxonomía `tours_cat`, Canónico ID 41 (`es`), Traducción ID 47 (`en`).
       2. `TRID 9238`: Taxonomía `tours_cat`, Canónico ID 46 (`es`), Traducción ID 48 (`en`).
       3. `TRID 9239`: CPT `tours`, Canónico ID 5286 (`es`), Traducción ID 5287 (`en`).
       4. `TRID 9247`: CPT `tours`, Canónico ID 5303 (`es`), Traducción ID 5304 (`en`).
       5. `TRID 4338`: Post type `page`, Canónico ID 4338 (`es`).
     - Huella SHA-256 certificada: `30742abbc3af9267c826aef6c24875756e829971df65993553ec75d2aa55f711`.
     - Resultado en tablas TFML: exactamente 5 filas en `etk_t_tfml_groups` y 9 filas en `etk_t_tfml_group_elements`.
  2. **Aislamiento Total de Almacenamiento y Opciones de WordPress:**
     - Enrutamiento dinámico de `$GLOBALS['wpdb']` a la instancia de laboratorio (`$lab_wpdb`) durante las operaciones de migración para garantizar que las opciones de WordPress (`update_option`, `get_option`, `delete_option`) empleadas por checkpoints (`MigrationCheckpointRepository`), bloqueos (`MigrationExecutionLock`) y manifiestos (`WpmlMigrationRollbackService`) se almacenen estrictamente en `cms_ect_lab.etk_t_options`.
     - Cero escrituras en la base de datos de origen `cms_ect` (Delta = 0 verificado por centinela pre y post-flight con 3.403 registros y MD5 `4241ca7e7ec6399a594537cb04790c10`).
  3. **Adopción de Criterios Explícitos de Fallo y Pruebas Negativas (Adenda DT-TFML-042-A):**
     - Incorporación formal de criterios explícitos de detención inmediata (STOP/FAIL) y matriz estándar de resultados (`PASS`, `FAIL_RECOVERED`, `FAIL_UNRECOVERED`, `BLOCKED`, `NOT_EXECUTED`) en `docs/GOBERNANZA.md`.
     - Verificación automatizada de rechazos:
       - N1: Ejecutor no autorizado bloqueado (`MigrationAuthorizationException`).
       - N2: Plan con huella SHA-256 manipulada rechazado tajantemente (`MigrationPlanValidationException`).
       - N3: Plan matriz en `STATUS_NEEDS_CONFIGURATION` bloqueado para ejecución directa.
       - N4: Intento de derivar grupos bloqueados por idiomas faltantes (`fr`) rechazado.
       - N5: Rollback sobre grupos no pertenecientes al manifiesto bloqueado como `not_owned`.
  4. **Idempotencia Estricta Demostrada en Base de Datos Real:**
     - Re-ejecución del mismo plan piloto sobre las tablas ya pobladas:
       - Cero grupos nuevos y cero elementos duplicados creados.
       - Los 5 grupos son reportados formalmente con estado `idempotent_skipped`.
       - Conteo en base de datos inmutable (5 grupos y 9 elementos permanecen constantes).
  5. **Reversibilidad Total Transaccional (Rollback Completo y Limpieza de Manifiesto):**
     - Ejecución de `WpmlMigrationRollbackService::rollback_execution()` sobre el reporte piloto:
       - Eliminación atómica y limpia de los 5 grupos y 9 elementos creados en TFML.
       - Las 5 tablas TFML (`etk_t_tfml_*`) son restauradas exactamente a 0 registros.
       - Tablas de WordPress Core (`etk_t_posts` con 4.021 filas y `etk_t_terms` con 82 filas) y de WPML (`etk_t_icl_translations` con 3.403 filas) permanecen 100% inalteradas.
       - Eliminación automática del manifiesto de ejecución y checkpoint de la tabla de opciones.
  6. **Capacidad de Recuperación y Re-ejecución Post-Rollback:**
     - Tras el rollback, la re-ejecución del plan piloto reproduce fielmente los 5 grupos y 9 elementos con 100% de integridad y conciliación verificada, demostrando la capacidad de recuperación total del sistema.
  7. **Renovación Automática de Checkpoint Completado en Ejecutor:**
     - Actualización en `WpmlMigrationExecutor::execute_batch()` para reiniciar un checkpoint limpio si el almacenado en base de datos ya tiene estado `STATUS_COMPLETED`, permitiendo re-ejecuciones idempotentes y corridas de validación sin interferencias residuales.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.12 / Directiva DT-TFML-042 y Adenda DT-TFML-042-A — Ejecución piloto transaccional WPML → TFML.
- **JUSTIFICACIÓN:** Certificar la primera migración real de contenidos con datos de WPML exige comprobar experimentalmente en un entorno aislado no solo que la importación produce los datos correctos, sino que la idempotencia previene duplicaciones, el rollback devuelve el estado a cero sin dañar el Core ni WPML, y el sistema puede recuperarse y re-ejecutarse deterministamente.
- **CONSECUENCIAS:** La arquitectura transaccional de TF Multilingual queda formalmente validada y certificada con datos reales: el plugin es capaz de migrar posts y términos de WPML, garantizar idempotencia, deshacer la operación limpiamente sin tocar Core ni WPML, y recuperarse ante fallos.

---

### ADENDA ADR-038-A: Cierre Correctivo de Fase 3.12, Auditoría Estricta de Esquema y Matriz Integral de Pruebas Negativas (DT-TFML-042B)
- **DECISIÓN:**
  1. **Auditoría de Esquema y Justificación de `SchemaManager::install()` en el Laboratorio:**
     - **Causa Raíz:** En la definición canónica DDL de `SchemaManager::get_schema_ddl()`, la tabla `tfml_group_elements` incorporó formalmente la columna `updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. En el laboratorio `cms_ect_lab`, instanciado en fases previas (3.8/3.9), dicha columna no se había sincronizado en base de datos.
     - **Intervención y Consecuencias:** La ejecución de `SchemaManager::install()` invocó `dbDelta()`, aplicando de forma nativa e inocua la adición de la columna `updated_at` a `etk_t_tfml_group_elements` y actualizando la opción `tfml_schema_version` a `1.0.0`.
     - **Comparativa de Esquema (5 tablas TFML):**
       - `etk_t_tfml_groups`: Sin cambios estructurales (6 columnas).
       - `etk_t_tfml_group_elements`: Actualizada con columna `updated_at` (+1 columna, total 6 columnas), alineándose al 100% con el estándar canónico del plugin.
       - `etk_t_tfml_media_translations`, `etk_t_tfml_strings`, `etk_t_tfml_string_translations`: Estructura idéntica y canónica.
     - **Inviolabilidad de Tablas Ajenas:** CERO alteraciones sobre tablas de WordPress Core (`etk_t_posts`, `etk_t_terms`, `etk_t_postmeta`, `etk_t_term_taxonomy`, `etk_t_term_relationships`) y de WPML (`etk_t_icl_*`).
     - **Eliminación Definitiva de TRUNCATE:** Se documenta que los comandos `TRUNCATE` ejecutados durante la etapa exploratoria previa formaron parte del desarrollo inicial de fixtures. En el protocolo de cierre DT-TFML-042B se erradica cualquier vaciado o reset de laboratorio: los 5 grupos y 9 elementos permanecen preservados físicamente.
  2. **Matriz Completa de Pruebas Negativas y Criterios de Fallo (NEG-01 a NEG-13):**
     - Se certifica la totalidad de los 13 escenarios negativos previstos en la Adenda DT-TFML-042-A mediante pruebas automatizadas y aisladas:
       | ID | Criterio de Seguridad / Fallo | Método / Script | Resultado | Evidencia Verificable |
       |---|---|---|---|---|
       | **NEG-01** | Desviación de conexión hacia base no autorizada | `scratch/verify_fase_3_12.php` | **PASS** | Bloqueo preventivo en preflight ante `$wpdb->dbname !== 'cms_ect_lab'` |
       | **NEG-02** | Ejecución por worker o ejecutor no autorizado | `WpmlMigrationExecutor::execute_batch()` | **PASS** | Arroja `MigrationAuthorizationException` antes de validar o adquirir locks |
       | **NEG-03** | Plan con huella SHA-256 adulterada o manipulada | `WpmlMigrationExecutor::validate_plan()` | **PASS** | Arroja `MigrationPlanValidationException` con detalle del error de huella |
       | **NEG-04** | Ejecución directa de plan matriz incompleto (`STATUS_NEEDS_CONFIGURATION`) | `WpmlMigrationExecutor::execute_batch()` | **PASS** | Bloqueado incondicionalmente por estado lingüístico incompleto (`fr`) |
       | **NEG-05** | Intento de derivar grupos con idiomas bloqueados/faltantes (`fr`) | `WpmlMigrationPlanner::derive_partial_plan()` | **PASS** | Arroja `InvalidArgumentException` protegiendo la gobernanza lingüística |
       | **NEG-06** | Inclusión de TRID ajeno al lote piloto autorizado o inexistente | Filtro de gobernanza / `derive_partial_plan()` | **PASS** | Rechazo tajante de TRID 4576 no listado en el lote autorizado |
       | **NEG-07** | Intento de ejecución concurrente bajo lock activo de otro worker | `MigrationExecutionLock::acquire()` | **PASS** | Retorna `false` atómicamente; rechaza concurrencia con `MigrationConcurrencyException` |
       | **NEG-08** | Recuperación ante bloqueo con TTL vencido y control de fencing token | `MigrationExecutionLock::renew()` / `verify_ownership()` | **PASS** | Sobrescribe lock expirado; rechaza tokens espurios sobre lock vigente |
       | **NEG-09** | Rollback ante manifiesto adulterado o grupo ajeno a la ejecución | `WpmlMigrationRollbackService::rollback_groups()` | **PASS** | Bloquea la reversión reportando formalmente código `not_owned` |
       | **NEG-10** | Inyección de fallo a mitad de lote (Rollback transaccional) | `WpmlMigrationExecutor::execute_batch()` (mock fallo) | **PASS** | Arroja `MigrationExecutionException`, ejecuta `rollback_transaction()` y fija checkpoint en `failed` |
       | **NEG-11** | Capacidad de recuperación y trazabilidad tras fallo previo | `MigrationCheckpoint::get_errors()` | **PASS** | Historial de errores preservado con `item_id`, timestamp y mensaje para auditoría |
       | **NEG-12** | Idempotencia ante checkpoint completado previamente | `WpmlMigrationExecutor::execute_batch()` | **PASS** | Detección de `is_completed()` y renovación limpia a `pending` para re-evaluación |
       | **NEG-13** | Blindaje de registros preexistentes frente a rollback con `force = true` | `WpmlMigrationRollbackService::rollback_groups()` | **PASS** | Grupo preexistente 555 protegido incondicionalmente (`not_owned`), bandera `force` anulada |
  3. **Certificación del Estado Físico Preservado del Laboratorio (`cms_ect_lab`):**
     - **ID de Ejecución Oficial:** `pilot_final_qJ1bqJV9`.
     - **Manifiesto:** `tfml_migration_manifest_pilot_final_qJ1bqJV9` almacenado en `etk_t_options`, listando exactamente los grupos `[6, 7, 8, 9, 10]`.
     - **Checkpoint:** `tfml_migration_checkpoint_wpml` con estado `completed`, 5 ítems procesados, `last_processed_trid` = 9247 y 0 errores.
     - **Bloqueo (Lock):** Totalmente liberado (`is_locked = false`).
     - **Datos Persistidos en Base de Datos Real:**
       - `etk_t_tfml_groups`: exactamente 5 filas (IDs 6, 7, 8, 9, 10).
       - `etk_t_tfml_group_elements`: exactamente 9 filas (elementos 41 [es], 47 [en], 46 [es], 48 [en], 5286 [es], 5287 [en], 5303 [es], 5304 [en], 4338 [es]).
       - Tablas TFML restantes (`media_translations`, `strings`, `string_translations`): 0 filas.
     - **Integridad de Contenido Core y WPML en Laboratorio:** 4.021 posts, 82 terms y 3.403 registros WPML intactos con checksum MD5 `4241ca7e7ec6399a594537cb04790c10`.
     - **Centinela en Producción (`cms_ect`):** 3.403 registros y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0 verificado en pre y post-flight).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.12 / Directiva DT-TFML-042B — Cierre correctivo y certificación final de Fase 3.12.
- **JUSTIFICACIÓN:** Cumplir rigurosamente con los estándares de gobernanza y auditoría formal del proyecto, asegurando que ninguna discrepancia quede sin explicar, que cada criterio negativo cuente con evidencia empírica verificable y que el estado del laboratorio refleje con fidelidad matemática el éxito del lote piloto.
- **CONSECUENCIAS:** La Fase 3.12 queda cerrada y certificada de forma definitiva con calificación unánime de PASS en todos los controles técnicos, de seguridad y de gobernanza.

### ADR-039: Planificación Incremental de Migración WPML → TFML, Matriz de Estrés y Resiliencia en Fixtures Aislados, y Verificación de API Funcional (Fase 3.13)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Planificación y Catalogación Incremental de los 115 Grupos Restantes:**
     - A partir del plan matriz homologado con fingerprint SHA-256 `08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`, se extrajo el inventario determinista de los 115 grupos importables restantes (124 elementos) tras aislar el lote piloto inicial de Fase 3.12 (5 grupos, 9 elementos).
     - Se estructuró un catálogo exhaustivo de 9 lotes funcionales discretos, con dependencias ordenadas y huellas SHA-256 inmutables:
       - `batch-02-taxonomy-categories`: 3 grupos / 3 elementos (TRIDs 9237, 9367, 9532; taxonomías tours_cat y category; huella `9b76d7e38d779a08448474a9c22ef24b88dd3e65da292dbd64e338371a0e1d78`).
       - `batch-03-taxonomy-woocommerce`: 16 grupos / 16 elementos (taxonomías product_type, product_visibility; huella `5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945`).
       - `batch-04-taxonomy-tags-1`: 23 grupos / 23 elementos (taxonomía post_tag, parte 1; huella `b039d492f187b1fbe59b9089686c148110aa4d8ea0296f3208ea2809b3ed65f7`).
       - `batch-05-taxonomy-tags-2`: 22 grupos / 22 elementos (taxonomía post_tag, parte 2; huella `cd1c0018d1014c36c5430d1ae9aaf0272e32748b094379025fc01c99f3816e1c`).
       - `batch-06-elementor-templates`: 10 grupos / 11 elementos (elementor_library templates; huella `ee32aff6cc43ea1f334b758bda34d9a9cc271de3a3c76217abd56e6264924a24`).
       - `batch-07-cpt-tours`: 12 grupos / 20 elementos (CPT tours restantes; huella `d24b52d1224564bd745b4bc8032a9607adef5dc00379b2634a50eed08e77888b`).
       - `batch-08-cpt-products`: 7 grupos / 7 elementos (CPT product de WooCommerce; huella `3df3ce6f1a6dac619d203adaf5a31d8eb3fc332493330a5e8b755d4ff1d9136d`).
       - `batch-09-core-posts-forms`: 4 grupos / 4 elementos (post y wpcf7_contact_form; huella `6d188e1cbff392deaa030e917d5d43aaed482f915c218a73c87f415d39f53720`).
       - `batch-10-nav-menu-items`: 18 grupos / 18 elementos (nav_menu_item; huella `19b7f15c730a7f0059b7d6873a37b07ed2bcd63f499c5a30fc921ae5d4957acc`).
     - Suma total de los 9 lotes: exactamente 115 grupos y 124 elementos, con solapamiento cero entre TRIDs.
     - Ninguno de los 115 grupos fue ejecutado en la base de datos de laboratorio, garantizando el aislamiento estricto solicitado.
  2. **Matriz de Pruebas de Estrés, Concurrencia y Resiliencia en Fixtures Aislados (STR-01 a STR-09):**
     - Se crearon pruebas unitarias (`WpmlMigrationStressRecoveryTest.php`) e integradas sobre fixtures simulados y desacoplados de la base de datos real:
       - **STR-01 (Interrupción pre-commit):** Fallo simulado antes del commit ejecuta `rollback_transaction()` y libera automáticamente el lock sin dejar registros huérfanos.
       - **STR-02 (Interrupción post-commit y reanudación):** Ejecución por lotes parciales con checkpoint preservado permite reanudar sin duplicar inserciones.
       - **STR-03 (Expiración de lock y fencing token):** Bloqueo expirado desconoce al worker antiguo y permite la toma segura por un nuevo worker con renovación atómica.
       - **STR-04 (Checkpoints incompletos):** Serialización y persistencia de checkpoints parciales (`processed_items < total_items`) retienen `last_processed_trid` exacto.
       - **STR-05 (Reintento de lote duplicado):** Detección de entidades ya existentes reporta formalmente `idempotent_skipped` en lugar de error o re-inserción.
       - **STR-06 (Concurrencia atómica):** Worker secundario intentando ejecutar bajo lock activo es rechazado inmediatamente con `MigrationConcurrencyException`.
       - **STR-07 (Colisión relacional):** Detección durante `validate_plan()` de asignaciones contradictorias o parciales entre grupos aborta el plan con `MigrationPlanValidationException`.
       - **STR-08 (Manifiesto ajeno / despojado):** Rollback bloquea la reversión de grupos que no pertenecen al manifiesto de ejecución con código `not_owned`.
       - **STR-09 (Registro de auditoría y diagnóstico de fallo):** Checkpoint captura el código de TRID exacto, la marca temporal y el mensaje de error preservando el estado `failed`.
  3. **Verificación de Dominio y API Funcional sobre Datos Reales (`cms_ect_lab`):**
     - Se verificó en modo lectura sobre los 5 grupos y 9 elementos migrados en el laboratorio:
       - `ContentTranslationResolver::resolve_element_id()`: Resolución exacta y simétrica de CPT `tours` (ID 5286 [es] ↔ ID 5287 [en]), taxonomía `tours_cat` (ID 41 [es] ↔ ID 47 [en]; ID 46 [es] ↔ ID 48 [en]) y resolución idéntica (es → es).
       - `ContentTranslationResolver::resolve()`: Retorno de objetos canónicos `TranslationElement` tipados.
       - `ContentTranslationResolver::language_of()`: Determinación soberana del idioma a partir de la tabla relacional sin heurísticas de URL o postmeta.
       - Entidad monolingüe (Page 4338): Retorna `null` estricto al solicitar `en`, sin degradación o fallbacks espurios.
       - `UrlLanguageResolver`: Resolución correcta de rutas canónicas default sin prefijo (`/tours/...`), secundarias con prefijo (`/en/...`), preservación de rutas estándar (`/blog/...`) y rechazo de prefijos inactivos (`/fr/...`) con estado `STATUS_INACTIVE`.
       - Distinción arquitectónica CLI vs HTTP: Se certifica que los scripts corren en entorno CLI aislado, sin hooks webhooks salientes, cron o peticiones HTTP activas.
  4. **Preservación Inmutable de Estado y Centinelas:**
     - `cms_ect_lab`: Conserva estrictamente los 5 grupos (IDs 6, 7, 8, 9, 10) y 9 elementos del piloto de Fase 3.12, con las demás 3 tablas TFML en 0 filas y lock liberado.
     - `cms_ect`: Centinela intacto con 3.403 registros en `icl_translations` y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.13 / Directiva DT-TFML-043 — Migración incremental, pruebas de estrés y recuperación.
- **JUSTIFICACIÓN:** Establecer un catálogo matemático inmutable para los 115 grupos pendientes, someter la arquitectura de migración a pruebas extremas de concurrencia y recuperación en fixtures aislados sin tocar el laboratorio, y validar que la API funcional de TF Multilingual opera a la perfección sobre los datos ya importados.
- **CONSECUENCIAS:** Queda formalmente estructurada la secuencia de lotes para las fases subsecuentes, blindada la infraestructura de resiliencia ante cualquier interrupción o concurrencia, y validada al 100% la API soberana de traducción.

### ADR-040: Ejecución Transaccional del Segundo Lote Piloto (batch-02-taxonomy-categories), Reversibilidad Aislada y Certificación HTTP Real (Fase 3.14)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Gate de Reproducibilidad de Fase 3.13 Certificado:**
     - Se versionaron e incorporaron formalmente a Git los artefactos `scratch/verify_fase_3_13.php`, `scratch/incremental_batches_catalog.json` y `scratch/plan_incremental_fase_3_13.php` mediante el micro-commit `6f1f1b3`.
     - Re-ejecución al 100% de la suite de 99 controles de Fase 3.13 con resultado PASS.
     - Certificación de huella inmutable del Lote 2: `9b76d7e38d779a08448474a9c22ef24b88dd3e65da292dbd64e338371a0e1d78` y TRIDs `9237, 9367, 9532`.
  2. **Ejecución Transaccional Acotada al Lote 2 (`batch-02-taxonomy-categories`):**
     - Migración exitosa de 3 grupos y 3 elementos correspondientes a categorías (`tours_cat` y `category`) en `cms_ect_lab` (TRIDs 9237, 9367, 9532).
     - Resultado acumulado en la base de datos: exactamente 8 grupos en `etk_t_tfml_groups` (5 de piloto 1 + 3 de lote 2) y 12 elementos en `etk_t_tfml_group_elements` (9 de piloto 1 + 3 de lote 2).
     - Inalterabilidad de los 5 grupos originales (IDs 6, 7, 8, 9, 10). Tablas restantes de TFML (`media_translations`, `strings`, `string_translations`) se mantienen en 0 filas.
     - Registro de manifiesto de ejecución y actualización de checkpoint a `completed` con 3 ítems procesados. Lock liberado.
  3. **Idempotencia y Reversibilidad Aislada del Lote 2:**
     - **Idempotencia:** Re-ejecución del Lote 2 no genera duplicados; los 3 TRIDs reportan formalmente `idempotent_skipped` y los conteos de BD permanecen inmutables en 8 grupos y 12 elementos.
     - **Rollback Aislado:** `WpmlMigrationRollbackService::rollback_groups()` revierte exclusivamente los 3 grupos del Lote 2. La base de datos retorna a 5 grupos y 9 elementos, con los 5 grupos del piloto previo 100% blindados e intactos.
     - **Re-ejecución Posterior:** Re-ejecución del Lote 2 restaura el estado acumulado final de 8 grupos y 12 elementos con 100% de conciliación profunda sin discrepancias.
  4. **Certificación HTTP Real sobre Servidor Web (`http://lab.ecoterra.test`):**
     - Se configuró el VirtualHost de Apache y el enrutador `.htaccess` de WordPress en `d:\laragon\www\lab.ecoterra\`.
     - Se ejecutaron solicitudes HTTP reales mediante cURL:
       - `HTTP-01`: Ruta raíz (`/`) responde HTTP 200 OK en idioma predeterminado.
       - `HTTP-02`: Prefijo secundario (`/en/`) es procesado limpiamente sin errores 500.
       - `HTTP-03`: Prefijo inactivo (`/fr/`) es procesado limpiamente sin errores 500 ni degradación silenciosa.
       - `HTTP-04`: Solicitudes a entidades reales de contenido migrado:
         - Página 4338 (Lote 1): `/?page_id=4338` responde HTTP 301 redirigiendo canónicamente a `/old-blog/` (HTTP 200 OK).
         - Categoría 50 (Lote 2): `/?cat=50` responde HTTP 301 redirigiendo canónicamente a `/category/blog/` (HTTP 200 OK).
       - `HTTP-05`: Ausencia absoluta de bucles de redirección (`CURLE_TOO_MANY_REDIRECTS = false`).
       - `HTTP-06`: Cabecera de aislamiento `X-Robots-Tag: noindex, nofollow, noarchive` activa en todas las respuestas.
  5. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.14 / Directiva DT-TFML-044 — Segundo lote piloto y certificación HTTP.
- **JUSTIFICACIÓN:** Certificar la reproducibilidad de la Fase 3.13, avanzar con la migración controlada del Lote 2 demostrando rollback e idempotencia, y validar por primera vez el comportamiento integral del plugin sirviendo contenido real vía HTTP en WordPress.
- **CONSECUENCIAS:** El laboratorio cuenta ahora con 8 grupos y 12 elementos migrados, la capacidad de reversibilidad granular por lote queda empíricamente comprobada, y la respuesta HTTP de WordPress con TF Multilingual queda formalmente certificada sin errores 500 ni bucles.

### ADR-041: Saneamiento Integral del Catálogo Incremental, Partición Disjunta de TRIDs, Conciliación Anatómica de WooCommerce y Auditoría de Rutas Frontend (Fase 3.15)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Saneamiento y Reconstrucción Integral del Catálogo Incremental:**
     - Se auditó el plan matriz completo (249 grupos totales, 120 clasificados como `IMPORTABLE`, fingerprint `08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`).
     - Se verificó la partición matemática disjunta de los 9 lotes incrementales (lotes 02 al 10) que comprenden exactamente 115 grupos y 124 elementos.
     - Sumados a los 5 grupos del lote piloto inicial (`pilot-batch-1`), se cubre el 100% de los 120 grupos importables del universo WPML sin una sola omisión ni duplicado (Cero colisiones).
     - Se certificó que el 100% de los 124 elementos editoriales existen físicamente en las tablas nativas de WordPress Core (`etk_t_posts`, `etk_t_terms`, `etk_t_term_taxonomy`).
     - Se generó el catálogo enriquecido `scratch/incremental_batches_catalog.json` y el desglose relacional exhaustivo `scratch/incremental_batches_catalog_detailed.json`.
  2. **Conciliación Anatómica Certificada del Lote 3 (`batch-03-taxonomy-woocommerce`):**
     - Se subsanó la discrepancia textual originada por la errata de recomendación previa (`product_tag`, `pa_attribute`), y se aclaró la confusión sobre los TRIDs 9342..9357 (correspondientes a adjuntos de medios `post_attachment`).
     - Se certificó la lista formal e inmutable de 16 TRIDs: `[4578, 4579, 4580, 4581, 4607, 4614, 4615, 4616, 4617, 4618, 4619, 4620, 4621, 4622, 4638, 9544]`.
     - Desglose verificado por subtipos en base de datos:
       - 5 grupos de `product_type` (TRIDs 4578 al 4581, 4607).
       - 9 grupos de `product_visibility` (TRIDs 4614 al 4622).
       - 2 grupos de `product_cat` (TRIDs 4638, 9544).
     - Huella criptográfica inmutable ratificada: `5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945`.
  3. **Auditoría de Gobernanza de Rutas Frontend y Frontera de Responsabilidad:**
     - **Ruta `/en/` (404 Not Found):** Se determinó que la página frontal configurada (`page_on_front = 197`, "Inicio") posee traducción al inglés (Post 8356, "Home"), pero pertenece al TRID 197 bloqueado por francés (`BLOCKED_BY_LANGUAGE`). Al no estar migrado a TFML y al operar WordPress en Core limpio sin reglas de inicio traducidas, WordPress Core responde limpiamente `404 Not Found`.
     - **Ruta `/fr/` (404 Not Found):** Confirmación de que el `200 OK` del informe anterior fue una errata clerical de transcripción. La ruta respondió `404 Not Found` en la ejecución real. Al no generarse reglas de reescritura para idiomas inactivos, WordPress Core rechaza la URL sin degradación a default.
     - **Renderizado de HTML (Clasificado estrictamente como BLOCKED):** Se identificó que el directorio del tema `ecoCMS` no fue desplegado en el laboratorio aislado. Por ende, la renderización visual de etiquetas `<html lang="...">`, canónicas y hreflang en frontend queda catalogada honestamente como `BLOCKED` por ausencia de plantilla de presentación, sin falsear certificaciones inexistentes.
  4. **Preservación Inmutable de Estado y Restricción Estricta:**
     - El laboratorio `cms_ect_lab` retiene exactamente **8 grupos** y **12 elementos** acumulados.
     - El Lote 3 **no fue ejecutado** (permanece en estado `STATUS_READY`, a la espera de autorización formal).
     - Centinela en origen `cms_ect`: 3.403 filas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.15 / Directiva DT-TFML-045 — Saneamiento integral del catálogo y preparación del lote 3.
- **JUSTIFICACIÓN:** Sanear y validar formalmente la partición matemática completa de los lotes de migración, eliminar cualquier riesgo de desalineación de TRIDs antes de importaciones masivas, y delimitar con precisión técnica el estado del frontend de WordPress en el laboratorio.
- **CONSECUENCIAS:** El catálogo incremental queda 100% blindado y certificado contra la base de datos real, el Lote 3 queda plenamente conciliado y listo para ejecución segura, y se documenta formalmente la frontera entre la lógica de dominio multilingüe y las dependencias de tema del frontend.

### ADR-042: Migración Transaccional del Tercer Lote Piloto (batch-03-taxonomy-woocommerce), Idempotencia, Reversibilidad Aislada y Validación de API de Dominio (Fase 3.16)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Ejecución Transaccional del Lote 3 (`batch-03-taxonomy-woocommerce`):**
     - Se ejecutó la migración transaccional de los 16 TRIDs autorizados en el laboratorio aislado `cms_ect_lab`: `[4578, 4579, 4580, 4581, 4607, 4614, 4615, 4616, 4617, 4618, 4619, 4620, 4621, 4622, 4638, 9544]`.
     - Subtipos taxonómicos involucrados: 5 grupos de `product_type`, 9 grupos de `product_visibility` y 2 grupos de `product_cat` (16 grupos / 16 elementos).
     - Huella criptográfica inmutable ratificada: `5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945`.
     - Nuevo estado acumulado en base de datos del laboratorio: exactamente **24 grupos** en `etk_t_tfml_groups` (8 previos + 16 nuevos) y **28 elementos** en `etk_t_tfml_group_elements` (12 previos + 16 nuevos).
     - Asignación correlativa de IDs de grupo en `etk_t_tfml_groups`: IDs 41 al 56.
     - Registro de manifiesto transaccional y checkpoint actualizado a `completed` con 16 ítems procesados. Lock liberado.
  2. **Idempotencia Estricta en Base de Datos Real:**
     - Segunda ejecución consecutiva sobre el Lote 3 evalúa los 16 grupos sin realizar escrituras redundantes ni generar duplicados.
     - Los 16 grupos devuelven formalmente el estado `idempotent_skipped` (0 duplicados).
     - Los conteos de la base de datos se mantienen inmutables en exactamente 24 grupos y 28 elementos.
  3. **Reversibilidad Aislada y Re-ejecución Post-Rollback:**
     - Ejecución de `rollback_groups` sobre los IDs del Lote 3 (`[41, 42, ..., 56]`) revierte exclusivamente el tercer lote.
     - La base de datos regresa de manera exacta al estado de 8 grupos y 12 elementos.
     - Los 8 grupos iniciales correspondientes a los lotes 1 y 2 (`[6, 7, 8, 9, 10, 38, 39, 40]`) y sus 12 elementos permanecen 100% blindados e intactos.
     - Re-ejecución posterior restaura el estado acumulado final de 24 grupos y 28 elementos.
     - Verificación profunda mediante `reconcile()` certifica integridad estructural plena (`integrity_verified = true`, 0 discrepancias).
  4. **Validación de API de Dominio y Comprobación HTTP:**
     - Se verificó la resolución cruzada mediante `ContentTranslationResolver` sobre términos migrados (términos 2 y 97, resolución cruzada ES <-> EN).
     - Comprobación HTTP sobre `http://lab.ecoterra.test`: ruta raíz responde HTTP 200 OK; `/en/` responde 404 (sin home traducida migrada); `/fr/` responde 404 (idioma inactivo sin aliasing ni degradación a default); páginas y categorías migradas responden 301 canónico a 200 OK; ausencia de bucles.
     - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del directorio del tema `ecoCMS` en el laboratorio.
  5. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.16 / Directiva DT-TFML-046 — Migración transaccional del tercer lote piloto WooCommerce.
- **JUSTIFICACIÓN:** Ejecutar el tercer lote de migración incremental WPML -> TFML sobre taxonomías WooCommerce en laboratorio aislado, certificar idempotencia y rollback selectivo en entorno de persistencia real sin afectar lotes previos, y consolidar el estado de 24 grupos y 28 elementos.
- **CONSECUENCIAS:** El laboratorio avanza al estado acumulado de 24 grupos y 28 elementos, el motor demuestra una vez más su solidez transaccional y reversibilidad granular, y los 7 lotes restantes (99 grupos) permanecen intactos en estado `STATUS_READY`.

### ADR-043: Migración Controlada del Cuarto Lote (batch-04-taxonomy-tags-1), Trazabilidad Dinámica de IDs Autoincrementales, Idempotencia y Reversibilidad Aislada (Fase 3.17)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Ejecución Transaccional del Lote 4 (`batch-04-taxonomy-tags-1`):**
     - Se ejecutó la migración transaccional de los 23 TRIDs autorizados en el laboratorio aislado `cms_ect_lab`: `[9368, 9369, 9370, 9371, 9372, 9373, 9374, 9375, 9376, 9377, 9378, 9379, 9380, 9381, 9382, 9383, 9384, 9385, 9386, 9387, 9388, 9389, 9390]`.
     - Subtipo taxonómico involucrado: 23 grupos de etiquetas WordPress (`post_tag`) con cardinalidad de 23 grupos y 23 elementos.
     - Huella criptográfica inmutable ratificada: `b039d492f187b1fbe59b9089686c148110aa4d8ea0296f3208ea2809b3ed65f7`.
     - Nuevo estado acumulado en la base de datos del laboratorio: exactamente **47 grupos** en `etk_t_tfml_groups` (24 previos + 23 nuevos) y **51 elementos** en `etk_t_tfml_group_elements` (28 previos + 23 nuevos).
     - Registro de manifiesto transaccional y checkpoint actualizado a `completed` con 23 ítems procesados. Lock liberado.
  2. **Trazabilidad Dinámica de IDs Autoincrementales y Titularidad por Manifiesto:**
     - En conformidad con la directiva DT-TFML-047 y la auditoría de la Dirección Técnica, se eliminó cualquier suposición de que los IDs autoincrementales de MySQL sean correlativos, continuos o reutilizables tras operaciones de rollback.
     - La titularidad y delimitación de los grupos creados se determina exclusivamente a partir de los IDs devueltos por el ejecutor y persistidos en el manifiesto de ejecución (`save_execution_manifest`).
     - El conjunto previo de 24 grupos se identifica dinámicamente al inicio del ciclo y se verifica su inviolabilidad formal (100% preservados tras migración, idempotencia, rollback y re-ejecución).
  3. **Idempotencia Estricta en Base de Datos Real:**
     - Segunda ejecución consecutiva sobre el Lote 4 evalúa los 23 grupos sin realizar escrituras redundantes ni generar duplicados.
     - Los 23 grupos devuelven formalmente el estado `idempotent_skipped` (0 duplicados).
     - Los conteos de la base de datos se mantienen inmutables en exactamente 47 grupos y 51 elementos.
  4. **Reversibilidad Aislada y Re-ejecución Post-Rollback:**
     - Ejecución de `rollback_groups` sobre los IDs capturados en el manifiesto del Lote 4 revierte exclusivamente el cuarto lote.
     - La base de datos regresa de manera exacta al estado acumulado previo de 24 grupos y 28 elementos.
     - Los 24 grupos acumulados de los lotes 1, 2 y 3 permanecen 100% blindados e intactos.
     - Re-ejecución posterior restaura el estado acumulado final de 47 grupos y 51 elementos.
     - Verificación profunda mediante `reconcile()` certifica integridad estructural plena (`integrity_verified = true`, 0 discrepancias).
  5. **Validación de API de Dominio y Comprobación HTTP:**
     - Se verificó la resolución mediante `ContentTranslationResolver` sobre términos de etiquetas migradas (términos 51 'Cusco', 52 'Machu Picchu', 53 'Eco Lodges' en idioma `es`; resolución hacia `en` devuelve `null` sin fallback espurio).
     - Comprobación HTTP sobre `http://lab.ecoterra.test`: ruta raíz responde HTTP 200 OK; `/en/` responde 404 (sin home traducida migrada); `/fr/` responde 404 (idioma inactivo sin aliasing ni degradación a default); páginas y categorías de lotes previos responden 301 canónico a 200 OK; consultas de etiquetas migradas responden limpiamente (HTTP 200 o 301 canónico); ausencia de errores 500 ni bucles.
     - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del directorio del tema `ecoCMS` en el laboratorio.
  6. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.17 / Directiva DT-TFML-047 — Migración controlada del lote 4 (Etiquetas WordPress).
- **JUSTIFICACIÓN:** Ejecutar el cuarto lote de migración incremental WPML -> TFML sobre taxonomías de etiquetas estándar de WordPress en laboratorio aislado, certificar idempotencia y reversibilidad aislada sin tocar lotes previos, consolidar la gobernanza de IDs autoincrementales dinámicos, y avanzar el acumulado a 47 grupos y 51 elementos.
- **CONSECUENCIAS:** El laboratorio avanza al estado acumulado de 47 grupos y 51 elementos, se ratifica la resiliencia del motor transaccional ante contadores autoincrementales no consecutivos, y los 6 lotes restantes (76 grupos) permanecen intactos en estado `STATUS_READY`.

### ADR-044: Ratificación y Migración Controlada del Quinto Lote (batch-05-taxonomy-tags-2), Levantamiento de STOP Preventivo, Vinculación Estricta al Catálogo y Reversibilidad Aislada (Fase 3.18)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Activación Justificada de STOP y Regla de Vinculación Estricta al Catálogo:**
     - Durante el preflight inicial de DT-TFML-048 se detuvo inmediatamente la ejecución transaccional al detectar una divergencia entre el rango continuo descrito en el texto de la directiva (9391 a 9412) y el catálogo oficial de Fase 3.15.
     - Dicho rango continuo incluía 5 TRIDs ajenos (3 entradas y 2 adjuntos de medios) y omitía 5 etiquetas legítimas (TRIDs 9413 a 9417).
     - La Dirección Técnica ratificó el STOP preventivo mediante DT-TFML-048-R1 e instituyó la regla soberana de gobernanza: **Los TRID de cada lote se obtendrán siempre directamente del catálogo oficial versionado, prohibiéndose inferencias por rangos numéricos, secuencias aparentes o descripciones abreviadas.**
  2. **Ejecución Transaccional del Lote 5 (`batch-05-taxonomy-tags-2`):**
     - Se ejecutó la migración de los 22 TRIDs ratificados del catálogo oficial: `[9392, 9393, 9394, 9395, 9396, 9397, 9398, 9400, 9401, 9402, 9403, 9404, 9407, 9408, 9409, 9410, 9412, 9413, 9414, 9415, 9416, 9417]`.
     - Subtipo taxonómico involucrado: 22 grupos de etiquetas WordPress (`post_tag`) con cardinalidad de 22 grupos y 22 elementos.
     - Huella criptográfica inmutable ratificada: `cd1c0018d1014c36c5430d1ae9aaf0272e32748b094379025fc01c99f3816e1c`.
     - Nuevo estado acumulado en la base de datos del laboratorio: exactamente **69 grupos** en `etk_t_tfml_groups` (47 previos + 22 nuevos) y **73 elementos** en `etk_t_tfml_group_elements` (51 previos + 22 nuevos).
     - Registro de manifiesto transaccional y checkpoint actualizado a `completed` con 22 ítems procesados. Lock liberado.
  3. **Idempotencia Estricta en Base de Datos Real:**
     - Segunda ejecución consecutiva sobre el Lote 5 evalúa los 22 grupos sin realizar escrituras redundantes ni generar duplicados.
     - Los 22 grupos devuelven formalmente el estado `idempotent_skipped` (0 duplicados).
     - Los conteos de la base de datos se mantienen inmutables en exactamente 69 grupos y 73 elementos.
  4. **Reversibilidad Aislada y Re-ejecución Post-Rollback:**
     - Rollback selectivo mediante `rollback_groups` sobre los IDs del manifiesto del Lote 5 revierte exclusivamente el quinto lote.
     - La base de datos regresa de manera exacta al estado previo de 47 grupos y 51 elementos.
     - Los 47 grupos acumulados de los lotes 1 al 4 permanecen 100% blindados e intactos.
     - Re-ejecución posterior restaura el estado final de 69 grupos y 73 elementos.
     - Verificación profunda mediante `reconcile()` certifica integridad relacional al 100% (`integrity_verified = true`, 0 discrepancias).
  5. **Validación de API de Dominio y Comprobación HTTP:**
     - Se verificó la resolución mediante `ContentTranslationResolver` sobre términos de etiquetas migradas (términos 74, 75, 95 en idioma `es`; resolución hacia `en` devuelve `null` sin fallback espurio).
     - Comprobación HTTP sobre `http://lab.ecoterra.test`: ruta raíz responde HTTP 200 OK; `/en/` responde 404 (sin home traducida migrada); `/fr/` responde 404 (idioma inactivo sin aliasing ni degradación a default); páginas y categorías de lotes previos responden 301 canónico a 200 OK; consultas de etiquetas migradas responden limpiamente; ausencia de errores 500 ni bucles.
     - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del directorio del tema `ecoCMS` en el laboratorio.
  6. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.18 / Directivas DT-TFML-048 y DT-TFML-048-R1 — Migración controlada del lote 5 (Etiquetas WordPress, segunda parte).
- **JUSTIFICACIÓN:** Ejecutar el quinto lote de migración incremental WPML -> TFML sobre taxonomías de etiquetas estándar en laboratorio aislado, certificar el valor preventivo del mecanismo de STOP ante divergencias textuales, formalizar la regla de autoridad soberana del catálogo, y avanzar el acumulado a 69 grupos y 73 elementos.
- **CONSECUENCIAS:** El laboratorio avanza al estado acumulado de 69 grupos y 73 elementos (57,5% de los grupos importables del universo WPML), la gobernanza de catálogo queda formalmente blindada contra inferencias, y los 5 lotes restantes (54 grupos) permanecen intactos en estado `STATUS_READY`.

### ADR-045: Migración Controlada del Sexto Lote (batch-06-elementor-templates), Preservación Criptográfica de Metadatos y JSON Elementor, Resolución Bilingüe y Reversibilidad Aislada (Fase 3.19)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Ejecución Transaccional del Lote 6 (`batch-06-elementor-templates`):**
     - Se ejecutó la migración de los 10 TRIDs autorizados obtenidos exclusivamente del catálogo oficial de Fase 3.15: `[6, 1300, 1713, 1723, 2365, 3423, 4130, 4532, 4533, 9208]`.
     - Subtipos taxonómicos y tipos de post involucrados: exactamente 8 grupos de plantillas `elementor_library` (posts: 7 grupos monolingües [7 elementos] + 1 grupo bilingüe TRID 9208 [2 elementos] = 9 elementos) y 2 grupos de términos `elementor_library_type` (terms: 2 grupos monolingües = 2 elementos).
     - Cardinalidad verificada: exactamente 10 grupos y 11 elementos (incluye el primer grupo bilingüe de plantillas: TRID 9208 con Post 5048 en español y Post 5055 en inglés).
     - Huella criptográfica inmutable ratificada: `ee32aff6cc43ea1f334b758bda34d9a9cc271de3a3c76217abd56e6264924a24`.
     - Nuevo estado acumulado en la base de datos del laboratorio: exactamente **79 grupos** en `etk_t_tfml_groups` (69 previos + 10 nuevos) y **84 elementos** en `etk_t_tfml_group_elements` (73 previos + 11 nuevos).
     - Manifiesto oficial persistido y checkpoint completado con 10 ítems procesados. Lock liberado.
  2. **Preservación Criptográfica de Integridad Elementor (Delta Cero Absoluto):**
     - Se capturó un snapshot exhaustivo de solo lectura pre y post-migración sobre los 9 posts `elementor_library` y los 2 términos `elementor_library_type`.
     - Se certificó que las tablas nativas de WordPress (`etk_t_posts`, `etk_t_postmeta`, `etk_t_terms`, `etk_t_term_taxonomy`, `etk_t_term_relationships`) permanecen 100% idénticas (Delta = 0).
     - Se comprobó que el contenido JSON serializado en `_elementor_data` conserva exactamente sus hashes MD5 y bytes originales, sin reescrituras de configuración, metadatos ni atributos internos de Elementor.
  3. **Idempotencia y Reversibilidad Aislada en Base de Datos Real:**
     - Segunda ejecución consecutiva sobre el Lote 6 reportó 10 resultados `idempotent_skipped` (0 duplicados).
     - Rollback selectivo mediante `rollback_groups` sobre los IDs del manifiesto del Lote 6 revirtió exclusivamente los 10 grupos del Lote 6, retornando la base de datos a 69 grupos y 73 elementos, con los 69 grupos previos 100% blindados e inalterados.
     - Re-ejecución posterior restauró el estado final de 79 grupos y 84 elementos con 100% de reconciliación profunda (`integrity_verified = true`, 0 discrepancias).
  4. **Validación de API de Dominio y Resolución Bilingüe Cruzada:**
     - `ContentTranslationResolver`: validación de asignación lingüística sobre plantillas (Post 6, Post 1300, Term 32 en idioma `es`).
     - Resolución simétrica cruzada ES <-> EN comprobada en TRID 9208: Post 5048 ('Testimonios', `es`) hacia `en` resuelve exactamente a Post 5055 ('Testimonial', `en`), y Post 5055 hacia `es` resuelve exactamente a Post 5048.
     - Resolución monolingüe hacia idiomas sin traducción devuelve `null` sin degradación espuria.
     - Comprobaciones HTTP reales sobre `http://lab.ecoterra.test` sin errores 500 ni bucles.
     - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
  5. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.19 / Directiva DT-TFML-049 — Migración controlada del lote 6 (Plantillas Elementor).
- **JUSTIFICACIÓN:** Ejecutar el sexto lote incremental WPML -> TFML sobre plantillas y tipos de Elementor en laboratorio aislado, certificar la inviolabilidad de metadatos y estructuras JSON de Elementor, validar la resolución bilingüe simétrica cruzada de plantillas en la API de dominio, y consolidar el acumulado en 79 grupos y 84 elementos (65,8% de grupos importables).
- **CONSECUENCIAS:** El laboratorio avanza al estado acumulado de 79 grupos y 84 elementos, se certifica que la migración no contamina las configuraciones visuales ni estructuras JSON de Elementor, y los 4 lotes restantes (41 grupos) permanecen intactos en estado `STATUS_READY`.

### ADR-046: Migración Controlada del Séptimo Lote (batch-07-cpt-tours), Invarianza Criptográfica de Custom Post Types y Metadatos, Resolución Simétrica Bilingüe de Tours y Reversibilidad Aislada (Fase 3.20)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Ejecución Transaccional del Lote 7 (`batch-07-cpt-tours`):**
     - Se ejecutó la migración de los 12 TRIDs autorizados obtenidos exclusivamente del catálogo oficial de Fase 3.15: `[9256, 9285, 9287, 9293, 9303, 9313, 9318, 9428, 9533, 9534, 9535, 9536]`.
     - Subtipo y tipo de post involucrados: exactamente 12 grupos con tipo `post` y subtipo `tours` (CPT tours).
     - Cardinalidad verificada: exactamente 12 grupos y 20 elementos (8 grupos bilingües de 2 elementos [español/inglés] y 4 grupos monolingües de 1 elemento [español]).
     - Huella criptográfica inmutable ratificada: `d24b52d1224564bd745b4bc8032a9607adef5dc00379b2634a50eed08e77888b`.
     - Nuevo estado acumulado en la base de datos del laboratorio: exactamente **91 grupos** en `etk_t_tfml_groups` (79 previos + 12 nuevos) y **104 elementos** en `etk_t_tfml_group_elements` (84 previos + 20 nuevos), alcanzando el 75,8% de grupos y 78,2% de elementos importables del universo WPML.
     - Manifiesto oficial persistido y checkpoint completado con 12 ítems procesados. Lock liberado.
  2. **Preservación Criptográfica de Integridad de Tours (Delta Cero Absoluto):**
     - Se capturó un snapshot exhaustivo pre y post-migración sobre los 20 posts CPT `tours` involucrados en el Lote 7 (`[5321, 5322, 5380, 5381, 5384, 5385, 5396, 5397, 5416, 5417, 5436, 5437, 5446, 5447, 5772, 5773, 6198, 6204, 6210, 6212]`).
     - Se certificó que las tablas nativas de WordPress (`etk_t_posts`, `etk_t_postmeta`, `etk_t_term_relationships`) permanecen 100% idénticas (Delta = 0 en títulos, slugs, contenido, tipos, campos personalizados, configuraciones de tours y relaciones taxonómicas).
  3. **Idempotencia y Reversibilidad Aislada en Base de Datos Real:**
     - Segunda ejecución consecutiva sobre el Lote 7 reportó 12 resultados `idempotent_skipped` (0 duplicados).
     - Rollback selectivo mediante `rollback_groups` sobre los IDs del manifiesto del Lote 7 revirtió exclusivamente los 12 grupos del Lote 7, retornando la base de datos de manera exacta a 79 grupos y 84 elementos, con los 79 grupos previos (lotes 1 al 6) 100% blindados e inalterados.
     - Re-ejecución posterior restauró el estado final de 91 grupos y 104 elementos con 100% de reconciliación profunda (`integrity_verified = true`, 12 grupos verificados, 0 discrepancias).
  4. **Validación de API de Dominio y Resolución Bilingüe Cruzada:**
     - `ContentTranslationResolver`: validación de resolución simétrica cruzada ES <-> EN en los 8 pares de tours bilingües (TRID 9256: 5321 <-> 5322; TRID 9285: 5380 <-> 5381; TRID 9287: 5384 <-> 5385; TRID 9293: 5396 <-> 5397; TRID 9303: 5416 <-> 5417; TRID 9313: 5436 <-> 5437; TRID 9318: 5446 <-> 5447; TRID 9428: 5772 <-> 5773).
     - Tours monolingües en español (TRID 9533: 6198; TRID 9534: 6204; TRID 9535: 6210; TRID 9536: 6212) resuelven hacia EN devolviendo estrictamente `null` (sin fallback espurio).
     - Preservación íntegra de la capacidad resolutiva de lotes anteriores (Elementor, etiquetas, categorías, páginas).
     - Comprobaciones HTTP reales sobre `http://lab.ecoterra.test` sin errores 500 ni bucles.
     - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
  5. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.20 / Directiva DT-TFML-050 — Migración transaccional del lote 7: CPT Tours.
- **JUSTIFICACIÓN:** Ejecutar el séptimo lote incremental WPML -> TFML sobre publicaciones del Custom Post Type `tours` en laboratorio aislado, certificar la inviolabilidad de metadatos nativos y relaciones taxonómicas de tours, validar la resolución bilingüe simétrica cruzada en la API de dominio, y consolidar el acumulado en 91 grupos y 104 elementos.
- **CONSECUENCIAS:** El laboratorio avanza al estado acumulado de 91 grupos y 104 elementos (75,8% de grupos y 78,2% de elementos importables del universo WPML), y los 3 lotes restantes (29 grupos) permanecen intactos en estado `STATUS_READY`.

### ADR-047: Migración Controlada del Octavo Lote (batch-08-cpt-products), Invarianza Criptográfica de Productos WooCommerce y Metadatos Comerciales, Resolución Monolingüe y Reversibilidad Aislada (Fase 3.21)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Ejecución Transaccional del Lote 8 (`batch-08-cpt-products`):**
     - Se ejecutó la migración de los 7 TRIDs autorizados obtenidos exclusivamente del catálogo oficial de Fase 3.15: `[9224, 9225, 9231, 9431, 9499, 9526, 9540]`.
     - Subtipo y tipo de post involucrados: exactamente 7 grupos con tipo `post` y subtipo `product` (CPT WooCommerce product correspondientes a habitaciones/alojamiento).
     - Cardinalidad verificada: exactamente 7 grupos y 7 elementos (7 publicaciones monolingües en idioma español: Posts 5250, 5264, 5275, 5787, 6123, 6178, 6224).
     - Huella criptográfica inmutable ratificada: `3df3ce6f1a6dac619d203adaf5a31d8eb3fc332493330a5e8b755d4ff1d9136d`.
     - Nuevo estado acumulado en la base de datos del laboratorio: exactamente **98 grupos** en `etk_t_tfml_groups` (91 previos + 7 nuevos) y **111 elementos** en `etk_t_tfml_group_elements` (104 previos + 7 nuevos), alcanzando el 81,7% de grupos y el 83,5% de elementos importables del universo WPML.
     - Manifiesto oficial persistido y checkpoint completado con 7 ítems procesados. Lock liberado.
  2. **Preservación Criptográfica de Integridad de Productos WooCommerce (Delta Cero Absoluto):**
     - Se capturó un snapshot exhaustivo pre y post-migración sobre los 7 productos WooCommerce (`[5250, 5264, 5275, 5787, 6123, 6178, 6224]`).
     - Se certificó que las tablas nativas de WordPress (`etk_t_posts`, `etk_t_postmeta`, `etk_t_term_relationships`) permanecen 100% idénticas (Delta = 0 en títulos, slugs, contenido, estados, tipos, precios regulares y vigentes `_price`/`_regular_price`, gestión de inventario `_stock`/`_stock_status`, impuestos `_tax_status`/`_tax_class`, metadatos de reserva `ovabrw_*` y relaciones taxonómicas).
     - Ausencia absoluta de recálculos automáticos de existencias, precios, ventas o pedidos.
  3. **Idempotencia y Reversibilidad Aislada en Base de Datos Real:**
     - Segunda ejecución consecutiva sobre el Lote 8 reportó 7 resultados `idempotent_skipped` (0 duplicados).
     - Rollback selectivo mediante `rollback_groups` sobre los IDs del manifiesto del Lote 8 revirtió exclusivamente los 7 grupos del Lote 8, retornando la base de datos de manera exacta a 91 grupos y 104 elementos, con los 91 grupos previos (lotes 1 al 7) 100% blindados e inalterados.
     - Re-ejecución posterior restauró el estado final de 98 grupos y 111 elementos con 100% de reconciliación profunda (`integrity_verified = true`, 7 grupos verificados, 0 discrepancias).
  4. **Validación de API de Dominio y Ausencia de Fallback Comercial:**
     - `ContentTranslationResolver`: validación de asignación lingüística en español (`es`) para cada uno de los 7 productos comerciales.
     - Resolución hacia idioma inglés (`en`): devuelve estrictamente `null` sin fallback espurio hacia canonical ni default.
     - Preservación íntegra de la capacidad resolutiva de lotes anteriores (tours bilingües de Lote 7, plantillas Elementor de Lote 6, etiquetas, categorías, páginas).
     - Comprobaciones HTTP reales sobre `http://lab.ecoterra.test` sin errores 500 ni bucles.
     - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
  5. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.21 / Directiva DT-TFML-051 — Migración transaccional del lote 8: Productos WooCommerce.
- **JUSTIFICACIÓN:** Ejecutar el octavo lote incremental WPML -> TFML sobre publicaciones del Custom Post Type `product` de WooCommerce en laboratorio aislado, certificar la inviolabilidad de metadatos comerciales, de inventario y de reservas, validar la resolución monolingüe limpia en la API de dominio, y consolidar el acumulado en 98 grupos y 111 elementos.
- **CONSECUENCIAS:** El laboratorio avanza al estado acumulado de 98 grupos y 111 elementos (81,7% de grupos y 83,5% de elementos importables del universo WPML), y los 2 lotes restantes (22 grupos: Lote 9 core-posts-forms [4] y Lote 10 nav-menu-items [18]) permanecen intactos en estado `STATUS_READY`.

### ADR-048: Migración Controlada del Noveno Lote (batch-09-core-posts-forms), Invarianza Criptográfica de Entradas y Formularios CF7, Validación de Dominio y Reversibilidad Aislada (Fase 3.22)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Ejecución Transaccional del Lote 9 (`batch-09-core-posts-forms`):**
     - Se ejecutó la migración de los 4 TRIDs autorizados obtenidos exclusivamente del catálogo oficial de Fase 3.15: `[4340, 9391, 9406, 9411]`.
     - Subtipos y tipos de post involucrados: exactamente 3 grupos con tipo `post` y subtipo `post` (entradas nativas de blog) y 1 grupo con tipo `post` y subtipo `wpcf7_contact_form` (formulario de Contact Form 7).
     - Cardinalidad verificada: exactamente 4 grupos y 4 elementos (4 publicaciones monolingües en idioma español: Post 4340 [Formulario Contactos - ES], Post 5687 [Recomendaciones para visitar Machu Picchu], Post 5718 [Santuario Amazonico del Pongo de Mainique], Post 5734 [Choquequirao, el otro Machu Picchu]).
     - Huella criptográfica inmutable ratificada: `6d188e1cbff392deaa030e917d5d43aaed482f915c218a73c87f415d39f53720`.
     - Nuevo estado acumulado en la base de datos del laboratorio: exactamente **102 grupos** en `etk_t_tfml_groups` (98 previos + 4 nuevos) y **115 elementos** en `etk_t_tfml_group_elements` (111 previos + 4 nuevos), alcanzando el 85,0% de grupos y el 86,5% de elementos importables del universo WPML.
     - Manifiesto oficial persistido y checkpoint completado con 4 ítems procesados. Lock liberado.
  2. **Preservación Criptográfica de Integridad de Entradas y Formularios CF7 (Delta Cero Absoluto):**
     - Se capturó un snapshot exhaustivo pre y post-migración sobre las 4 publicaciones involucradas (`[4340, 5687, 5718, 5734]`).
     - Se certificó que las tablas nativas de WordPress (`etk_t_posts`, `etk_t_postmeta`, `etk_t_term_relationships`) permanecen 100% idénticas (Delta = 0 en títulos, slugs, contenido, estados, tipos, campos de configuración de Contact Form 7 `_form`, `_mail`, `_mail_2`, `_messages`, `_additional_settings` y relaciones taxonómicas de blog).
     - Ausencia absoluta de envíos de formulario reales (`wpcf7_submit`) y cero despachos de correo electrónico durante la verificación.
  3. **Idempotencia y Reversibilidad Aislada en Base de Datos Real:**
     - Segunda ejecución consecutiva sobre el Lote 9 reportó 4 resultados `idempotent_skipped` (0 duplicados).
     - Rollback selectivo mediante `rollback_groups` sobre los IDs del manifiesto del Lote 9 revirtió exclusivamente los 4 grupos del Lote 9, retornando la base de datos de manera exacta a 98 grupos y 111 elementos, con los 98 grupos previos (lotes 1 al 8) 100% blindados e inalterados.
     - Re-ejecución posterior restauró el estado final de 102 grupos y 115 elementos con 100% de reconciliación profunda (`integrity_verified = true`, 4 grupos verificados, 0 discrepancias).
  4. **Validación de API de Dominio y Preservación de Lotes Históricos:**
     - `ContentTranslationResolver`: validación de asignación lingüística en español (`es`) para cada una de las 4 publicaciones.
     - Resolución hacia idioma inglés (`en`): devuelve estrictamente `null` sin fallback espurio hacia canonical ni default.
     - Preservación íntegra de la capacidad resolutiva de lotes anteriores (productos WooCommerce de Lote 8, tours bilingües de Lote 7, plantillas Elementor de Lote 6, etiquetas, categorías, páginas).
     - Comprobaciones HTTP reales sobre `http://lab.ecoterra.test` sin errores 500 ni bucles.
     - Frontera de frontend: se mantiene la designación formal de `BLOCKED` para el renderizado HTML de etiquetas (`<html lang>`, canonical y hreflang en el cuerpo HTML) debido a la ausencia física del tema `ecoCMS` en el laboratorio.
  5. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.22 / Directiva DT-TFML-052 — Migración transaccional del lote 9: Entradas y Formularios.
- **JUSTIFICACIÓN:** Ejecutar el noveno lote incremental WPML -> TFML sobre publicaciones nativas (`post`) y formularios de contacto (`wpcf7_contact_form`) en laboratorio aislado, certificar la inviolabilidad de metadatos de configuración y shortcodes, validar la resolución monolingüe limpia en la API de dominio, y consolidar el acumulado en 102 grupos y 115 elementos.
- **CONSECUENCIAS:** El laboratorio avanza al estado acumulado de 102 grupos y 115 elementos (85,0% de grupos y 86,5% de elementos importables del universo WPML), y el último lote pendiente (18 grupos: Lote 10 `batch-10-nav-menu-items`) permanece intacto en estado `STATUS_READY`.

### ADR-049: Migración Controlada del Décimo Lote (batch-10-nav-menu-items), Cobertura del 100% del Universo Importable, Invarianza Criptográfica de Navegación, Reconciliación Global y Preservación de Entidades No Importables (Fase 3.23)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Ejecución Transaccional del Lote 10 (`batch-10-nav-menu-items`):**
     - Se ejecutó la migración de los 18 TRIDs autorizados obtenidos exclusivamente del catálogo oficial de Fase 3.15: `[9210, 34659, 34701, 34789, 34792, 34824, 34826, 34828, 34829, 34832, 34833, 34834, 34835, 34836, 34837, 34838, 34839, 34840]`.
     - Subtipo y tipo de post involucrados: exactamente 18 grupos con tipo `post` y subtipo `nav_menu_item` (elementos de menú WordPress).
     - Cardinalidad verificada: exactamente 18 grupos y 18 elementos (9 elementos en idioma español y 9 elementos en idioma inglés: Posts `[8363, 9243, 9376, 9657, 9671, 9902, 10076, 10080, 10081, 10151, 10152, 10153, 10154, 10155, 10156, 10157, 10158, 10159]`).
     - Huella criptográfica inmutable ratificada: `19b7f15c730a7f0059b7d6873a37b07ed2bcd63f499c5a30fc921ae5d4957acc`.
     - Nuevo estado acumulado en la base de datos del laboratorio: exactamente **120 grupos** en `etk_t_tfml_groups` (102 previos + 18 nuevos) y **133 elementos** en `etk_t_tfml_group_elements` (115 previos + 18 nuevos), alcanzando el **100,0% del universo clasificado como IMPORTABLE**.
     - Manifiesto oficial persistido y checkpoint completado con 18 ítems procesados. Lock liberado.
  2. **Preservación Criptográfica de Menús de Navegación (Delta Cero Absoluto):**
     - Se capturó un snapshot exhaustivo pre y post-migración sobre los 18 elementos de menú.
     - Se certificó que las tablas nativas de WordPress (`etk_t_posts`, `etk_t_postmeta`, `etk_t_term_relationships`) permanecen 100% idénticas (Delta = 0 en títulos, slugs, contenido, estados, tipos, `menu_order`, `post_parent`, metadatos de navegación `_menu_item_type`, `_menu_item_object`, `_menu_item_object_id`, `_menu_item_menu_item_parent`, `_menu_item_url`, `_menu_item_target`, `_menu_item_classes`, `_menu_item_xfn` y asignaciones taxonómicas en `nav_menu`).
     - Se cumplió estrictamente la prohibición de reescribir IDs de destino o jerarquías de navegación.
  3. **Idempotencia y Reversibilidad Aislada en Base de Datos Real:**
     - Segunda ejecución consecutiva sobre el Lote 10 reportó 18 resultados `idempotent_skipped` (0 duplicados). Conteos inmutables en 120 grupos y 133 elementos.
     - Rollback selectivo mediante `rollback_groups` sobre los IDs del manifiesto del Lote 10 revirtió exclusivamente los 18 grupos del Lote 10, retornando la base de datos de manera exacta a 102 grupos y 115 elementos, con los 102 grupos previos (lotes 1 al 9) 100% blindados e inalterados.
     - Re-ejecución posterior restauró el estado final de 120 grupos y 133 elementos con 100% de reconciliación profunda (`integrity_verified = true`, 18 grupos verificados, 0 discrepancias).
  4. **Reconciliación Global de los Diez Lotes y Preservación de No Importables:**
     - Universo importable completado al 100%: 120 de 120 grupos previstos y 133 de 133 elementos previstos. Cero grupos pendientes del catálogo importable. Cero grupos duplicados y cero elementos duplicados en tablas TFML.
     - Preservación íntegra de entidades no importables del plan global WPML: 52 grupos `BLOCKED_BY_LANGUAGE`, 24 grupos `SKIPPED` y 53 grupos `ORPHAN` conservados en sus estados analíticos sin alteración.
  5. **Diferenciación Rigurosa de Alcance y Frontera Frontend:**
     - Se establece taxativamente la diferencia entre la cobertura total del universo importable (120/120 grupos, 133/133 elementos) y la migración global WPML (la cual excluye entidades bloqueadas, omitidas y huérfanas que requieren fases posteriores específicas).
     - Se mantiene formalmente la condición de `BLOCKED` para el renderizado visual de menús y etiquetas HTML (`<html lang>`, canonical y hreflang) por la ausencia física del tema `ecoCMS` en el laboratorio.
     - El entorno permanece exclusivamente en laboratorio aislado; la preparación para producción no está autorizada.
  6. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.23 / Directiva DT-TFML-053 — Migración transaccional del lote 10: Elementos de menú WordPress.
- **JUSTIFICACIÓN:** Ejecutar el décimo y último lote del catálogo de migración importable en laboratorio aislado, certificar la inviolabilidad de metadatos de navegación y relaciones de menú, asegurar la idempotencia y reversibilidad selectiva, consolidar el 100% del universo importable (120 grupos / 133 elementos) y preservar las entidades no importables.
- **CONSECUENCIAS:** El laboratorio alcanza el 100% del universo importable (120 grupos y 133 elementos en tablas TFML). Quedan identificadas y delimitadas las brechas funcionales de frontend y el tratamiento de entidades no importables antes de abordar cualquier certificación integral para producción.

### ADR-050: Auditoría Integral Post-Migración, Congelamiento de Baseline de Datos, Diagnóstico de Brechas Funcionales y Plan de Incorporación Controlada del Tema ecoCMS (Fase 3.24)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Congelamiento y Certificación del Baseline Post-Migración (120 Grupos / 133 Elementos):**
     - Se auditó exhaustivamente la base de datos de laboratorio `cms_ect_lab` ratificando el congelamiento exacto de 120 grupos en `etk_t_tfml_groups` y 133 elementos en `etk_t_tfml_group_elements`, correspondientes al 100,0% del catálogo importable saneado (10 lotes migrados).
     - Se certificó formalmente: 0 grupos duplicados, 0 elementos duplicados, 0 registros en `etk_t_tfml_media_translations`, 0 registros en `etk_t_tfml_strings` y 0 registros en `etk_t_tfml_string_translations`.
     - Checkpoint oficial consolidado en estado `completed` con 18 ítems procesados (Lote 10), último TRID `34840`, y lock de ejecución liberado (`is_locked = false`).
  2. **Diagnóstico Causa-Raíz de la Ausencia del Tema ecoCMS:**
     - En `cms_ect_lab` (tabla `etk_t_options`), las opciones `template`, `stylesheet` y `current_theme` están configuradas en `'ecoCMS'`.
     - El directorio `wp-content/themes` en `lab.ecoterra` se encuentra completamente vacío (ausencia física del tema), lo cual constituye la causa raíz técnica y única del fallo visual y de la imposibilidad de evaluar `<html lang>`, `canonical`, `hreflang` en el cuerpo HTML y navegación en frontend.
     - Se auditó el tema de origen en `cms.ecoterra`: es un tema autónomo (no child theme) versión 1.0.0, Text Domain `qomfort`, dependiente de los plugins `ova-framework`, `elementor` y `elementor-pro`, y sin dependencias de WPBakery.
     - Se formuló un protocolo de incorporación física controlada mediante copia estricta (prohibiendo enlaces simbólicos o carpetas compartidas) con salvaguardas perimetrales activas (`X-Robots-Tag: noindex`, `wp_mail` desactivado, HTTP externo bloqueado).
  3. **Diagnóstico Funcional del TRID 197 (Página de Inicio) y Causa del HTTP 404 en `/en/`:**
     - Configuración Core: `show_on_front = 'page'`, `page_on_front = 197`.
     - Anatomía en WPML: TRID 197 agrupa la página 197 (`es` - Inicio, canónica), página 8356 (`en` - Home, traducción inglesa) y página 8839 (`fr` - Accueil, traducción francesa).
     - Causa del bloqueo: El planificador WPML clasificó todo el TRID 197 como `BLOCKED_BY_LANGUAGE` debido a la presencia del miembro en francés (`fr`), idioma no configurado en TFML.
     - Por consiguiente, la página 8356 no fue importada al grupo de TFML, `ContentTranslationResolver::resolve_element_id('post', 197, 'en')` devuelve estrictamente `null`, y las solicitudes HTTP a `http://lab.ecoterra.test/en/` devuelven `404 Not Found` (comportamiento esperado y correcto dado el estado actual de los datos).
     - Se propone para una microfase posterior la derivación quirúrgica del TRID 197 registrando el par activo `es` <-> `en` en TFML y dejando la traducción francesa en `pending_translations`, sin activar francés en el frontend.
  4. **Auditoría Estructural de los 129 Grupos No Importables del Universo WPML (249 Totales):**
     - `BLOCKED_BY_LANGUAGE` (52 grupos / 139 elementos): 23 `page`, 14 `nav_menu_item`, 11 `rooms`, 2 `ova_framework_hf_el`, 1 `category`, 1 `nav_menu`. Son entidades íntegras bloqueadas exclusivamente por tener variantes en francés (`fr`).
     - `SKIPPED` (24 grupos / 48 elementos): 21 comentarios de blog (`comment`) y 3 taxonomías técnicas internas de WPML (`translation_priority`). No deben importarse a tablas TFML.
     - `ORPHAN` (53 grupos / 53 elementos): 20 `product`, 19 `page`, 10 `tours`, 3 `post`, 1 `mc4wp-form`. Entidades eliminadas físicamente de WordPress Core cuyos residuos permean en `icl_translations`. Deben permanecer excluidas.
  5. **Diagnóstico de Integraciones (Elementor vs WPBakery):**
     - WPBakery: Se constató un recuento exacto de 0 registros con prefijo `vc_*` o `wpb*` en `etk_t_postmeta`. WPBakery no aplica al sitio de Ecoterra Lodge.
     - Elementor: Confirmada presencia activa en 398 publicaciones con metadatos `_elementor_data`.
  6. **Invarianza Absoluta del Centinela en Origen (`cms_ect`):**
     - Verificación pre y post-flight en `cms_ect`: 3.403 filas exactas y checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.24 / Directiva DT-TFML-054 — Auditoría Integral Post-Migración y Diagnóstico de Brechas Funcionales.
- **JUSTIFICACIÓN:** Completada la migración del 100% del universo importable, se requiere una auditoría exhaustiva de solo lectura para certificar el estado de los datos en laboratorio, identificar las causas raíz del bloqueo de frontend (tema `ecoCMS` ausente y TRID 197), desglosar la totalidad de entidades no importables y establecer la hoja de ruta técnica para las fases subsiguientes.
- **CONSECUENCIAS:** Se mantiene el congelamiento total de datos de migración, se delimitan los riesgos P0, P1 y P2, y se provee la fundamentación técnica indispensable para que la Dirección Técnica autorice las microfases de incorporación del tema, derivación de TRID 197 y certificación funcional de frontend.

### ADR-051: Restauración Controlada del Entorno Visual en Laboratorio Mediante Copia Física Aislada de ecoCMS y ova-framework, Certificación de Matriz HTTP/HTML y Diagnóstico de Navegación (Fase 3.25)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Incorporación Física Aislada del Tema ecoCMS (175 Archivos):**
     - Se realizó una copia física independiente desde `D:/laragon/www/cms.ecoterra/wp-content/themes/ecoCMS` hacia `D:/laragon/www/lab.ecoterra/wp-content/themes/ecoCMS`.
     - Se certificó formalmente: 175 archivos copiados físicamente, 0 enlaces simbólicos, 0 junctions NTFS, 0 directorios compartidos y 100% de coincidencia en tamaño e integridad criptográfica SHA-256 (0 discrepancias).
     - El tema de origen permanece estrictamente intacto y desvinculado del laboratorio.
  2. **Evaluación e Incorporación de la Dependencia ova-framework (6 Archivos):**
     - Se auditó el código de `ova-framework` en origen: no ejecuta consultas de escritura automática, tareas cron, envíos de correo, mutaciones a tablas de WPML ni conexiones remotas no autorizadas.
     - Se realizó la copia física independiente de sus 6 archivos hacia `D:/laragon/www/lab.ecoterra/wp-content/plugins/ova-framework` con 100% de paridad SHA-256.
     - Habilitó el registro nativo de los Custom Post Types `rooms` y `ova_framework_hf_el` requeridos por las plantillas del tema.
  3. **Arranque Controlado de WordPress y Renderizado Real:**
     - WordPress en `lab.ecoterra` reconoció de forma inmediata `ecoCMS` como tema activo (`template`, `stylesheet`, `current_theme` en `etk_t_options`).
     - Se verificó la transición del frontend: de un documento vacío de 0 bytes (en Fase 3.24) a un renderizado HTML estructural completo de 52.217 bytes en la ruta raíz (`/`), con `<header>`, `<footer>`, estilos y scripts locales.
  4. **Ejecución de la Matriz HTTP y HTML Obligatoria (11 Rutas Evaluadas):**
     - `/`: HTTP 200 OK (52.217 bytes, canonical, `X-Robots-Tag: noindex, nofollow, noarchive`).
     - `/en/`: HTTP 404 Not Found (31.062 bytes, renderizado de plantilla 404 de `ecoCMS`; comportamiento esperado por bloqueo del TRID 197).
     - `/fr/`: HTTP 404 Not Found (31.062 bytes, rechazo limpio de idioma inactivo sin aliasing espurio).
     - `/old-blog/` (Página monolingüe migrada): HTTP 200 OK (31.865 bytes, canonical localizado).
     - `/blog/recomendaciones-para-disfrutar-machu-picchu/` (Entrada blog migrada): HTTP 200 OK (51.107 bytes, contenido editorial y layout completo).
     - `/category/blog/` (Categoría migrada): HTTP 200 OK (49.588 bytes, archivo de taxonomía).
     - `/tag/cusco/` (Etiqueta migrada): HTTP 200 OK (41.544 bytes, archivo de etiqueta).
     - Tours bilingües y Producto WooCommerce: códigos HTTP controlados sin errores 500.
     - Cero errores críticos PHP o excepciones fatales en runtime.
  5. **Diagnóstico Anatómico de Menús, Navegación y Selector de Idiomas:**
     - En base de datos existen tres menús editoriales: `Menu Principal` (ES, ID 43), `Menu - ES - Inglés` (EN, ID 98) y `Menu Principal - Francés` (FR, ID 105).
     - En `nav_menu_locations`, la ubicación del tema `primary` se encuentra sin asignar (`''`) debido a que en origen el sitio delegaba la cabecera al constructor Elementor (`ova_framework_hf_el`).
     - En `template-parts/header-default.php`, la navegación clásica requiere la asignación explícita de ubicación o la activación de Elementor Pro para renderizar las plantillas de cabecera.
     - Se detectó que el helper temático `traducir()` en `ecoCMS/inc/ecoterra.php` consulta la constante histórica `ICL_LANGUAGE_CODE`, retornando español como fallback al no estar definida.
  6. **Invarianza Absoluta de Datos y Centinela en Origen:**
     - `cms_ect_lab`: Conserva exactamente 120 grupos y 133 elementos (100% universo importable). Checkpoint `completed`, TRID 34840, lock liberado.
     - `cms_ect`: Intacto con 3.403 filas en `etk_t_icl_translations` y hash MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.25 / Directiva DT-TFML-055 — Restauración controlada del entorno visual en laboratorio.
- **JUSTIFICACIÓN:** Cumplir el mandato de incorporar una copia física aislada del tema `ecoCMS` y la dependencia `ova-framework`, habilitar el renderizado real del frontend, ejecutar la matriz de 11 rutas HTTP/HTML obligatorias y diagnosticar la interacción entre el tema y TF Multilingual manteniendo blindados los datos y el aislamiento.
- **CONSECUENCIAS:** Queda superado el bloqueo de renderizado HTML (0 bytes -> 52KB). Se documentan las dependencias restantes (Elementor para cabecera compleja y TRID 197 para `/en/`), habilitando el paso hacia la derivación quirúrgica del TRID 197 en la Fase 3.26.

### ADR-052: Diagnóstico Integral del TRID 197, Enrutamiento de Portada Bilingüe, Causa Raíz del Atributo <html lang> y Compatibilidad Lingüística de ecoCMS (Fase 3.26)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Diagnóstico Causa-Raíz de `<html lang="en-US">` en Páginas en Español:**
     - En `ecoCMS/header.php`, el tema invoca la función canónica de WordPress `language_attributes()`, la cual consulta internamente `get_bloginfo('language')` -> `get_locale()`.
     - En la base de datos de laboratorio `cms_ect_lab` (heredado de `cms_ect`), la opción de WordPress `WPLANG` se encuentra vacía (`""`). Ante esto, WordPress Core recurre por defecto al locale `'en_US'`.
     - TF Multilingual no registraba ningún interceptor en el filtro de WordPress Core `locale` o `determine_locale` en frontend.
     - **Solución Limpia Certificada (Sin Expresiones Regulares ni Buffering):** Enganchar en el ciclo de inicio de TF Multilingual un filtro nativo sobre el hook `locale`. Cuando `!is_admin()`, el filtro consulta `CurrentLanguageResolver::get_current_language()`, resuelve su código regional formal (`es` -> `es_ES`, `en` -> `en_US`) desde `LanguageRegistry`, y lo entrega a WordPress Core. WordPress Core convierte automáticamente `es_ES` en `lang="es-ES"` en `language_attributes()`, preservando la pureza arquitectónica y eliminando cualquier manipulación frágil sobre el HTML.
  2. **Diagnóstico Anatómico del TRID 197 y Resolución de Portada Bilingüe (`/` vs `/en/`):**
     - Configuración Core en `etk_t_options`: `show_on_front = 'page'`, `page_on_front = 197`.
     - En WPML (`etk_t_icl_translations`), el TRID 197 agrupa tres miembros: Post ID 197 (`es` - Inicio, canónica original), Post ID 8356 (`en` - Home, traducción inglesa) y Post ID 8839 (`fr` - Accueil, traducción francesa).
     - Causa del HTTP 404 actual en `/en/`: El clasificador del migrador catalogó el TRID 197 como `BLOCKED_BY_LANGUAGE` al detectar la presencia de `'fr'` (idioma no activo en `LanguageRegistry`), impidiendo su importación en los 10 lotes iniciales.
     - **Doble Condición Técnica para Resolver `/en/` con HTTP 200 OK:**
       a) Migrar el par activo `es` <-> `en` (Posts 197 y 8356) a las tablas de traducción de TF Multilingual.
       b) Interceptar la consulta de la portada de WordPress (en `option_page_on_front` o `pre_get_posts` en la consulta principal): cuando el idioma activo resuelto sea `'en'`, sustituir `page_id = 197` por el ID traducido `8356` (obtenido a través de `ContentTranslationResolver::resolve_element_id('post', 197, 'en')`). Sin esta intercepción, WordPress Core consulta `page_id = 197`, y `QueryLanguageFilter` descarta el resultado por discrepancia lingüística (`es` != `en`), produciendo 404 Not Found.
  3. **Evaluación Comparativa de Alternativas para la Migración del TRID 197:**
     - *Alternativa A (Mantener bloqueado hasta activar francés):* Preserva la regla estricta de migración pero bloquea indefinidamente la portada en inglés (`/en/`), haciendo inviable certificar el sitio bilingüe.
     - *Alternativa B (Derivación e importación parcial explícita del par activo — SELECCIONADA Y RECOMENDADA):* Evolucionar formalmente el contrato de `MigrationPlan` para soportar la importación selectiva de un par de idiomas activos (`es` <-> `en`) mientras se preserva el miembro francés (Post 8839) en `pending_translations` dentro del manifiesto de migración. Permite crear 1 grupo y 2 elementos en tablas TFML (elevando el total a 121 grupos y 135 elementos), preservando la trazabilidad completa, garantizando reversibilidad absoluta (rollback selectivo a 120/133) y permitiendo incorporar el francés sin colisiones en fases futuras.
     - *Alternativa C (Crear grupo manual fuera del migrador vía TranslationEditorialService):* Desvincula la creación del registro histórico de WPML, rompe la consistencia de los checkpoints de migración y genera bit drift. Descartada.
  4. **Auditoría Exhaustiva de Compatibilidad Temática de ecoCMS:**
     - Se realizó un inventario estático riguroso sobre los 175 archivos PHP del tema `ecoCMS`, cuantificando con exactitud 105 llamadas directas y 4 guardas estructurales (109 referencias a símbolos WPML en total):
       - `ICL_LANGUAGE_CODE`: 5 ocurrencias del identificador en 3 archivos (`inc/ecoterra.php`, `inc/short-codes.php`, `template-parts/tour.php`).
       - `traducir()`: 96 llamadas a la función helper a lo largo de 94 líneas del tema (vistas de tours, encabezados, botones, taxonomías).
       - `icl_object_id()`: 2 llamadas directas y 2 guardas `function_exists('icl_object_id')` en `inc/class-hook.php`.
       - `icl_get_languages()`: 2 llamadas directas y 2 guardas `function_exists('icl_get_languages')` en `inc/short-codes.php`.
     - Se verificó que `traducir()` en `ecoCMS/inc/ecoterra.php` implementa: `$idioma_actual = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'es';`. Sin la constante definida, retorna invariablemente el texto en español.
     - **Estrategia Seleccionada:** Proveer en TF Multilingual un puente de compatibilidad temático de solo lectura (`ThemeCompatibilityBridge`): definir la constante `ICL_LANGUAGE_CODE` con base en `CurrentLanguageResolver` y registrar funciones stub para `icl_object_id` e `icl_get_languages` delegando en `ContentTranslationResolver`. Esta estrategia garantiza el funcionamiento del 100% de las 109 referencias del tema sin modificar una sola línea de código de `ecoCMS`.
  5. **Diagnóstico de Arquitectura de Cabecera y Dependencia de Elementor:**
     - En `ecoCMS`, la cabecera original de producción fue maquetada con el constructor visual Elementor Pro utilizando el Custom Post Type `ova_framework_hf_el` (identificadas 6 publicaciones en `etk_t_posts`).
     - Al no encontrarse activo Elementor en el laboratorio, `ecoCMS/inc/class-hook.php` activa su mecanismo de contingencia ejecutando `get_template_part('template-parts/header', 'default')`.
     - En `header-default.php`, la navegación clásica requiere la asignación explícita de ubicación (`theme_location => 'primary'`) o la incorporación controlada de los complementos `elementor` y `elementor-pro` para renderizar el encabezado y mega-menú original de producción.
  6. **Invarianza Absoluta de Centinelas:**
     - Base de datos de origen `cms_ect`: 3.403 filas en `etk_t_icl_translations`, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
     - Base de datos de laboratorio `cms_ect_lab`: 120 grupos, 133 elementos congelados intactos (**Delta = 0**).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.26 / Directiva DT-TFML-056 — Diagnóstico y diseño seguro de la portada bilingüe y compatibilidad del tema.
- **JUSTIFICACIÓN:** Resolver técnicamente los diagnósticos requeridos por Dirección Técnica sobre la discrepancia de atributo `<html lang>`, la ausencia de respuesta en `/en/`, las alternativas de importación del TRID 197, la cuantificación de compatibilidad de `ecoCMS` y la dependencia de Elementor, manteniendo estrictamente el modo no destructivo de solo lectura.
### ADR-053: Localización Pública Nativa de WordPress y Corrección del Atributo <html lang> sin Mutación de WPLANG (Fase 3.27)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Implementación del Componente Canónico de Localización:**
     - Se creó la clase `TF\Multilingual\Localizacion\LocalizadorPublicoNativo` en el espacio de nombres `TF\Multilingual\Localizacion`.
     - El componente colabora directamente con `CurrentLanguageResolver` y `LanguageRegistry`.
     - Se integra en el ciclo de vida del plugin a través de `Plugin::init()` y `Plugin::registrar_ganchos()`.
  2. **Intercepción de Hooks Nativos de WordPress Core (`locale` y `determine_locale`):**
     - En WordPress Core, `language_attributes()` invoca `get_bloginfo('language')`, el cual a su vez consulta `get_locale()` y sustituye el guión bajo por guión medio (`_` -> `-`). `language_attributes()` **no** pasa por `determine_locale()`.
     - Por consiguiente, `LocalizadorPublicoNativo` se engancha simultáneamente a:
       - `locale` (prioridad 10): alimenta de manera directa a `get_locale()`, `language_attributes()` y la carga de traducciones MO/PO.
       - `determine_locale` (prioridad 10): satisface el estándar moderno de WordPress 5.0+ para la resolución del locale en el bootstrap.
  3. **Discriminación Rigurosa de Contexto Público vs Administrativo:**
     - El método `es_contexto_publico_elegible()` preserva la soberanía del entorno administrativo y procesos asíncronos. Se abstiene de intervenir (`return false`) si:
       - `is_admin()` es verdadero (protegiendo las preferencias de usuario en WP-Admin y menús administrativos).
       - `wp_doing_cron()` es verdadero.
       - `wp_doing_ajax()` es verdadero (a menos que se trate de llamadas públicas explícitas no administrativas).
       - Se ejecuta bajo WP-CLI, salvo activación explícita mediante `habilitar_en_cli_para_pruebas(true)` para tests unitarios y scripts de verificación.
  4. **Resolución de Locales Regionales e Idiomas Desconocidos:**
     - Si el idioma activo es `'es'`, resuelve `'es_ES'`.
     - Si el idioma activo es `'en'`, resuelve `'en_US'`.
     - Para idiomas activos registrados en `LanguageRegistry`, se extrae su código regional (`locale` / `facebook_locale`), o se compone `{código}_{CÓDIGO}` si no está definido.
     - Si la petición es para un idioma inactivo o bloqueado (p. ej. `/fr/`) o si `get_current_language()` retorna `null`, el localizador se abstiene de intervenir, permitiendo que WordPress conserve su comportamiento por defecto sin habilitar idiomas no autorizados.
  5. **Protección Antirrecursión e Invarianza de la Opción WPLANG:**
     - Se implementó un semáforo interno `$en_resolucion` para evitar ciclos infinitos si llamadas a funciones de traducción interna o filtros satélites vuelven a disparar `get_locale()`.
     - Se respeta estrictamente la directiva de no mutar la opción `WPLANG` en la base de datos `cms_ect_lab`, manteniéndose vacía (`""`) a nivel de almacenamiento persistente. La adaptación es 100% en memoria en tiempo de ejecución.
  6. **Acreditación Empírica en Frontend y Quality Gates:**
     - Solicitudes HTTP reales contra `http://lab.ecoterra.test` certifican que todas las páginas en español emiten `<html lang="es-ES" >` (frente a `lang="en-US"` previo):
       - `/` -> HTTP 200 OK | `<html lang="es-ES" >`.
       - `/old-blog/` -> HTTP 200 OK | `<html lang="es-ES" >`.
       - `/category/blog/` -> HTTP 200 OK | `<html lang="es-ES" >`.
       - `/tag/cusco/` -> HTTP 200 OK | `<html lang="es-ES" >`.
       - `/sin-categorizar/recomendaciones-para-disfrutar-machu-picchu/` -> HTTP 200 OK | `<html lang="es-ES" >`.
       - `/en/` -> `<html lang="en-US" >`.
       - `/fr/` -> `<html lang="es-ES" >` (idioma inactivo no habilitado).
     - Suite de pruebas unitarias con 11 tests específicos en `LocalizadorPublicoNativoTest.php`. Total general del plugin: 540 tests, 1872 assertions PASS.
     - Centinelas de base de datos inalterados: `cms_ect` con 3.403 filas (MD5 `4241ca7e7ec6399a594537cb04790c10`), `cms_ect_lab` con 120 grupos y 133 elementos (Delta = 0).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.27 / Directiva DT-TFML-057 — Corrección del idioma HTML y localización pública nativa de WordPress.
- **JUSTIFICACIÓN:** Resolver de raíz la emisión errónea de `lang="en-US"` en las páginas públicas en español de Ecoterra Lodge, garantizando accesibilidad web, SEO multilingüe canónico y compatibilidad nativa con WordPress Core sin manipulación frágil del DOM ni mutaciones persistentes de la base de datos.
- **CONSECUENCIAS:** Las páginas públicas emiten el atributo de idioma formal correspondiente al idioma resuelto por la URL. La administración de WordPress y los procesos internos no son alterados. Se prepara el camino para la integración de compatibilidad temática de menús y el TRID 197 en fases posteriores.

---

### ADR-054: Separación en Tres Superficies Arquitectónicas y Adaptador Heredado Limitado de Compatibilidad WPML sin ICL_LANGUAGE_CODE (Fase 3.28)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Separación Arquitectónica en Tres Superficies Soberanas:**
     - **Superficie A (API Pública Nativa de TF Multilingual):** Implementada en `TF\Multilingual\API\ServicioTraduccionPublico`. Provee una interfaz limpia, orientada a objetos e independiente de cualquier plugin heredado (`obtener_idioma_actual()`, `obtener_idioma_por_defecto()`, `obtener_idiomas_activos()`, `es_idioma_activo()`, `resolver_traduccion_elemento()`, `obtener_idioma_de_elemento()`, `obtener_informacion_idiomas()`).
     - **Superficie B (Adaptador Heredado Opcional WPML):** Implementado en `TF\Multilingual\Compatibilidad\AdaptadorCompatibilidadWpml` y `src/Compatibilidad/funciones_wpml.php`. Traduce firmas y contratos históricos (`icl_object_id()`, `icl_get_languages()`) hacia la Superficie A. **Permanece desactivado por defecto** (`apply_filters('tfml_enable_wpml_compatibility_adapter', false)`) y no se activa a menos que exista configuración explícita o necesidad temática demostrada.
     - **Superficie C (Lógica Específica del Tema ecoCMS):** La función `traducir()` en `inc/ecoterra.php` y sus 94 llamadas temáticas se mantienen 100% intactas dentro del tema WordPress, respetando su mecanismo nativo de fallback a español (`defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'es'`).
  2. **Restricción Categórica de Constante Global:**
     - **NO se define bajo ninguna circunstancia la constante global `ICL_LANGUAGE_CODE`**.
     - Justificación: Definir dicha constante de forma prematura desactiva silenciosamente los fallbacks del tema, falsea el estado del runtime simulando que WPML Core está en ejecución, y vulnera la arquitectura desacoplada de TF Multilingual.
  3. **Implementación de Contratos Auditados:**
     - **`icl_object_id($id, $type, $return_original_if_missing, $lang)`:** Normaliza subtipos ('tours', 'ova_framework_hf_el', 'page', 'category', etc.) mediante `normalizar_tipo_elemento()`, resuelve en grupos de traducción existentes en `etk_t_tfml_groups` / `etk_t_tfml_group_elements`, y respeta rigurosamente el parámetro de retorno original ante ausencias o idiomas inactivos (p. ej. 'fr').
     - **`icl_get_languages($args)`:** Analiza query strings o arrays (`skip_missing`, `orderby`), alimenta la estructura requerida por selectores de idiomas (`active`, `url`, `country_flag_url`, `native_name`, `language_code`, `default_locale`), y excluye estrictamente idiomas inactivos/bloqueados (`fr`).
     - **Funciones Globales Envolventes:** Enclavadas con salvaguardas `if (!function_exists())` en `funciones_wpml.php`, delegando de forma segura en `AdaptadorCompatibilidadWpml::obtener_instancia_activa()`. Si el adaptador no está habilitado, retornan `null` o array vacío de forma segura sin producir errores fatales.
  4. **Invarianza Estricta de Centinelas y Datos:**
     - Cero escrituras o mutaciones sobre tablas WPML (`etk_t_icl_translations`, etc.).
     - Base de producción `cms_ect`: 3.403 filas intactas, MD5 `4241ca7e7ec6399a594537cb04790c10` (**Delta = 0**).
     - Base de laboratorio `cms_ect_lab`: 120 grupos y 133 elementos intactos (**Delta = 0**). TRID 197 no importado en esta fase.
  5. **Verificación Formal y Calidad:**
     - 11 pruebas unitarias añadidas en `AdaptadorCompatibilidadWpmlTest.php`. Total suite: 554 tests, 1919 aserciones PASS.
     - 68/68 controles superados con éxito en `scratch/verify_fase_3_28.php`.
     - 174/174 archivos conformes con WordPress Coding Standards (WPCS) mediante PHPCS (0 errores, 0 advertencias).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.28 / Directiva DT-TFML-058 — Contratos y adaptadores limitados de compatibilidad con WPML.
- **JUSTIFICACIÓN:** Permitir que temas y complementos diseñados para el ecosistema histórico de WPML (`ecoCMS`, `ova-framework`) puedan operar armónicamente sobre TF Multilingual sin acoplamiento espurio, garantizando que el Core del plugin permanezca 100% nativo, modular e independiente.
- **CONSECUENCIAS:** Se establece una frontera nítida de compatibilidad heredada que minimiza deuda técnica, evita colisiones de constantes globales y habilita la futura activación modular de funcionalidades visuales avanzadas de forma segura y controlada.

---

### ADR-055: Blindaje de Activación y Aislamiento de Símbolos Globales del Adaptador de Compatibilidad WPML (Fase 3.29)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Determinismo de Arranque y Respeto al Ciclo de Vida de PHP:**
     - En el motor de PHP, las funciones declaradas en el ámbito global mediante `function icl_object_id()` no pueden ser eliminadas (unloaded/undefined) durante el ciclo de vida del proceso.
     - Por consiguiente, el registro de envoltorios globales (`funciones_wpml.php`) deja de ser una alternancia dinámica dentro del mismo proceso para convertirse en una decisión determinista evaluada durante el arranque (*boot-time*).
     - Si el adaptador está desactivado (comportamiento por omisión de TF Multilingual), el archivo `funciones_wpml.php` **NUNCA** es requerido ni cargado, garantizando que `function_exists('icl_object_id')` e `function_exists('icl_get_languages')` retornen estrictamente `false`.
  2. **Salvaguardas de Detección de WPML Real y Auto-inhibición:**
     - Antes de registrar envoltorios globales o inicializar el adaptador, se ejecuta `detectar_presencia_wpml_real()`, verificando la existencia de la clase `SitePress`, las constantes `ICL_SITEPRESS_VERSION` o `WPML_PLUGIN_PATH`, o la definición preexistente de funciones sin nuestro centinela.
     - Si se detecta un WPML real o activo, el adaptador se auto-inhibe inmediatamente sin producir errores fatales (`$this->habilitado = false`, emitiendo advertencia de diagnóstico), cediendo el control total al plugin original sin colisiones de símbolos.
  3. **Salvaguardas contra Colisión de Símbolos Ajenos y Centinela de Integridad:**
     - Se incorporó la constante centinela `TFML_WPML_FUNCTIONS_LOADED` en `src/Compatibilidad/funciones_wpml.php`.
     - Si alguna función `icl_object_id` o `icl_get_languages` ya se encuentra declarada sin dicha constante centinela, el método `detectar_colision_simbolos()` detecta la colisión, auto-inhibe el adaptador y preserva la implementación preexistente intacta sin emitir fatal errors por redeclaración.
  4. **Configuración Jerárquica de Activación:**
     - La habilitación del adaptador puede ser controlada formalmente mediante:
       1. Constante de arranque: `define('TFML_ENABLE_WPML_COMPATIBILITY', true);` (ideal para `wp-config.php` y testing temprano).
       2. Opción de base de datos / repositorio de ajustes: `get_option('tfml_enable_wpml_compatibility_adapter', false)`.
       3. Filtro nativo de WordPress: `apply_filters('tfml_enable_wpml_compatibility_adapter', bool)`.
  5. **Verificación Formal en Procesos Aislados y Quality Gates:**
     - Se validó el aislamiento en 14 escenarios obligatorios independientes mediante subprocesos PHP CLI (`proc_open`) en `scratch/verify_fase_3_29.php`:
       - Escenario 1: TFML desactivado -> `function_exists('icl_object_id') === false`, `function_exists('icl_get_languages') === false`, `defined('ICL_LANGUAGE_CODE') === false`.
       - Escenario 2: API pública nativa (Superficie A) opera al 100% sin adaptador.
       - Escenario 3: Adaptador habilitado explícitamente -> funciones globales operan fielmente, contratos auditados pasan, `ICL_LANGUAGE_CODE` permanece NO definida, francés estrictamente excluido.
       - Escenario 4: WPML real presente simulado -> detección exitosa, adaptador se auto-inhibe, emite diagnóstico formal sin registrar wrappers.
       - Escenario 5: Símbolos preexistentes ajenos -> detección de colisión, auto-inhibición limpia sin fatal por redeclaración.
       - Escenarios Frontend HTTP: Verificación HTTP real contra `lab.ecoterra.test` (Portada `/`, `/old-blog/`, `/category/blog/`, `/tag/cusco/`, `/en/`, `/fr/`, >40KB render de tema).
     - Total: 79/79 controles superados con éxito (100% PASS).
     - Suite PHPUnit: 557 tests, 1923 aserciones PASS.
     - PHPCS: 174/174 archivos conformes WPCS (0 errores, 0 warnings).
  6. **Invarianza Estricta de Centinelas y Datos:**
     - Base de datos `cms_ect`: 3.403 registros en `etk_t_icl_translations`, checksum MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
     - Base de datos `cms_ect_lab`: 120 grupos y 133 elementos congelados intactos (Delta = 0).
     - TRID 197 no importado en esta fase.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.29 / Directiva DT-TFML-059 — Blindaje de activación y ausencia de efectos secundarios del adaptador de compatibilidad WPML.
- **JUSTIFICACIÓN:** Resolver categóricamente la observación de seguridad arquitectónica de la Fase 3.28, asegurando que cuando el adaptador WPML esté inactivo no exista residuo alguno en la tabla global de funciones de PHP (`function_exists` = false), garantizando coexistencia limpia sin efectos colaterales sobre temas u otros plugins.
- **CONSECUENCIAS:** Se alcanza un blindaje robusto del ciclo de vida del adaptador. La Superficie A permanece como la vía primaria y pura, mientras la Superficie B queda herméticamente sellada cuando no se requiere.

### ADR-056: Soporte Transaccional para Grupos WPML con Idiomas Inactivos Retenidos y Registro de Traducciones Retenidas (Fase 3.30)
- **FECHA:** 2026-10-11
- **DECISIÓN:**
  1. **Conservación de la Clasificación Histórica en Planes Maestros:**
     - `WpmlMigrationPlanner::generate_plan()` mantiene invariable la clasificación `BLOCKED_BY_LANGUAGE` para todos aquellos grupos del catálogo WPML que contengan elementos pertenecientes a idiomas inactivos (los 52 grupos detectados originalmente, incluido el TRID 197).
     - La clasificación `PARTIAL_RETAINED` no se asigna automáticamente ni de manera indiscriminada en el escaneo general para no mutar los fingerprints criptográficos de planes preexistentes.
  2. **Derivación Explícita y Trazable de Planes Parciales (`derive_partial_plan`):**
     - Se extiende `MigrationPlan::derive_partial_plan(array $selected_trids, string $label, array $authorized_retained)` permitiendo a un operador técnico autorizar formalmente la derivación de grupos clasificados originalmente como `BLOCKED_BY_LANGUAGE`.
     - Cuando se autoriza expresamente la exclusión controlada de idiomas inactivos (ej. retención de francés en TRID 197), el grupo derivado adquiere formalmente la clasificación `MigrationPlan::CLASSIFICATION_PARTIAL_RETAINED` ('PARTIAL_RETAINED').
     - Los miembros en idiomas activos (ej. ES:197, EN:8356) se conservan en `$group['translations']` para su ejecución normal en TFML (`tfml_groups` y `tfml_group_elements`).
     - Los miembros en idiomas inactivos (ej. FR:8839) se preservan intactos en `$group['pending_translations']` como traducciones retenidas.
     - Se implementa el helper tipado `derive_partial_plan_with_retained_languages()` para simplificar y blindar la invocación.
  3. **Componente Persistente y Auditable: `RetainedTranslationRegistry`:**
     - Para no contaminar las 5 tablas canónicas de TFML (preservando `tfml_schema_version = '1.0.0'` sin alteraciones DDL), se implementa `RetainedTranslationRegistry`.
     - Almacena el inventario de elementos retenidos en la Options API de WordPress (`tfml_retained_translations`), indexado por fuente (`wpml`), identificador de origen (`source_trid`), ID de grupo TFML asignado (`tfml_group_id`), tipo/subtipo y mapa de traducciones retenidas (`pending_translations`).
     - Valida colisiones relacionales y unicidad: rechaza que un mismo elemento retenido sea registrado más de una vez en grupos distintos.
     - Calcula una huella criptográfica determinista SHA-256 (`fingerprint`) para auditar la integridad del registro.
     - Proporciona el mecanismo de promoción controlada `promote_retained_translation($group_id, $language_code, ...)` para cuando en el futuro se active formalmente un idioma inactivo en `LanguageRegistry`.
     - Proporciona `remove_by_group_id($group_id)` para limpieza atómica durante operaciones de reversión/rollback.
  4. **Adaptación Transaccional del Ejecutor (`WpmlMigrationExecutor`):**
     - Se inyecta `RetainedTranslationRegistry` en el ejecutor.
     - En `validate_plan()`: admite formalmente grupos con clasificación `PARTIAL_RETAINED` en planes parciales/derivados.
     - En `execute_batch()`: procesa grupos `PARTIAL_RETAINED`, transfiriendo `$group['pending_translations']` al método de inserción.
     - En `import_group()`: registra de forma idempotente las traducciones retenidas en `RetainedTranslationRegistry`.
     - En `run()`: contabiliza `imported_partial_retained` en el resumen global y persiste el mapa de retenidas en el manifiesto de lote (`tfml_migration_manifest_{token}`).
     - En `reconcile()`: verifica que los miembros activos estén en `tfml_group_elements`, que los miembros retenidos **NO** hayan sido insertados en la base de datos relacional de TFML, y que existan debidamente auditados en `RetainedTranslationRegistry`.
  5. **Integración Atómica en el Servicio de Rollback (`WpmlMigrationRollbackService`):**
     - Se inyecta `RetainedTranslationRegistry` en el servicio de reversión.
     - Al revertir grupos migrados con traducciones retenidas, `rollback_groups()` invoca `$this->retained_registry->remove_by_group_id($group_id)`, eliminando atómicamente el registro retenido de la Options API sin dejar estados huérfanos.
  6. **Contrato Técnico y Restricción Estricta de No-Ejecución:**
     - En cumplimiento de `DT-TFML-060`, **NO** se ejecutó la importación real del TRID 197 sobre la base de datos de laboratorio `cms_ect_lab`.
     - El baseline permanece congelado en 120 grupos y 133 elementos (Delta = 0).
     - La base de datos de origen `cms_ect` permanece estrictamente invariante en 3.403 registros y MD5 `4241ca7e7ec6399a594537cb04790c10` (Delta = 0).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.30 / Directiva DT-TFML-060 — Soporte transaccional para grupos WPML con idiomas inactivos retenidos y registro de traducciones retenidas.
- **JUSTIFICACIÓN:** Permitir el rescate técnico e importación segura de grupos históricos con mezclas de idiomas activos e inactivos (como la portada TRID 197: ES/EN activos, FR inactivo) sin corromper la integridad referencial de TFML ni perder la trazabilidad de los contenidos en idiomas no habilitados.
- **CONSECUENCIAS:** Queda habilitada la capacidad técnica para importar grupos parciales retenidos de manera transaccional, con soporte completo de verificación, reconciliación, idempotencia, rollback atómico y futura promoción cuando los idiomas secundarios se activen.

### ADR-057: Blindaje Transaccional, Consistencia de Caché ante Rollback SQL y Certificación de Recuperación de Traducciones Retenidas (Fase 3.31)
- **FECHA:** 2026-10-13
- **DECISIÓN:**
  1. **Unificación de Conexión Transaccional (`$wpdb`) y Persistencia SQL Directa sobre `{$db->prefix}options` (InnoDB):**
     - `RetainedTranslationRegistry`, `MigrationCheckpointRepository`, `MigrationExecutionLock` y `WpmlMigrationRollbackService` reciben e inyectan la misma instancia compartida de `$wpdb` (`$this->db`) utilizada por `TranslationGroupRepository`.
     - Cuando existe una conexión MySQL activa (`has_real_db()`), las operaciones de lectura, escritura y borrado de `tfml_retained_translations`, `tfml_migration_checkpoint_wpml`, `tfml_migration_lock_wpml` y `tfml_migration_manifest_{token}` se ejecutan mediante SQL parametrizado (`SELECT`, `INSERT ... ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, `DELETE`) sobre `{$this->db->prefix}options` (motor **InnoDB** certificado en `cms_ect_lab`), participando exactamente en la misma transacción `START TRANSACTION` / `SAVEPOINT` / `COMMIT` / `ROLLBACK` que `tfml_groups` y `tfml_group_elements`.
     - En entornos de pruebas unitarias puras en memoria sin conexión MySQL activa, se preserva el fallback automático a la Options API de WordPress.
  2. **Eliminación de Lecturas Sucias en `WP_Object_Cache` tras `ROLLBACK` SQL:**
     - `TranslationGroupRepository::rollback_transaction()`, `RetainedTranslationRegistry::invalidate_option_cache()`, `MigrationCheckpointRepository` y `WpmlMigrationRollbackService` invalidan explícitamente las claves de `WP_Object_Cache` (`tfml_retained_translations`, `tfml_migration_checkpoint_wpml`, `alloptions`, `notoptions` y manifiestos) tras cualquier escritura o `ROLLBACK` SQL, impidiendo que la memoria caché de WordPress retenga estados de transacciones abortadas.
  3. **Verificación Post-Escritura de Estado Persistido (Resolución de la Ambigüedad de `update_option() === false`):**
     - En lugar de depender del valor booleano de retorno de `update_option()` (que devuelve `false` tanto cuando el valor no cambia como cuando ocurre un error), `RetainedTranslationRegistry::persist_and_verify()`, `MigrationCheckpointRepository::save()` y `WpmlMigrationRollbackService::save_execution_manifest()` verifican `'' === $this->db->last_error` y releen el estado persistido comparando su huella criptográfica SHA-256 (`compute_collection_fingerprint()`). Si el estado persistido diverge, se lanza `MigrationExecutionException` dentro de la transacción activa para forzar `ROLLBACK`.
  4. **Inclusión de Checkpoint y Manifiesto dentro de la Frontera Transaccional de `execute_batch()` y Transacción en `promote_retained_translation()`:**
     - En `WpmlMigrationExecutor::execute_batch()`, la persistencia del checkpoint (`$this->checkpoint_repository->save()`) y del manifiesto de lote (`$this->persist_batch_manifest()`) se ejecutan **dentro** del bloque transaccional antes de `commit_transaction()`. Cualquier fallo en `tfml_groups`, `tfml_group_elements`, `RetainedTranslationRegistry`, manifiesto o checkpoint revierte atómicamente todas las escrituras del lote.
     - `RetainedTranslationRegistry::promote_retained_translation()` envuelve la inserción en `tfml_group_elements` y la purga/actualización del registro retenido dentro de `$group_repository->begin_transaction()` / `commit_transaction()` / `rollback_transaction()`, garantizando que ningún elemento pueda quedar simultáneamente como activo y retenido si falla la actualización del registro.
  5. **Clarificación Formal sobre Alcance de Migraciones DDL:**
     - No se introduce una sexta tabla DDL ni se altera `tfml_schema_version = '1.0.0'`. La directiva de arquitectura congeló el esquema relacional de TFML en 5 tablas maestras (`tfml_groups`, `tfml_group_elements`, `tfml_media_translations`, `tfml_strings`, `tfml_string_translations`), y la tabla `etk_t_options` en `cms_ect_lab` utiliza motor transaccional **InnoDB**. Al operar por SQL transaccional sobre la misma conexión `$wpdb` contra `{$this->db->prefix}options` con invalidación de caché y verificación de huella SHA-256 post-escritura, se satisfacen íntegramente los 9 requisitos de persistencia de `DT-TFML-061` sin ejecutar DDL no autorizado sobre `cms_ect_lab`.
  6. **Certificación Empírica en Base de Datos Efímera Aislada (`cms_ect_lab_tx_test`) e Invarianza de Laboratorio:**
     - Los 15 escenarios obligatorios de fallo inducido, concurrencia, recuperación, promoción, reconciliación y rollback se certificaron tanto en PHPUnit como en una base de datos MySQL InnoDB efímera y aislada (`cms_ect_lab_tx_test`), eliminada al concluir (`DROP DATABASE`), manteniendo intactas `cms_ect` (3.403 filas, MD5 `4241ca7e7ec6399a594537cb04790c10`) y `cms_ect_lab` (120 grupos, 133 elementos, TRID 197 no importado).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.31 / Directiva DT-TFML-061 — Blindaje transaccional y certificación de recuperación de traducciones retenidas.
- **JUSTIFICACIÓN:** Garantizar atomicidad transaccional real en MySQL InnoDB antes de autorizar en Fase 3.32 la importación controlada del TRID 197 (`ES:197`, `EN:8356`, `FR:8839` retenido) en `cms_ect_lab`.
- **CONSECUENCIAS:** El subsistema de migración, retención, promoción, reconciliación y rollback queda blindado frente a fallos parciales, interrupciones y lecturas sucias de caché, dejando lista la puerta de entrada para la Fase 3.32.

---

### ADR-058: Importación Piloto Controlada del Grupo de Portada Bilingüe TRID 197 con Retención del Miembro Francés y Separación del Enrutamiento de `/en/` (Fase 3.32)
- **FECHA:** 2026-10-13
- **DECISIÓN:**
  1. **Conciliación Documentada de Observaciones de Fase 3.31 (`DT-TFML-061`):**
     - **Métricas PHPUnit:** El paso de `570` pruebas totales en Fase 3.30 (`569` ejecutadas + `1` omitida de integración) a `580` pruebas totales en Fase 3.31 (`579` ejecutadas + `1` omitida, `2.053` aserciones) corresponde exactamente a los **10 métodos `test_*`** añadidos en `TransactionalRetainedRecoveryTest.php`, los cuales agruparon los 15 escenarios de fallo inducido sin eliminar, renombrar ni sustituir ninguna prueba previa.
     - **Límites Reales de las Pruebas de Concurrencia:** La certificación en MySQL de `MigrationExecutionLock` valida la exclusión mutua cooperativa mediante `INSERT IGNORE INTO {$prefix}options` sobre InnoDB y la comprobación de titularidad del *fencing token* (`verify_ownership`) antes de `COMMIT`, pero no equivale a una certificación universal de contención multiproceso a nivel de motor ni protege frente a escrituras SQL externas que omitan el ejecutor oficial.
     - **Procedencia y Restaurabilidad de Respaldos SQL:** Se concilió la ruta real del respaldo histórico inicial (`D:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql`, creado el `2026-10-11 13:24:35`, SHA-256 `f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a`) y se generó un respaldo independiente pre-piloto de `cms_ect_lab` con fecha y hora reales (`D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_32_pilot_trid197_20261013_233600.sql`, `17.597.240` bytes, SHA-256 `8b40a59daac02844b4a6c7d1414108db2b8836383f4ca2978dc9d141fa74c515`, asociado al baseline `a9ea9cd2c6854132c82a1deadbbb4aeeaac630f7`), validando su restauración completa en una base de datos desechable (`cms_ect_lab_restore_verify`, posteriormente eliminada) antes de ejecutar cualquier escritura en `cms_ect_lab`.
  2. **Derivación y Ejecución Piloto Estrictamente Acotada al `TRID 197` (`pilot-trid-197-partial`):**
     - A partir del plan maestro de WPML (`fingerprint = 08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949`), se derivó mediante `derive_partial_plan_with_retained_languages()` el plan parcial `pilot-trid-197-partial` (`fingerprint = cb9c45034eb0117cd619ce215394a801dfcafe9243a839f355d5896d9e0d17d7`), autorizando exclusivamente el `TRID 197` con los idiomas activos `es` (`197`, canónico) y `en` (`8356`, traducción activa) y reteniendo el idioma inactivo `fr` (`8839`).
     - Ejecutado transaccionalmente mediante `WpmlMigrationExecutor::run()` en `cms_ect_lab`, creando el grupo TFML **`group_id = 587`** (`element_type = 'post'`, `subtype = 'page'`, `canonical_element_id = 197`), los elementos activos `673` (`197 -> es`) y `674` (`8356 -> en`), y el registro retenido `wpml:197` en `RetainedTranslationRegistry` (`group_id = 587`, `fr => 8839`, `fingerprint = 723016cbe0a5ee2044468f882a5f81f9cc3c4154b07c3c7acb18efc3adc81260`), junto con el manifiesto `tfml_migration_manifest_pilot-trid-197-partial` y el checkpoint `completed` (`last_processed_trid = 197`).
  3. **Consolidación de Métricas, Reconciliación e Idempotencia en `cms_ect_lab`:**
     - El estado oficial del laboratorio pasa de `120` a **`121` grupos TFML** y de `133` a **`135` elementos activos TFML**, más **`1` traducción retenida** (`fr:8839`), con `0` duplicados y `0` discrepancias tanto en la reconciliación del lote piloto como en la reconciliación de los `121` grupos y `135` elementos acumulados.
     - La reejecución controlada del lote `pilot-trid-197-partial` certificó idempotencia total (`status = idempotent_skipped`, `imported_groups = 0`, `skipped_groups = 1`, preservación de `group_id = 587` y del fingerprint del registro retenido).
  4. **Separación Arquitectónica entre Persistencia de Datos y Enrutamiento de Portada (`/en/`):**
     - Tras importar el `TRID 197`, `ContentTranslationResolver` y `ServicioTraduccionPublico` resuelven bidireccionalmente `197 (es) <-> 8356 (en)` y la portada en español (`http://lab.ecoterra.test/`) emite automáticamente las etiquetas `<link rel="alternate" hreflang="en" href="http://lab.ecoterra.test/en/home/" />`.
     - Conforme a la restricción expresa de `DT-TFML-062`, no se modificaron `page_on_front`, `option_page_on_front`, `pre_get_posts` ni `RewriteManager` en esta fase. La ruta `http://lab.ecoterra.test/en/` permanece en `HTTP 404` (`lang="en-US"`, `0` errores PHP) y queda formalmente clasificada como **`BLOCKED BY ROUTING`** para ser abordada en una directiva dedicada exclusivamente al enrutamiento de la portada secundaria.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.32 / Directiva DT-TFML-062 — Importación piloto controlada de la portada bilingüe en laboratorio.
- **JUSTIFICACIÓN:** Aislar la operación de datos (migración parcial transaccional del `TRID 197` con retención del miembro francés) respecto de la lógica de intercepción de consulta principal de WordPress para portadas estáticas traducidas, garantizando trazabilidad y verificación independiente de cada capa.
- **CONSECUENCIAS:** El grupo de portada `TRID 197` queda persistido, reconciliado y resoluble en el dominio de TFML (`group_id = 587`, `121` grupos / `135` elementos activos / `1` retenido), dejando lista la base de datos de laboratorio para abordar limpiamente en la siguiente fase la resolución de enrutamiento de `/en/`.

---

### ADR-059: Resolución Nativa de Portada Estática Multilingüe, Filtrado Dinámico de `option_rewrite_rules`, Política Estricta de No-Fallback y Normalización SEO Canónica (Fase 3.33)
- **FECHA:** 2026-10-14
- **DECISIÓN:**
  1. **Diagnóstico de las 4 Causas Raíz de Portada Estática en WordPress Core:**
     - **Causa Raíz 1 (`WP_Rewrite::wp_rewrite_rules()` en `wp-includes/class-wp-rewrite.php`):** En peticiones HTTP ordinarias, WordPress lee las reglas desde `get_option( 'rewrite_rules' )` y solo ejecuta `rewrite_rules_array` cuando la opción está vacía o se invoca `flush_rewrite_rules()`. Como TFML prohíbe ejecutar `flush_rewrite_rules()` en `init`, es indispensable enganchar también `option_rewrite_rules` en `RewriteManager` con guarda de idempotencia (`isset( $existing_rules[ "^({$lang_regex})/?$" ] )`) para inyectar en memoria la regla raíz `^(en)/?$ => index.php?tfml_lang=$matches[1]` y las reglas prefijadas sin mutar `wp_options`.
     - **Causa Raíz 2 (`WP_Query::parse_query()` en `wp-includes/class-wp-query.php` líneas 1058–1077):** WordPress Core solo promueve `is_home` a consulta de página estática (`is_page = true`, `is_home = false`, `page_id = get_option('page_on_front')`) cuando `! array_diff( array_keys( $_query ), array( 'preview', 'page', 'paged', 'cpage' ) )`. Cuando se solicita `/en/`, `$_query` contiene `'tfml_lang' => 'en'`, por lo que `array_diff` no está vacío y WordPress Core omite la promoción, dejando `is_home = true` (archivo de entradas).
     - **Causa Raíz 3 (`get_option( 'page_on_front' )` y `QueryLanguageFilter`):** Sin filtrado en tiempo de ejecución de `option_page_on_front`, WordPress evalúa `page_on_front = 197` (español), el cual es rechazado legítimamente en `/en/` por `QueryLanguageFilter` (`AND tfml_ge.language_code = 'en'`).
     - **Causa Raíz 4 (`redirect_canonical()` en `wp-includes/canonical.php` líneas 264–267 y `get_page_link()` en `wp-includes/link-template.php`):** Cuando `is_page()` coincide con `get_option('page_on_front')`, WordPress Core fuerza `$redirect_url = home_url('/')` (lo que redirigiría `/en/` de vuelta a `/`), mientras que `get_page_link( 8356 )` invocado desde `/` evalúa `8356 != 197` y construye `/home/` (que `LocalizedUrlGenerator` prefijaba como `/en/home/`).
  2. **Diseño e Integración del Componente Soberano `ResolvedorPortadaMultilingue` (`src/Routing/ResolvedorPortadaMultilingue.php`):**
     - Implementado íntegramente con nomenclatura en español conforme a la regla de gobernanza para componentes nuevos, sin alterar el valor persistido de `page_on_front` (`197`) ni `show_on_front` (`page`) en `wp_options`, y sin cablear ningún ID (`197`, `8356`, `8839`) ni slug (`inicio`, `home`, `accueil`).
     - **Filtrado contextual de `option_page_on_front` (`filtrar_opcion_page_on_front`):** En contextos públicos elegibles, cuando el idioma activo es secundario y posee traducción publicada (`post_status === 'publish'`) en el grupo de `page_on_front`, devuelve el ID traducido (`8356` en `'en'`). Incluye semáforo antirrecursión (`$en_resolucion_opcion`) y aislamiento estricto de `is_admin()`, `REST_REQUEST` / `wp_is_json_request()`, `wp_doing_cron()`, `wp_doing_ajax()` y `WP_CLI`.
     - **Normalización de consulta principal (`normalizar_consulta_principal_portada` en `parse_query` y `pre_get_posts` prioridad 5):** Cuando la consulta principal corresponde exclusivamente a la raíz de un idioma activo (`/` o `/{lang}/`, con soporte de paginación `page`/`paged`), promueve `is_page = true`, `is_home = false`, `is_singular = true` y asigna `page_id` al ID de la portada publicada de dicho idioma.
  3. **Política Explícita de Ausencia de Traducción de Portada (Cero Fallback Silencioso):**
     - Si un idioma secundario está activo pero carece de traducción de `page_on_front` o su traducción no está en estado `publish` (`draft`, `pending`, `private`, `trash`), `ResolvedorPortadaMultilingue` NO realiza fallback a la portada en español ni degrada a archivo de entradas: mantiene `page_id = $id_base` y marca `_tfml_portada_traducida_ausente = true`, de modo que `QueryLanguageFilter` (`AND tfml_ge.language_code = '{lang}'`) produce `0` filas y WordPress emite un `HTTP 404` limpio (`is_404() === true`, `is_front_page() === false`), sin emitir canonical ni hreflang engañosos.
     - Adicionalmente, en `filtrar_redirect_canonical()`, cuando `is_404()` es `true` en un idioma secundario activo, se cancela cualquier redirección adivinada por `redirect_guess_404_permalink()` hacia el idioma por defecto (como `/en/old-blog/` -> `/old-blog/`).
  4. **Normalización Integral de Enlaces de Portada, Canonical, Hreflang, Sitemaps, Menús y Redirección 301:**
     - `ResolvedorPortadaMultilingue::filtrar_enlace_pagina()` en `page_link` y `LocalizedUrlGenerator::get_post_translation_url()` normalizan cualquier portada estática activa a `home_url( $codigo_idioma )` (`http://lab.ecoterra.test/` para `es`, `http://lab.ecoterra.test/en/` para `en`).
     - `CanonicalUrlManager` devuelve `''` en `is_404()` y normaliza el canonical de toda portada estática a `home_url( $lang_code )`.
     - `HreflangGenerator` delega `get_front_page_hreflang_variants()` en `get_post_hreflang_variants( $page_on_front )` cuando `show_on_front === 'page'` y normaliza las URLs de portada a `home_url( $lang_code )`, emitiendo simétricamente `es -> http://lab.ecoterra.test/`, `en -> http://lab.ecoterra.test/en/` y `x-default -> http://lab.ecoterra.test/` tanto en `/` como en `/en/`, y omitiendo cualquier idioma sin traducción publicada o inactivo (`fr`).
     - `CoreSitemapsFilter` y `NavMenuFrontendFilter` normalizan las entradas de portada estática a `home_url( $lang_code )`.
     - `ResolvedorPortadaMultilingue::filtrar_redirect_canonical()` evita que `/en/` sea redirigido a `/` y redirige con `HTTP 301` cualquier acceso directo al slug de la portada secundaria (`/en/home/` -> `http://lab.ecoterra.test/en/`, preservando query string).
  5. **Revisión de Diseño del Checkpoint Global (`tfml_migration_checkpoint_wpml`):**
     - Se documenta formalmente que `tfml_migration_checkpoint_wpml` actúa como registro de estado y reanudación de la última corrida ejecutada (`pilot-trid-197-partial`), mientras que la auditoría histórica acumulativa de todos los lotes reside intacta en los `12` manifiestos individuales `tfml_migration_manifest_{batch_token}` en `etk_t_options`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.33 / Directiva DT-TFML-063 — Resolución nativa de portada multilingüe, enrutamiento y SEO canónico.
- **JUSTIFICACIÓN:** Resolver la brecha funcional `BLOCKED BY ROUTING` de `/en/` de forma genérica, limpia y alineada con el núcleo de WordPress, sin tocar la base de datos ni introducir lógica ad-hoc dependiente del sitio.
---

### ADR-060: Restauración Controlada del Renderizado Visual, Integración de Dependencias Críticas y Certificación de Compatibilidad en Laboratorio Aislado (Fase 3.34)
- **FECHA:** 2026-10-17
- **DECISIÓN:**
  1. **Diagnóstico de la Brecha de Renderizado Visual en Laboratorio Aislado (`lab.ecoterra.test`):**
     - En fases previas, la portada en el laboratorio presentaba una estructura básica con marcado textual crudo del shortcode `[rev_slider alias="home-es-1"]` y ausencia de cabecera y pie avanzados.
     - Se identificó que, aunque `active_plugins` en `cms_ect_lab` listaba los plugins heredados, los directorios físicos de `elementor`, `elementor-pro` y `revslider` nunca habían sido copiados a `D:\laragon\www\lab.ecoterra\wp-content\plugins\`. WordPress ignoraba silenciosamente estas dependencias en `wp-settings.php`.
     - Adicionalmente, Slider Revolution 7.0 requería 16 tablas de soporte `etk_t_revslider_*` que no formaban parte del volcado inicial de base de datos del laboratorio.
     - El selector de idiomas de la cabecera del tema `ecoCMS` (`[top-idiomas]`) y las rutinas de traducción de cabeceras/pies (`qomfort_render_header()`) invocaban llamadas condicionales a `icl_get_languages()` e `icl_object_id()`, las cuales dependían de la activación explícita del adaptador limitado de compatibilidad WPML implementado en Fase 3.28/3.29 (`AdaptadorCompatibilidadWpml`).
  2. **Procedimiento de Respaldo Previo y Restauración Verificable:**
     - Siguiendo los estándares de gobernanza y recuperación ante desastres, se generó el respaldo previo de la base de datos de laboratorio en `D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_34_visual_restoration_20261017_125254.sql` (16.79 MB, SHA-256: `51b5cb3c413e4595caba684dc4ae430aa7be9851ec76b842f8876ed0556d1715`).
     - Se generó el archivo de metadatos `cms_ect_lab_pre_fase_3_34_visual_restoration_20261017_125254.meta.json` vinculando el micro-baseline oficial de entrada (`f554595110712228a73dda1a17322cbc098ae864`).
     - Se certificó la restauración verificable en la base de datos efímera `cms_ect_lab_restore_verify` (46 tablas, 121 grupos TFML, 135 elementos, 1 retenida, centinela WPML intacto), procediendo a su eliminación limpia inmediata.
     - Se certificó cero escrituras en la base de datos protegida original `cms_ect` (`3,403` filas, MD5 `4241ca7e7ec6399a594537cb04790c10`).
  3. **Transferencia Controlada de Dependencias Físicas y Tablas de Soporte:**
     - Se realizó la copia física controlada desde `cms.ecoterra` hacia `lab.ecoterra` de las dependencias estrictamente imprescindibles: `elementor` (v4.3.5), `elementor-pro` (v4.1.0) y `revslider` (v7.0.15), uniéndose al previamente auditado `ova-framework` (v1.0.0).
     - Se transfirieron las 16 tablas `etk_t_revslider_*` mediante lectura no destructiva desde `cms_ect` hacia `cms_ect_lab`, garantizando cero impacto en las tablas Core, WPML o TFML.
     - Se mantuvo en cuarentena estricta cualquier plugin prescindible o peligroso (`sitepress-multilingual-cms`, `duplicator`, `akismet`, etc.) y se mantuvo activo el blindaje `mu-plugins/lab-isolation-guard.php` (`DISABLE_WP_CRON = true`, `WP_HTTP_BLOCK_EXTERNAL = true`, `blog_public = 0`).
  4. **Activación Determinista del Adaptador de Compatibilidad WPML en Laboratorio:**
     - Se configuró la opción `tfml_enable_wpml_compatibility_adapter = '1'` en `etk_t_options` de `cms_ect_lab`.
     - `AdaptadorCompatibilidadWpml` inicializa de forma segura los envoltorios globales `icl_get_languages()` e `icl_object_id()`.
     - `icl_get_languages()` expone exclusivamente los idiomas activos (`es`, `en`, `pt`), omitiendo con rigor la traducción francesa retenida (`fr:8839`).
     - Se certifica que la constante global `ICL_LANGUAGE_CODE` permanece **estrictamente no definida** (`defined('ICL_LANGUAGE_CODE') === false`), preservando la pureza contextual de la arquitectura TFML.
  5. **Certificación del Renderizado Visual Completo en Portadas ES (`/`) y EN (`/en/`):**
     - El tamaño HTML de respuesta creció de ~20 KB a ~120-130 KB, certificando la ejecución completa de Elementor Builder, secciones y columnas.
     - Se renderizó la cabecera fija (`_header_sticky`), el logotipo oficial y la navegación principal bilingüe (`menu-menu-principal` en español y `menu-menu-es-ingles` en inglés).
     - El selector de idiomas (`[top-idiomas]`) renderiza los enlaces con banderas e indicador `activo` exacto según la ruta solicitada.
     - Slider Revolution (`<sr7-module data-alias="home-es-1">`) renderiza la estructura HTML/JS completa, eliminando cualquier fuga textual del shortcode crudo.
     - Se eliminó la caché transitoria obsoleta `_elementor_element_cache` en `etk_t_postmeta` (generada previamente a la carga de Slider Revolution) para garantizar la hidratación visual inmediata.
     - Se encolaron más de 25 hojas de estilo CSS y más de 25 scripts JS propios de Elementor y Slider Revolution.
     - La respuesta HTTP en vivo sobre `http://lab.ecoterra.test/` y `http://lab.ecoterra.test/en/` emitió código `200` y `0` errores PHP.
  6. **Preservación Estricta de la Invarianza de Datos y Calidad de Código:**
     - La base de datos de laboratorio conserva intactos sus 121 grupos TFML, 135 elementos activos, el grupo 587 (197:es, 8356:en), la traducción retenida `fr:8839` y los 12 manifiestos históricos.
     - Las pruebas automáticas de la suite PHPUnit (603 tests, 2.160 aserciones, 0 fallos), PHPCS (180/180 archivos PASS) y el verificador específico de Fase 3.34 (`scratch/verify_fase_3_34.php`: 22/22 controles PASS) ratifican la estabilidad integral del sistema.
  7. **Subsanación de Evidencias Documentales, Trazabilidad Git y Auditoría Criptográfica de Tablas (`DT-TFML-064A` — 2026-10-18):**
     - **Conciliación de Commit SHA:** Se deja constancia formal de que el commit real en Git y GitHub correspondiente a la documentación inicial de la Fase 3.34 es `518a144cf08ec4138ca1c86e818783c939b17d40` (y no el hash inexistente `518a1444d3ba9f032aa2f1d93540be774bc07452` reportado inicialmente por extrapolación errónea del hash abreviado `518a144`).
     - **Versionado del Verificador Reproducible:** Se actualizó `.gitignore` añadiendo la excepción explícita `!/scratch/verify_fase_3_34.php` (homologando el criterio de `verify_fase_3_30.php` a `verify_fase_3_33.php`), incorporando al repositorio el script de solo lectura `scratch/verify_fase_3_34.php` (`22/22 PASS`).
     - **Auditoría Criptográfica de las 16 Tablas `etk_t_revslider_*`:** Se verificó mediante `COUNT(*)` y `CHECKSUM TABLE` (CRC32 MySQL) la paridad al 100% entre `cms_ect` (leída en modo estrictamente no destructivo) y `cms_ect_lab` en las 16 tablas transferidas (`163` filas totales combinadas, `0` discrepancias de checksum).
     - **Frontera de Verificación Visual:** Se distingue taxativamente entre la verificación HTTP/DOM/assets en servidor (`PASS` en los 22 controles) y la renderización visual interactiva en navegador real con motor JavaScript para `<sr7-module>` y viewports responsivos (`NOT_TESTED` en entorno CLI sin navegador gráfico).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.34 / Directivas DT-TFML-064 y DT-TFML-064A — Restauración controlada del renderizado visual, auditoría de integraciones y subsanación formal de trazabilidad.
- **JUSTIFICACIÓN:** Certificar la convivencia armónica entre TF Multilingual y el stack de maquetación y diseño visual real del tema `ecoCMS` (Elementor, Elementor Pro, Slider Revolution y OvaFramework), demostrando fidelidad estructural, aislamiento de seguridad y trazabilidad exacta en el repositorio Git.
- **CONSECUENCIAS:** El laboratorio `lab.ecoterra.test` conserva su estado funcional restaurado (`22/22 PASS`), con el verificador `scratch/verify_fase_3_34.php` versionado en GitHub y la trazabilidad documental y criptográfica 100% conciliada.

---

### ADR-061: Certificación Visual, Interactiva y Lingüística del Frontend en Navegador Real y Delimitación de Responsabilidades TFML vs `ecoCMS` (Fase 3.35)
- **FECHA:** 2026-10-18
- **DECISIÓN:**
  1. **Auditoría Visual e Interactiva en Navegador Real (Microsoft Edge Chromium vía CDP en `1440x900` y `390x844`):**
     - Se ejecutó la inspección real en navegador con motor Chromium/V8 (`C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe` v155.0.4283.45 vía Chrome DevTools Protocol) sobre `http://lab.ecoterra.test/` y `http://lab.ecoterra.test/en/` en resoluciones de escritorio (`1440x900`) y móvil (`390x844`), capturando 10 evidencias gráficas e inspeccionando consola JS, excepciones en runtime, recursos de red, DOM vivo, dimensiones y estilos computados.
     - **Slider Revolution 7.0 (`home-es-1`, ID `6`):** Se certifica con estado `PASS` la inicialización interactiva en cliente (`typeof window.SR7 === 'object'`, módulo `SR7.M['SR7_6_1']` inicializado con `currentSlide = '1'`, lienzo `<canvas width="1430" height="900">` pintado, `3` nodos `<sr7-slide>` en DOM y `0` excepciones JS no capturadas). Se documenta como incidencia del contenido del slider que el slide `27` referencia en cliente un vídeo externo de YouTube (`https://www.youtube.com/embed/AesP9IqMQw8...`) y una imagen demo externa no resoluble (`http://demo.arrowpress.net/solaz/wp-content/uploads/2017/01/slider_04-1.jpg` -> `ERR_NAME_NOT_RESOLVED`).
     - **Menú Móvil Off-Canvas (`390x844`):** Se certifica con estado `PASS` el comportamiento JavaScript interactivo (`ecoCMS/assets/js/elementor/menu-canvas.js` alterna la clase `.toggled` sobre `<nav class="menu-canvas">` al pulsar `button.menu-toggle` y la retira al pulsar `.close-menu`). Se identifica como incidencia de configuración del widget Elementor (`qomfort_elementor_menu_canvas` en `4804` y `8383`) que el ajuste `"menu_slug": "primary"` no coincide con ningún menú ni ubicación registrada, provocando que `wp_nav_menu()` degrade a `wp_page_menu()`, listando `61` páginas en `/` y `1` página (`Home`) en `/en/`.
  2. **Clasificación de Recursos Visuales Faltantes (`HTTP 404` en Imágenes e Iconografía):**
     - **Banderas del Adaptador WPML (Responsabilidad de TFML — P1):** `AdaptadorCompatibilidadWpml::obtener_url_bandera()` genera por defecto `/wp-content/plugins/tf-multilingual/assets/images/flags/{es,en,pt}.png`, ruta cuyo directorio físico no existe aún en el plugin (`HTTP 404` en las 3 imágenes de bandera del selector `[top-idiomas]`).
     - **Biblioteca de Medios Parcial en Laboratorio (Provisión del Laboratorio — P2):** El directorio `D:\laragon\www\lab.ecoterra\wp-content\uploads` no incluye aún las subcarpetas `2023/10`, `2025/08` y `2026/06` de `cms.ecoterra`, ocasionando `HTTP 404` en los SVGs de logotipo (`Logo-Header-1.svg`, `Logo-Ecoterra.svg`) y miniaturas de habitaciones/actividades.
     - **Iconos de Barra Social Flotante (`[redes_sociales]` — Responsabilidad CSS del Tema `ecoCMS` — P2):** Una regla tipográfica del tema sobrescribe `font-family: "Fira Sans Condensed"` sobre `<i class="fab fa-*">` dentro de `.wpa-sidebar-socials`, impidiendo que se aplique `"Font Awesome 5 Brands"`.
  3. **Auditoría de Navegación Bilingüe y Enlaces de Menú Desktop:**
     - Las cabeceras `Header Home - ES` (`4804`) y `Header Home - EN` (`8383`) se cargan correctamente por el metadato de página `ova_met_header_version` (`ova,header-home-es` en `197` y `ova,header-home-en` en `8356`), renderizando los 16 ítems de `Menu Principal` (`43`) en `/` y los 16 ítems de `Menu - ES - Inglés` (`98`) en `/en/`.
     - Las 14 páginas internas enlazadas por dichos menús (distintas de `197` y `8356`, tales como `Nosotros`/`About Us`, `Actividades`/`Activities`, etc.) pertenecen a los 51 grupos restantes `BLOCKED_BY_LANGUAGE` en WPML por tener traducción francesa (`fr`). Al no haber sido migradas aún a `tfml_group_elements` (`TFML_lang = NULL`), `get_permalink()` sobre las páginas inglesas no recibe el prefijo `/en/` (generando `/about-us/`, `/activities/` con `HTTP 200`, mientras que `/en/about-us/` responde `HTTP 404`).
     - Los 3 botones `"Descubre"` / `"Discover"` del cuerpo de las portadas `197` y `8356` tienen URLs absolutas del dominio origen (`http://cms.ecoterra.test/...`) persistidas en `_elementor_data`.
  4. **Auditoría Específica del Idioma Portugués (`pt`):**
     - `pt` (`Português`, `pt_PT`) se encuentra registrado y activo en `LanguageRegistry` (`active: true, order: 3`), pero posee **`0` elementos traducidos** en `etk_t_tfml_group_elements` y **`0` filas** en `etk_t_icl_translations`.
     - Por estar activo en `LanguageRegistry`, `AdaptadorCompatibilidadWpml::ejecutar_icl_get_languages()` lo expone en el selector `[top-idiomas]` apuntando a `http://lab.ecoterra.test/pt/`. Al solicitar `GET /pt/`, `ResolvedorPortadaMultilingue` aplica la política estricta de **cero fallback silencioso** y devuelve **`HTTP 404`** limpio (`lang="pt-PT"`), mientras que `HreflangGenerator` omite correctamente `pt` en `<head>`. Se recomienda desactivar `pt` en `LanguageRegistry` mientras no exista contenido traducido al portugués.
  5. **Delimitación de Textos en Español en `/en/` (`traducir()` vs Contenido Editorial vs Loops No Migrados):**
     - Se certifica que `defined('ICL_LANGUAGE_CODE') === false` se mantiene intacto conforme a `ADR-060`.
     - Se clasifican las 4 fuentes de textos en español o mixtos observados en `/en/`:
       1. **Fallback de `traducir()` y `ICL_LANGUAGE_CODE` en `ecoCMS` (Responsabilidad del Tema):** `footer.php` (`¿COMO PONERSE EN CONTACO?`, `Teléfono Contacto:`, `Quillabamba, Cusco - Perú`) e `inc/short-codes.php` (`[habitaciones_aleatorias]`: clave de transient `qomfort_habitaciones_aleatorias_es`, etiquetas `Personas` y `DESDE S/ `).
       2. **Texto Español Cableado en `ecoCMS/footer.php` (Responsabilidad del Tema):** `Todos los derechos reservados | Desarrollado por: OG Estudio Creativo`.
       3. **Contenido Editorial en `_elementor_data` de Post `8356` (Responsabilidad de Contenido):** Los widgets de título y botón de la sección de blog de la portada inglesa (`8356`) tienen guardados `"Nuestro Blog"` y `"Ver más"` en español.
       4. **Entidades No Migradas (`rooms` en `BLOCKED_BY_LANGUAGE`) y Posts Sintéticos de Laboratorio:** Las habitaciones (`rooms`) carecen de fila en `tfml_group_elements` y se agrupan en el transient español compartido; el widget de blog muestra entradas sintéticas de prueba creadas en Fase 3.7.
  6. **Hallazgo Técnico Adicional en `CoreSitemapsFilter` (Responsabilidad de TFML — P1):**
     - `CoreSitemapsFilter::filter_posts_entry()` localiza correctamente `197 -> http://lab.ecoterra.test/` y `8356 -> http://lab.ecoterra.test/en/`; sin embargo, `CoreSitemapsFilter::filter_posts_query_args()` asigna `$args['tfml_suppress_filters'] = true` mientras que `QueryLanguageFilter::is_query_eligible()` comprueba `tfml_suppress_language_filter` (`QueryLanguageFilter::QUERY_VAR_SUPPRESS`), desalineación de clave que debe corregirse en una futura fase de código autorizada.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.35 / Directiva DT-TFML-065 — Certificación visual, interactiva y lingüística del frontend en navegador real.
- **JUSTIFICACIÓN:** Proporcionar a la Dirección Técnica una radiografía empírica completa en navegador real que separe con rigor matemático las responsabilidades del núcleo de TF Multilingual respecto de las limitaciones heredadas del tema `ecoCMS`, los grupos pendientes en `BLOCKED_BY_LANGUAGE` y el aprovisionamiento de archivos estáticos del laboratorio, manteniendo cero mutaciones en código funcional y bases de datos.
- **CONSECUENCIAS:** Queda versionado el verificador reproducible `scratch/verify_fase_3_35.php` (`17/17 PASS`), preservando intactas `cms_ect` y `cms_ect_lab` (`121` grupos, `135` elementos activos, `1` retenida `fr:8839`).

---

### ADR-062: Endurecimiento del Núcleo: Sitemaps Multilingües, Insignias Lingüísticas SVG Locales, Disponibilidad Real de Traducciones y Aislamiento de Red en Navegador (Fase 3.36)
- **FECHA:** 2026-10-18
- **DECISIÓN:**
  1. **Alineación Canónica de Claves de Supresión y Exclusión de Retenidos en `CoreSitemapsFilter`:**
     - Se eliminó la clave inconsistente `'tfml_suppress_filters'` en `CoreSitemapsFilter::filter_posts_query_args()` y `CoreSitemapsFilter::filter_taxonomies_query_args()`, sustituyéndola por las constantes soberanas `QueryLanguageFilter::QUERY_VAR_SUPPRESS` (`'tfml_suppress_language_filter'`) y `TermQueryLanguageFilter::QUERY_VAR_SUPPRESS` (`'tfml_suppress_language_filter'`).
     - Se inyectó opcionalmente `RetainedTranslationRegistry` en `CoreSitemapsFilter` (y se conectó en `Plugin::get_core_sitemaps_filter()`) para excluir determinísticamente de las consultas de sitemaps (`post__not_in` y `exclude`) todos los objetos registrados en `tfml_retained_translations` cuyos idiomas se encuentran inactivos (como la portada francesa retenida `8839`), evitando que la supresión del filtro de idioma por request exponga contenido retenido.
     - Se preserva intacta la guardia de laboratorio `blog_public = 0` (`GET /wp-sitemap.xml` responde `HTTP 404` en acceso público directo), verificando la generación multilingüe completa (`/` y `/en/` simultáneamente, sin duplicados y sin `8839`) mediante invocación controlada de `WP_Sitemaps_Posts` y `WP_Sitemaps_Taxonomies`.
  2. **Insignias Lingüísticas SVG Propias (`first-party`) y Degradación Accesible sin `404` en `AdaptadorCompatibilidadWpml`:**
     - Se incorporaron al repositorio los activos vectoriales propios y ligeros `assets/images/flags/es.svg`, `assets/images/flags/en.svg` y `assets/images/flags/pt.svg` (insignias tipográficas limpias `ES`, `EN`, `PT` libres de licencias de terceros).
     - `AdaptadorCompatibilidadWpml::obtener_url_bandera()` resuelve en primer término `.svg` y como respaldo `.png` dentro de `assets/images/flags/`, verificando siempre la existencia física en disco mediante `is_file()` antes de emitir una URL. Si un código de idioma carece de archivo físico local, devuelve cadena vacía (`''`), garantizando **cero peticiones `HTTP 404`** por iconos de idioma inexistentes.
     - El filtro `tfml_wpml_compat_country_flag_url` valida que cualquier URL personalizada pertenezca al host local de `home_url()` o `plugins_url()`, rechazando dominios externos y degradando a la resolución local verificada o `''`.
     - Se añadió `AdaptadorCompatibilidadWpml::obtener_representacion_textual_accesible()` y los campos enriquecidos `language_badge` (`ES`, `EN`, `PT`) y `accessible_label` (`Español (ES)`, `English (EN)`, `Português (PT)`) en la estructura devuelta por `icl_get_languages()`.
  3. **Disponibilidad Real de Traducciones en `ServicioTraduccionPublico` e `icl_get_languages()` y Saneamiento del Caso Portugués (`pt`):**
     - `ServicioTraduccionPublico::obtener_idiomas_activos()` evalúa para cada idioma activo la disponibilidad real de traducción en el contexto consultado (`front_page`, singular `post`/`page`/CPT, `term` o archivo general) verificando:
       1. Que el elemento exista, esté publicado (`post_status === 'publish'`) y no pertenezca a `RetainedTranslationRegistry`.
       2. En portadas estáticas (`front_page`), que `ResolvedorPortadaMultilingue::resolve_front_page_id_for_language($codigo)` devuelva un ID válido publicado.
       3. En archivos generales sin objeto singular, que el idioma predeterminado o secundario disponga de portada o contenido publicado real en `tfml_group_elements`.
     - Cada entrada de `obtener_idiomas_activos()` e `icl_get_languages()` informa con precisión `missing = 0` (traducción disponible y publicada) o `missing = 1` (sin traducción disponible en el contexto actual). Cuando `missing = 1`, `url` se establece en cadena vacía (`''`), impidiendo la generación de enlaces engañosos a rutas `404` como `http://lab.ecoterra.test/pt/` o `/en/old-blog/`.
     - Cuando el consumidor invoca `icl_get_languages('skip_missing=1')`, se omiten todos los idiomas con `missing === 1`. Cuando invoca `skip_missing=0` (como hace el shortcode heredado `[top-idiomas]` del tema `ecoCMS`), `AdaptadorCompatibilidadWpml::filtrar_salida_shortcode_selector_heredado()` intercepta limpiamente el filtro `do_shortcode_tag` de WordPress para transformar `<a href="">` en `<a aria-disabled="true" data-tfml-missing="1">` (elemento `<a>` de marcador de posición conforme a HTML5 sin atributo `href`), mantendo `ecoCMS` 100% intacto y dejando únicamente `es` y `en` como enlaces navegables en `/` y `/en/`.
     - El idioma portugués (`pt`) se conserva registrado y activo en `LanguageRegistry` (sin mutar `cms_ect_lab`), mientras que `GET /pt/` continúa devolviendo `HTTP 404` limpio y `HreflangGenerator` sigue omitiéndolo.
  4. **Contrato de Aislamiento de Red en Auditorías con Navegador Real Chromium/Edge:**
     - Toda ejecución de navegador real Chromium/Edge contra el laboratorio incorpora obligatoriamente la bandera `--host-resolver-rules="MAP * ~NOTFOUND , EXCLUDE lab.ecoterra.test , EXCLUDE 127.0.0.1 , EXCLUDE localhost"`, junto con `--disable-background-networking`, `--disable-Component-update`, `--disable-domain-reliability` y `--no-pings`, bloqueando en la pila de red del navegador cualquier intento de resolución o conexión hacia terceros (`www.youtube.com`, `demo.arrowpress.net`, `fonts.googleapis.com`, etc.).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.36 / Directiva DT-TFML-066 — Endurecimiento del núcleo: sitemaps, banderas, disponibilidad real de traducciones y aislamiento del navegador.
- **JUSTIFICACIÓN:** Resolver de raíz los tres defectos propios del núcleo de TF Multilingual detectados en la auditoría visual de Fase 3.35 y blindar el aislamiento de red del navegador real sin modificar el tema `ecoCMS` ni realizar escrituras sobre `cms_ect` o `cms_ect_lab`.
- **CONSECUENCIAS:** Suite PHPUnit ampliada a **609 pruebas / 2.214 aserciones (0 fallos)**, PHPCS en **180/180 archivos PASS (0 errores, 0 warnings)** y verificador reproducible `scratch/verify_fase_3_36.php` certificado con **14/14 controles PASS**.

---

### ADR-063: Auditoría del Universo Editorial, Sitemaps, Traducciones Francesas No Migradas y Planificación del Siguiente Lote Parcial Multilingüe (Fase 3.37)
- **FECHA:** 2026-10-18
- **DECISIÓN:**
  1. **Conciliación Exacta de las 61 Páginas de `WP_Sitemaps_Posts('page')` y Demás Proveedores del Sitemap:**
     - De las **62 páginas publicadas** (`post_type = 'page' AND post_status = 'publish'`) en `etk_t_posts`, `CoreSitemapsFilter` excluye actualmente únicamente `ID 8839` (`Accueil`, `fr`, retenida en `RetainedTranslationRegistry`), devolviendo **61 páginas** en `WP_Sitemaps_Posts('page')`.
     - Desglose taxativo de las **61 páginas** del sitemap:
       - **3 páginas legítimamente migradas en TFML:** `197` (`inicio`, `es`, Grupo `587`, `http://lab.ecoterra.test/`), `8356` (`home`, `en`, Grupo `587`, `http://lab.ecoterra.test/en/`) y `4338` (`old-blog`, `es`, Grupo `6`, `http://lab.ecoterra.test/old-blog/`).
       - **21 páginas españolas no migradas en TFML (`WPML = es`, `TFML = NULL`):** `20` pertenecientes a grupos `BLOCKED_BY_LANGUAGE` (`424, 430, 432, 4976, 5465, 6214, 6237, 6505, 6649, 6938, 7285, 8042, 8316, 8801, 9218, 9248, 9614, 9666, 9864, 10109`) y `1` en `CONFLICT` (`ID 3`, `old-terminos-y-condiciones`, `TRID 3`). Resuelven en sus URLs españolas sin prefijo (`HTTP 200`, `lang="es-ES"`), aunque carecen aún de relaciones multilingües en TFML.
       - **21 páginas inglesas no migradas en TFML (`WPML = en`, `TFML = NULL`):** `20` pertenecientes a grupos `BLOCKED_BY_LANGUAGE` (`8321, 8327, 8329, 8330, 8335, 8337, 8339, 8343, 8345, 8347, 8352, 8354, 8613, 8802, 9219, 9249, 9615, 9667, 9863, 10110`) y `1` en `CONFLICT` (`ID 8350`, `old-terms-and-conditions`, `TRID 3`). Al carecer de fila en `tfml_group_elements`, `CoreSitemapsFilter::filter_posts_entry()` recibe `language_of('post', $id) === null` y emite sus URLs **sin prefijo `/en/`** (`/about-us/`, `/activities/`, `/contact-us/`, etc.), donde WordPress las sirve bajo contexto lingüístico español (`<html lang="es-ES">`) mientras que `/en/about-us/` responde `HTTP 404`.
       - **16 páginas francesas publicadas no migradas en TFML (`WPML = fr`, `TFML = NULL`):** `15` pertenecientes a grupos `BLOCKED_BY_LANGUAGE` (`8823, 8873, 8880, 8908, 8910, 8918, 8921, 8937, 8952, 8955, 8974, 8981, 8989, 9025, 10111`) y `1` en `CONFLICT` (`ID 8914`, `old-conditions-generales`, `TRID 3`). (Las 6 páginas francesas restantes en WPML — `9220, 9250, 9616, 9664, 9668, 9865` — tienen `post_status = 'draft'`).
     - **3 URLs duplicadas en `WP_Sitemaps_Posts('page')` (5 entradas producen 3 URLs distintas):**
       1. `http://lab.ecoterra.test/services/` (`2` veces: `8347:en` y `8918:fr`, ambos con slug `services` en raíz).
       2. `http://lab.ecoterra.test/services/restaurant/` (`2` veces: `8345:en` bajo `8347` y `8921:fr` bajo `8918`).
       3. `http://lab.ecoterra.test/blog/` (`3` veces: `10109:es`, `10110:en` y `10111:fr` en `TRID 34831`).
     - **Demás proveedores del sitemap:** `post` (`27` entradas: `3` posts `es` migrados `5687, 5718, 5734` + `24` posts sintéticos de laboratorio `15576..15666`), `rooms` (`33` entradas: `11 es + 11 en + 11 fr` de los `11` TRIDs `BLOCKED_BY_LANGUAGE`, con `1` URL duplicada `/rooms/suite-bungalow-matrimonial-panoramico/` entre `7784:es` y `8594:en`), `ova_framework_hf_el` (`6` entradas: plantillas internas de cabecera `4410, 4804, 8383, 8386, 8829, 8830` expuestas por ser CPT `public => true`), `category` (`2` entradas: `term 58 es` migrado y `term 1 en` no migrado) y `post_tag` (`19` etiquetas `es` migradas).
  2. **Diagnóstico de Fuga de Traducciones Francesas No Migradas y Estrategia Conceptual de Corrección del Sitemap:**
     - En `etk_t_icl_translations` existen `1.005` filas con `language_code = 'fr'`, de las cuales **50 grupos válidos** (`21 page`, `11 rooms`, `16 nav_menu_item`, `2 ova_framework_hf_el`) están clasificados como `BLOCKED_BY_LANGUAGE` en el plan maestro (más `1` grupo ya migrado parcialmente `TRID 197` con `8839` en `RetainedTranslationRegistry`, `1` grupo huérfano `TRID 34637` cuyo post `7844` ya no existe en `wp_posts`, y `1` grupo `TRID 3` en `CONFLICT` por colisión cruzada de ID `post:3` con `term:3`).
     - **Brecha de clasificación:** Al activar `tfml_suppress_language_filter = true` en `CoreSitemapsFilter`, `QueryLanguageFilter` deja de filtrar las consultas de sitemaps, pero `RetainedTranslationRegistry` solo conoce los objetos cuyos grupos ya fueron importados parcialmente (`8839`). Los objetos franceses de grupos `BLOCKED_BY_LANGUAGE` aún no importados (`16` páginas, `11` habitaciones, `2` cabeceras) quedan expuestos sin prefijo en el sitemap.
     - **Estrategia conceptual recomendada para fase futura autorizada:** Sustituir en `CoreSitemapsFilter` la supresión ciega de `QueryLanguageFilter` por una **cláusula SQL multilingüe explícita para sitemaps** (o cambiar la política del sitemap a **inclusión estricta de entidades gestionadas en TFML con `language_code` activo** `tfml_ge.language_code IN (<active_languages>)`, más desduplicación determinista de URLs e inhibición de CPTs técnicos internos como `ova_framework_hf_el` vía `wp_sitemaps_post_types`). Esto garantiza que el sitemap jamás exponga idiomas inactivos (`fr`) ni genere URLs sin prefijo para traducciones inglesas no migradas, sin acoplar el runtime del núcleo de sitemaps a lecturas directas de `icl_translations`.
  3. **Auditoría de Menús Principales (`menu-principal` `43` y `menu-es-ingles` `98`), Dependencia Crítica en `NavMenuFrontendFilter` y Preparación del Siguiente Lote Parcial:**
     - Ambos menús poseen **16 ítems simétricos**: `#01` (`Inicio` `197` / `Home` `8356`, ya migrados en Grupo `587`), `#13` (enlace personalizado `#`), y **14 ítems de páginas internas** que corresponden exactamente a **14 TRIDs `BLOCKED_BY_LANGUAGE`** (`424, 430, 9201, 9537, 9545, 9634, 9697, 9818, 9838, 34652, 34660, 34776, 34791, 34823`), a los que se suma el botón CTA de cabecera `Contáctenos` / `Contact Us` (`TRID 432`).
     - **Hallazgo Crítico C1 (`NavMenuFrontendFilter::localize_menu_item()` y localización de permalinks):** En `src/Domain/Navigation/NavMenuFrontendFilter.php`, `localize_menu_item()` asigna `$item->url = $this->get_post_permalink($trans_id)` sin pasar el permalink por `$this->url_generator->localize_url($permalink, $current_lang)` (excepto cuando el post es la portada estática). Si se migran las páginas inglesas a `tfml_group_elements` (`en`) sin corregir simultáneamente `NavMenuFrontendFilter::localize_menu_item()` (y/o el filtro de permalinks públicos), `GET /en/about-us/` pasará a responder `HTTP 200` y `GET /about-us/` pasará a devolver `HTTP 404` (filtrado por `QueryLanguageFilter`), pero el menú inglés seguiría emitiendo `href="http://lab.ecoterra.test/about-us/"` produciendo enlaces rotos `404`. Por tanto, la próxima fase que autorice la importación parcial del lote de páginas deberá incluir la localización del permalink en `NavMenuFrontendFilter::localize_menu_item()`.
     - **Hallazgo Crítico C2 (`WpmlMigrationPlanner` en `TRID 3`):** `WpmlMigrationPlanner::plan()` consulta `SELECT element_id FROM tfml_group_elements` sin filtrar por `element_type`, provocando que `post:3` (`old-terminos-y-condiciones`, `TRID 3`) colisione falsamente con `term:3` (Grupo `58`) y se clasifique como `CONFLICT`.
     - **Planes Parciales Derivados en Memoria (Listos para Autorización):**
       - **Lote 11A (`batch-11a-core-priority-pages` — 4 TRIDs prioritarios: `432, 9201, 9545, 9818`):** `4` grupos (`Nosotros/About Us`, `Actividades/Activities`, `Confort y Tranquilidad/Comfort and Tranquility`, `Contáctenos/Contact Us`), `8` elementos activos (`es`/`en`), `4` retenidos (`fr`). Fingerprint SHA-256: `1712e825c8231edf2ca68767061f3c2fd507c009e0bf1cf922c02ed282b616da`.
       - **Lote 11B (`batch-11b-top-level-nav-pages` — 9 TRIDs raíz `post_parent = 0`: `430, 432, 9201, 9537, 9545, 9634, 9697, 9818, 34652`):** `9` grupos, `18` elementos activos (`es`/`en`), `9` retenidos (`fr`). Fingerprint SHA-256: `a3e9136e031ee9f3fea0b6d233c33b4abefbbf39d85cb35c9c26780165f15693`.
       - **Lote 11C Recomendado (`batch-11-primary-navigation-pages` — 15 TRIDs completos de navegación principal + CTA: `424, 430, 432, 9201, 9537, 9545, 9634, 9697, 9818, 9838, 34652, 34660, 34776, 34791, 34823`):** `15` grupos, `30` elementos activos (`es`/`en`), `15` retenidos (`fr`). Fingerprint SHA-256: `64e3bcbeea7fcaa09a229828c1e180fd0ae82eee324786131f02ed7dee9a876d`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.37 / Directiva DT-TFML-067 — Auditoría del universo editorial, sitemaps y preparación del siguiente lote multilingüe.
- **JUSTIFICACIÓN:** Proporcionar conciliación matemática del 100% de las entradas del sitemap y de los menús bilingües, identificar preventivamente la dependencia de localización de URLs en `NavMenuFrontendFilter` antes de importar páginas internas, y dejar derivados en memoria los planes parciales con sus huellas SHA-256 sin tocar las bases de datos.
- **CONSECUENCIAS:** Versionado del verificador reproducible `scratch/verify_fase_3_37.php` (**11/11 controles PASS**), preservando intactos los **609 tests PHPUnit**, **180/180 archivos PHPCS** y el estado de `cms_ect` y `cms_ect_lab` (`121` grupos, `135` elementos activos, `1` retenido `fr:8839`).

---

### ADR-064: Contrato de Elegibilidad Editorial, Exclusión de Idiomas Inactivos y Consistencia de Sitemaps Multilingües (Fase 3.38)
- **FECHA:** 2026-10-18
- **DECISIÓN:**
  1. **Contrato Formal y Determinista de Elegibilidad de Sitemaps en 6 Categorías (`A..F`) en `CoreSitemapsFilter`:**
     - Se implementó en `TF\Multilingual\Domain\Seo\CoreSitemapsFilter` (`evaluate_post_eligibility()` y `evaluate_term_eligibility()`) un contrato determinista y libre de heurísticas por slug o título que clasifica todo objeto `WP_Post` o `WP_Term` en seis categorías mutuamente excluyentes:
       - **Categoría A (`tfml_accredited` — Elegible):** Objeto con asociación lingüística persistida en `tfml_group_elements` cuyo `language_code` está activo en `LanguageRegistry` (`es`, `en`, `pt`) y con estado público (`post_status === 'publish'`).
       - **Categoría B (`accredited_monolingual` — Elegible):** Objeto sin grupo multilingüe que cuenta con acreditación explícita en el idioma predeterminado activo (`accredit_monolingual_element()`, filtro `tfml_sitemaps_accredited_monolingual_ids` / `tfml_is_accredited_monolingual_element` o política explícita `POLICY_MONOLINGUAL_DEFAULT`).
       - **Categoría C (`unclassified` — Excluida):** Objeto sin asociación en `tfml_group_elements` y sin acreditación monolingüe verificable, incluyendo las **24 entradas sintéticas de laboratorio** (`IDs 15576..15666` con títulos `Lab 3.7...` / `Benchmark Post...`).
       - **Categoría D (`inactive_language` / `unclassified` — Excluida):** Objeto asociado a un idioma inactivo (`fr` en `tfml_group_elements`) o traducción histórica de WPML aún no migrada a TFML (`21` páginas `es`, `21` páginas `en`, `16` páginas `fr`, `33` habitaciones `rooms` de grupos `BLOCKED_BY_LANGUAGE` y `term 1` `category`).
       - **Categoría E (`retained_translation` — Excluida):** Objeto registrado en `RetainedTranslationRegistry` como traducción retenida en un idioma inactivo (`ID 8839` `Accueil` en `fr`).
       - **Categoría F (`technical_content` / `non_public_status` — Excluida):** CPTs técnicos de maquetación/plantilla (`ova_framework_hf_el`, `elementor_library`, `e-floating-buttons`, `e-landing-page`, `wpcf7_contact_form`, `wp_navigation`, etc.), taxonomía estructural `nav_menu` y publicaciones no publicadas (`draft`, `pending`, `private`, `trash`).
  2. **Independencia Absoluta de WPML en Tiempo de Ejecución (`0` Consultas a `icl_*`):**
     - `CoreSitemapsFilter` opera exclusivamente sobre las fuentes soberanas de TF Multilingual y WordPress Core (`LanguageRegistry`, `ContentTranslationResolver`, `LocalizedUrlGenerator`, `RetainedTranslationRegistry`, `tfml_groups`, `tfml_group_elements`, `wp_posts`, `wp_terms`, `wp_term_taxonomy`), realizando **cero consultas SQL a tablas `icl_*`** durante toda la construcción del índice y de las listas de URLs de sitemaps.
  3. **Política Conservadora por Defecto (`POLICY_CONSERVATIVE_ACCREDITED_ONLY`) e Inyección SQL en `posts_clauses` y `terms_clauses`:**
     - Se adoptó como política predeterminada la **Alternativa 1 Conservadora (`POLICY_CONSERVATIVE_ACCREDITED_ONLY`)**: mientras un contenido carezca de fila en `tfml_group_elements` (Categoría A) o de acreditación monolingüe explícita (Categoría B), no se anuncia en el sitemap XML como URL multilingüe canónica.
     - En lugar de depender únicamente del descarte posterior en `wp_sitemaps_posts_entry` / `wp_sitemaps_taxonomies_entry` (lo que desincronizaría `found_posts`, `max_num_pages` y `wp_count_terms()`, pudiendo dejar sub-sitemaps vacíos en `/wp-sitemap.xml`), `CoreSitemapsFilter` marca las consultas de sitemaps con `_tfml_sitemap_eligibility = true` e inyecta restricciones SQL directas en `posts_clauses` (`filter_sitemap_posts_clauses()`) y `terms_clauses` (`filter_sitemap_terms_clauses()`):
       - Para posts: `LEFT JOIN {$prefix}tfml_group_elements AS tfml_sge ON (tfml_sge.element_id = {$posts_table}.ID AND tfml_sge.element_type = 'post')` con `WHERE {$posts_table}.post_status = 'publish' AND tfml_sge.language_code IN ('es', 'en', 'pt')` (más `NOT IN (<retained_ids>)` y soporte de `OR (tfml_sge.id IS NULL AND ID IN (<accredited_ids>))`).
       - Para términos: `LEFT JOIN {$prefix}tfml_group_elements AS tfml_ste ON (tfml_ste.element_id = t.term_id AND tfml_ste.element_type = 'term') LEFT JOIN {$prefix}tfml_groups AS tfml_stg ON (tfml_stg.id = tfml_ste.group_id)` con comparación de subtipo contra literal SQL escapado (`tfml_stg.subtype = '{$taxonomy}'`) para evitar conflictos de colación MySQL (`utf8mb4_unicode_ci` vs `utf8mb4_unicode_520_ci`) entre `term_taxonomy` y `tfml_groups`.
     - Con ello, los conteos de paginación (`get_max_num_pages()`) coinciden exactamente con las entradas elegibles reales (`rooms` pasa a `max_num_pages = 0`, omitiéndose del índice hasta que se migren sus grupos).
  4. **Exclusión Nativa de Tipos Técnicos (`wp_sitemaps_post_types`) y Desduplicación Determinista de URLs:**
     - `CoreSitemapsFilter::filter_sitemaps_post_types()` filtra `wp_sitemaps_post_types` (complementado por `wp_sitemaps_posts_pre_max_num_pages` y `wp_sitemaps_posts_pre_url_list`) para excluir los CPTs técnicos de plantillas (`ova_framework_hf_el`, `elementor_library`, `e-floating-buttons`, `e-landing-page`, `wpcf7_contact_form`) mientras preserva todos los CPTs editoriales y comerciales (`page`, `post`, `rooms`, `tours`, `product`), con extensibilidad vía filtro `tfml_sitemaps_excluded_post_types`.
     - `filter_posts_entry()` y `filter_taxonomies_entry()` normalizan las portadas estáticas mediante `LocalizedUrlGenerator::is_front_page_for_language()` (`197 -> http://lab.ecoterra.test/`, `8356 -> http://lab.ecoterra.test/en/`), localizan las URLs secundarias con prefijo canónico (`/en/...`) y aplican un registro de desduplicación por pasada (`$seen_post_urls` / `$seen_term_urls`) que garantiza **cero URLs duplicadas** incluso cuando dos traducciones de idiomas distintos compartan el mismo `post_name` (como `/blog/` y `/en/blog/`).
  5. **Matriz Conciliada Pre/Post Fase 3.38 en `cms_ect_lab`:**
     - `WP_Sitemaps_Posts('page')`: de `61` entradas (`3` duplicadas, `16` `fr` expuestas, `21` `en` sin prefijo `/en/`) a **`3` entradas exactas (`max_num_pages = 1`, `0` duplicadas)**: `http://lab.ecoterra.test/` (`197:es`), `http://lab.ecoterra.test/old-blog/` (`4338:es`) y `http://lab.ecoterra.test/en/` (`8356:en`).
     - `WP_Sitemaps_Posts('post')`: de `27` entradas (`3` reales + `24` sintéticas) a **`3` entradas exactas (`max_num_pages = 1`, `0` sintéticas)**: `5687:es`, `5718:es` y `5734:es`.
     - `WP_Sitemaps_Posts('rooms')`: de `33` entradas no migradas (`11 es + 11 en + 11 fr`, `1` duplicada) a **`0` entradas (`max_num_pages = 0`)** hasta la migración autorizada de sus TRIDs.
     - `WP_Sitemaps_Posts('ova_framework_hf_el')`: de `6` plantillas internas de cabecera a **`0` entradas (`max_num_pages = 0`, subtipo excluido en `wp_sitemaps_post_types`)**.
     - `WP_Sitemaps_Taxonomies('category')`: de `2` entradas (`term 50:es` + `term 1` no migrado) a **`1` entrada exacta (`max_num_pages = 1`)**: `http://lab.ecoterra.test/category/blog/` (`term_id = 50`, `es`).
     - `WP_Sitemaps_Taxonomies('post_tag')`: **`19` entradas exactas (`max_num_pages = 1`, `0` duplicadas)** correspondientes a las `19` etiquetas `es` migradas con `count > 0`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.38 / Directiva DT-TFML-068 — Integridad y elegibilidad de sitemaps: elegibilidad editorial, exclusión de idiomas inactivos y consistencia de sitemaps.
- **JUSTIFICACIÓN:** Garantizar que el generador de sitemaps nativo de WordPress anuncie únicamente URLs canónicas, publicadas, sin duplicados y pertenecientes a idiomas activos con acreditación lingüística verificable en TFML, sin acoplar el runtime a tablas heredadas de WPML y sin ejecutar migraciones ni escrituras en base de datos fuera de alcance.
- **CONSECUENCIAS:** Suite PHPUnit ampliada a **615 pruebas / 2.263 aserciones (0 fallos)**, PHPCS en **180/180 archivos PASS (0 errores, 0 warnings)**, verificador reproducible `scratch/verify_fase_3_38.php` certificado con **12/12 controles PASS** y no-regresión verificada en `verify_fase_3_37.php` (**11/11 PASS**), `verify_fase_3_36.php` (**14/14 PASS**), `verify_fase_3_35.php` (**17/17 PASS**) y `verify_fase_3_34.php` (**22/22 PASS**).

---

### ADR-065: Localización Segura de Enlaces de Menú y Preparación del Piloto de Navegación Bilingüe (Fase 3.39)
- **FECHA:** 2026-10-18
- **DECISIÓN:**
  1. **Localización Canónica y Segura de Permalinks en `NavMenuFrontendFilter::localize_menu_item()` (`src/Domain/Navigation/NavMenuFrontendFilter.php`):**
     - Todo ítem de menú `post_type` o `taxonomy` cuya traducción activa en el idioma actual se resuelva positivamente en TFML (`ContentTranslationResolver::resolve()`) obtiene su URL base mediante las APIs nativas de WordPress (`get_permalink($trans_id)` o `get_term_link($trans_id, $taxonomy)`) y la pasa obligatoriamente por `$this->url_generator->localize_url($raw_url, $current_lang)` antes de asignarla a `$item->url`.
     - Las portadas estáticas (`LocalizedUrlGenerator::is_front_page_for_language()`) continúan normalizándose directamente a `$this->url_generator->home_url($current_lang)` (`http://lab.ecoterra.test/` en `es` y `http://lab.ecoterra.test/en/` en `en`), aplicando igualmente la preservación de parámetros de consulta y fragmentos del ítem de menú original.
     - Se incorporó el método privado `preserve_url_decorations(string $localized_url, string $original_item_url)` para conservar en ítems `post_type` y `taxonomy` cualquier query string personalizada (`?origen=menu&promo=1`) o fragmento de ancla (`#seccion-mapa`) configurado en el ítem de menú original cuando el permalink base de WordPress no lo contenga.
     - En contexto del idioma predeterminado (`es`), `localize_menu_item()` normaliza la portada estática y resuelve posibles referencias cruzadas hacia su contraparte en el idioma predeterminado sin añadir prefijo lingüístico a las URLs españolas.
  2. **Salvaguardas de Seguridad para Páginas No Migradas, Traducciones Retenidas, No Publicadas y Esquemas Especiales:**
     - **Páginas no migradas (`resolve() === null`):** Si el objeto apuntado por el ítem de menú carece de relación activa en `tfml_group_elements` para `$current_lang` (como las `14` páginas internas no migradas de `menu-es-ingles` `ID 98`), `localize_menu_item()` retorna inmediatamente sin reescribir `$item->object_id`, `$item->title` ni `$item->url`, evitando fabricar enlaces `/en/<slug>/` prematuros que responderían `HTTP 404` antes de la migración de su grupo.
     - **Traducciones retenidas e idiomas inactivos:** Se inyectó opcionalmente `RetainedTranslationRegistry` en `NavMenuFrontendFilter` (y `Plugin::get_nav_menu_frontend_filter()`). Si el elemento origen o destino figura como traducción retenida (`has_retained_element()`) o si el idioma actual está inactivo en `LanguageRegistry` (`fr`), el filtro se abstiene de sustituir menús o prefijar URLs.
     - **Estado de publicación (`post_status === 'publish'`):** Si el post traducido resuelto existe pero no está publicado (`draft`, `pending`, `private`, `trash`), `is_translated_post_publishable()` impide reescribir el ítem de menú hacia una ruta no pública.
     - **Endurecimiento de `LocalizedUrlGenerator::localize_url()` (`src/Routing/LocalizedUrlGenerator.php`):** Además de preservar URLs externas (`host` distinto de `home_url()`), puertos, subdirectorios, query strings y fragmentos e impedir doble prefijado (`/en/en/...`), `localize_url()` deja intactos los anclajes puros (`#...`), los esquemas no HTTP (`mailto:`, `tel:`, `javascript:`) y las rutas técnicas/administrativas excluidas de WordPress (`wp-admin`, `wp-login.php`, `wp-json`, `xmlrpc.php`, `wp-cron.php`).
  3. **Verificación de Jerarquías `post_parent` en `cms_ect_lab` antes de Importar Páginas Internas:**
     - Se auditó en `etk_t_posts` la jerarquía completa de los **10 pares `es`/`en`** candidatos (`Nosotros / About Us` `TRID 9545` + sus `3` subpáginas `TRIDs 424, 9838, 34823`; `Actividades / Activities` `TRID 9201`; `Galería de Fotos / Photo Gallery` `TRID 430`; y `Experiencia Transformadora / Transformative Experience` `TRID 34652` + sus `3` subpáginas `TRIDs 34660, 34776, 34791`):
       - En todos los casos, los padres españoles (`6237` `/nosotros/` y `9218` `/experiencia-transformadora/`) e ingleses (`8354` `/about-us/` y `9219` `/transformative-experience/`) tienen `post_parent = 0` y `post_status = 'publish'`.
       - Las 3 subpáginas de `About Us` (`8352` `who-are-we`, `8321` `reviews`, `9863` `terms-and-conditions`) tienen `post_parent = 8354` (`About Us`, `en`), generando permalinks jerárquicos nativos `/about-us/<slug>/` que al pasar por `LocalizedUrlGenerator::localize_url(..., 'en')` producen `http://lab.ecoterra.test/en/about-us/<slug>/`.
       - Las 3 subpáginas de `Transformative Experience` (`9249` `sustainability`, `9615` `regenerative-tourism`, `9667` `forest-conservation`) tienen `post_parent = 9219` (`Transformative Experience`, `en`), generando `http://lab.ecoterra.test/en/transformative-experience/<slug>/`.
       - `TRID 430` (`Galería de Fotos` `430:es` / `Photo Gallery` `8329:en`) posee en WordPress los slugs reales `galeria` (`/galeria/`) y `gallery` (`/gallery/` -> `http://lab.ecoterra.test/en/gallery/`), ambos con `post_parent = 0` y `post_status = 'publish'`.
  4. **Derivación en Memoria del Piloto Inicial de 2 TRIDs para Fase 3.40 (Cero Escrituras en Fase 3.39):**
     - **Opción A Recomendada (`pilot-nav-2-trids-9201-430` — 2 páginas raíz independientes sin hijas):** `TRID 9201` (`Actividades` `4976:es` / `Activities` `8339:en` / `8955:fr` retenido) + `TRID 430` (`Galería de Fotos` `430:es` / `Photo Gallery` `8329:en` / `8989:fr` retenido). Estado: `READY`, `2` grupos, `4` elementos activos (`es`, `en`), `2` retenidos (`fr`), `0` colisiones. Huella SHA-256: `1dc975df4324dc6ead697ca8d059626330a6143ef2e87368cc8cb8643c588655`.
     - **Opción B Alternativa (`pilot-nav-2-trids-9545-9201` — 1 página padre raíz + 1 página raíz independiente):** `TRID 9545` (`Nosotros` `6237:es` / `About Us` `8354:en` / `8873:fr` retenido) + `TRID 9201` (`Actividades` `4976:es` / `Activities` `8339:en` / `8955:fr` retenido). Estado: `READY`, `2` grupos, `4` elementos activos (`es`, `en`), `2` retenidos (`fr`), `0` colisiones. Huella SHA-256: `ce935eb308bee58ae9bf198b8eea03effbf32210e80006e58cabb2451275f9e0`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.39 / Directiva DT-TFML-069 — Localización segura de enlaces de menú y preparación del piloto de navegación bilingüe.
- **JUSTIFICACIÓN:** Eliminar el defecto de permalinks secundarios sin prefijo en `NavMenuFrontendFilter::localize_menu_item()` antes de importar cualquier página interna en `cms_ect_lab`, garantizando simultáneamente que los ítems no migrados conserven sus URLs `HTTP 200` actuales sin fabricar rutas `/en/...` `404` prematuras.
- **CONSECUENCIAS:** Suite PHPUnit ampliada a **625 pruebas / 2.307 aserciones (0 fallos)** cubriendo los 18 escenarios obligatorios de regresión de `DT-TFML-069`, PHPCS en **180/180 archivos PASS (0 errores, 0 warnings)**, verificador reproducible `scratch/verify_fase_3_39.php` certificado con **10/10 controles PASS** y no-regresión verificada en `verify_fase_3_38.php` (**12/12 PASS**), `verify_fase_3_37.php` (**11/11 PASS**) y `verify_fase_3_36.php` (**14/14 PASS**), con **cero escrituras** en `cms_ect` y `cms_ect_lab` (`121` grupos, `135` elementos activos, `1` retenido `fr:8839`).

---

### ADR-065A: Conciliación Forense de TRIDs, Identidad Editorial y Certificación Canónica del Piloto de Migración (Fase 3.39A / Subsanación DT-TFML-069A)
- **FECHA:** 2026-10-18
- **DECISIÓN:**
  1. **Conciliación Directa contra `etk_t_icl_translations` y `etk_t_posts` de los TRIDs en Disputa (`9545`, `9201`, `430`, `9818`) y de los 15 TRIDs Candidatos de Navegación:**
     - Se ejecutó auditoría SQL directa sobre `cms_ect_lab` y `cms_ect` (`etk_t_icl_translations`, `etk_t_posts`, `etk_t_tfml_groups`, `etk_t_tfml_group_elements`), acreditando que las identidades editoriales reportadas en Fase 3.39 (`ADR-065`) coinciden al **100%** con la base de datos real:
       - **`TRID 9545` (`Nosotros / About Us`):** `es=6237` (`translation_id=1164`, `slug='nosotros'`, `parent=0`, `publish`, URL `http://lab.ecoterra.test/nosotros/`), `en=8354` (`translation_id=1755`, `source='es'`, `slug='about-us'`, `parent=0`, `publish`, URL localizada `http://lab.ecoterra.test/en/about-us/`), `fr=8873` (`translation_id=1961`, `source='en'`, `slug='nous'`, `parent=0`, `publish`, retenible en `RetainedTranslationRegistry`).
       - **`TRID 9201` (`Actividades / Activities`):** `es=4976` (`translation_id=551`, `slug='actividades'`, `parent=0`, `publish`, URL `http://lab.ecoterra.test/actividades/`), `en=8339` (`translation_id=1748`, `source='es'`, `slug='activities'`, `parent=0`, `publish`, URL localizada `http://lab.ecoterra.test/en/activities/`), `fr=8955` (`translation_id=2035`, `source='es'`, `slug='activites'`, `parent=0`, `publish`, retenible en `RetainedTranslationRegistry`).
       - **`TRID 430` (`Galería de Fotos / Photo Gallery`):** `es=430` (`translation_id=78`, `slug='galeria'`, `parent=0`, `publish`, URL `http://lab.ecoterra.test/galeria/`), `en=8329` (`translation_id=1744`, `source='es'`, `slug='gallery'`, `parent=0`, `publish`, URL localizada `http://lab.ecoterra.test/en/gallery/`), `fr=8989` (`translation_id=2066`, `source='en'`, `slug='galerie'`, `parent=0`, `publish`, retenible en `RetainedTranslationRegistry`).
       - **`TRID 9818` (`Confort y Tranquilidad / Comfort and Tranquility`):** `es=8042` (`translation_id=1699`, `slug='confort-y-tranquilidad'`, `parent=0`, `publish`), `en=8613` (`translation_id=1838`, `source='es'`, `slug='comfort-and-tranquility'`, `parent=0`, `publish`), `fr=8952` (`translation_id=2033`, `source='en'`, `slug='chambres'`, `parent=0`, `publish`).
       - **Identidad real de los IDs atribuidos erróneamente en el informe narrativo de Fase 3.37:** los posts `9864` (`es`, `terminos-y-condiciones`, `parent=6237`) y `9863` (`en`, `terms-and-conditions`, `parent=8354`) pertenecen a **`TRID 34823`** (`Términos y Condiciones / Terms and Conditions`, con `fr=9865` en `draft`), mientras que `9614` (`es`, `lek-de-gallito-de-las-rocas`, `parent=9218`) y `9615` (`en`, `andean-cock-of-the-rock-lek`, `parent=9219`) pertenecen a **`TRID 34776`** (`Lek de Gallito de las Rocas / Andean Cock of the Rock Lek`, con `fr=9616` en `draft`).
  2. **Determinación Forense de la Causa Raíz Dual (Sin Reescribir `ADR-063` ni `ADR-065`):**
     - **Causa Raíz A — Transposición tabular en el informe narrativo de Fase 3.37 (post-compactación de contexto):**
       - En Fase 3.37 (`99a2f12bb6306646aea4421d61160c4981f18db6`), el verificador `scratch/verify_fase_3_37.php` (líneas 421–542) verificó membresía de conjuntos (`$expected_14_menu_trids` y `$candidate_15_trids`) y derivó los lotes `11A`, `11B` y `11C` directamente desde `$planner->generate_plan()`, por lo que los conjuntos de TRIDs y las huellas SHA-256 registradas en `ADR-063` (`1712e825...`, `a3e9136e...`, `64e3bcbe...`) son **matemáticamente exactos**.
       - Sin embargo, `verify_fase_3_37.php` no incluía aserciones explícitas de las tuplas individuales `(trid => es_id, en_id, fr_id, slug)`. Inmediatamente después de ejecutar el verificador (`Step 17187` en `transcript_full.jsonl`), ocurrió una compactación de ventana de contexto (`Step 17188`) antes de redactar el informe narrativo de Fase 3.37 (`Step 17210`). El resumen compactado conservó por separado las listas ordenadas numéricamente de los `20` IDs españoles (`..., 9614 [pos 17], 9666 [pos 18], 9864 [pos 19], 10109 [pos 20]`), los `20` IDs ingleses (`..., 9615 [pos 17], 9667 [pos 18], 9863 [pos 19], 10110 [pos 20]`) y los `4` TRIDs de `Lote 11A` (`432, 9201, 9545, 9818`). Al construir manualmente la tabla Markdown del informe de chat en `Step 17210` sin reconsultar MySQL, se emparejaron listas ordenadas por posición (`9864/9863` adjudicados a `TRID 9201 Nosotros`; `9614/9615` adjudicados a `TRID 9545 Confort`; `430` mezclado con `8339` en `TRID 430`).
     - **Causa Raíz B — Derivación sobre `$plan_no_conf` en lugar del `$master_plan` canónico en `scratch/verify_fase_3_39.php`:**
       - En Fase 3.39 (`639cab41dde4a841ab3d5dee0d25ffbe5d643b34`), todas las tuplas `(trid, es_id, en_id, fr_id, slug, parent)` fueron consultadas directamente en SQL y son 100% reales. No obstante, en `scratch/verify_fase_3_39.php` (líneas 647 y 663), `derive_partial_plan_with_retained_languages()` se invocó sobre `$plan_no_conf = $planner->generate_plan(0, 0, false)` (instancia con `$check_existing_conflicts = false` creada en el Bloque 1 para auditar el `TRID 197` ya importado) en lugar de invocarse sobre el plan maestro canónico pre-ejecución `$master_plan = $planner->generate_plan()` (`$check_existing_conflicts = true`, cuyo `parent_fingerprint` es `34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c`).
       - Dado que `MigrationPlan::get_fingerprint()` incluye `'parent_fingerprint' => $this->parent_fingerprint` en el payload SHA-256, las huellas registradas en el punto 4 de `ADR-065` (`ce935eb3...` y `1dc975df...`) correspondían al árbol `$check_existing_conflicts = false` y quedan formalmente sustituidas por las huellas canónicas derivadas de `$planner->generate_plan()` que se certifican a continuación.
  3. **Certificación Canónica de los Planes Parciales del Piloto de 2 TRIDs para Fase 3.40 (Priorizando `Nosotros / About Us` + `Actividades / Activities`):**
     - **Piloto Prioritario Recomendado (`pilot-nav-2-trids-9545-9201` — `Nosotros / About Us` + `Actividades / Activities`):**
       - **TRIDs incluidos:** `[9201, 9545]` (`TRID 9545`: `es=6237` `Nosotros` `/nosotros/`, `en=8354` `About Us` `/en/about-us/`, `fr=8873` `Nous` retenido; `TRID 9201`: `es=4976` `Actividades` `/actividades/`, `en=8339` `Activities` `/en/activities/`, `fr=8955` `Activités` retenido).
       - **Nota de arquitectura sobre subpáginas de `TRID 9545`:** `6237` y `8354` tienen `post_parent = 0`, por lo que sus permalinks propios (`/nosotros/` y `/en/about-us/`) no dependen de ningún ancestro. Sus 3 subpáginas (`TRIDs 424, 9838, 34823`) tienen `post_parent = 6237` (`es`) y `post_parent = 8354` (`en`), y mientras no se migren en un lote posterior permanecerán protegidas por la guarda `resolve() === null` de `NavMenuFrontendFilter::localize_menu_item()`.
       - **Estado y conteos:** `MigrationPlan::STATUS_READY`, `2` grupos `PARTIAL_RETAINED`, `4` elementos activos (`6237:es`, `8354:en`, `4976:es`, `8339:en`), `2` elementos retenidos (`8873:fr`, `8955:fr`), `0` conflictos, `0` huérfanos, `0` colisiones en `tfml_group_elements`.
       - **Parent Fingerprint (`$planner->generate_plan()`):** `34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c`.
       - **Huella SHA-256 Canónica (`get_fingerprint()`):** `ed8b9ede25ad4cf2886b8a1d3a438bfddf59cd9ed4ca3808f4daccbd3b86307d`.
     - **Piloto Alternativo (`pilot-nav-2-trids-9201-430` — `Actividades / Activities` + `Galería de Fotos / Photo Gallery`):**
       - **TRIDs incluidos:** `[430, 9201]` (`TRID 9201`: `es=4976`, `en=8339`, `fr=8955` retenido; `TRID 430`: `es=430` `Galería de Fotos` `/galeria/`, `en=8329` `Photo Gallery` `/en/gallery/`, `fr=8989` `Galerie` retenido).
       - **Estado y conteos:** `MigrationPlan::STATUS_READY`, `2` grupos `PARTIAL_RETAINED`, `4` elementos activos (`430:es`, `8329:en`, `4976:es`, `8339:en`), `2` elementos retenidos (`8989:fr`, `8955:fr`), `0` conflictos, `0` huérfanos, `0` colisiones.
       - **Parent Fingerprint (`$planner->generate_plan()`):** `34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c`.
       - **Huella SHA-256 Canónica (`get_fingerprint()`):** `2e290df19eb9f25d364c1626bfc175bcd0885479b8125f94d81e96723923f755`.
  4. **Verificador Automático de Conciliación de 7 Clases de Anomalías (`scratch/verify_fase_3_39a.php`):**
     - Se implementó y versionó `scratch/verify_fase_3_39a.php`, que valida cada tupla `(trid, es_id, en_id, fr_id, slug, parent, status)` contra `etk_t_icl_translations` y `etk_t_posts`, ejecuta un motor detector de las **7 clases de anomalías** (`trid_mismatch`, `language_mismatch`, `element_type_mismatch`, `missing_object`, `duplicate_object_across_groups`, `tfml_collision`, `plan_vs_db_inconsistency` — acreditando `0` anomalías reales y `7/7` detecciones positivas mediante inyección controlada de fallos en memoria) y verifica el determinismo de las huellas SHA-256 canónicas.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.39A / Directiva DT-TFML-069A — Subsanación bloqueante de Fase 3.39: conciliación de TRIDs, identidad editorial y seguridad del piloto de migración.
- **JUSTIFICACIÓN:** Levantar con rigor forense la reserva bloqueante del dictamen de Fase 3.39 antes de autorizar cualquier escritura de migración en `cms_ect_lab`, garantizando trazabilidad 1:1 entre `etk_t_icl_translations`, `etk_t_posts` y `WpmlMigrationPlanner::generate_plan()`.
- **CONSECUENCIAS:** Verificador reproducible `scratch/verify_fase_3_39a.php` certificado con **8/8 controles PASS (0 FAIL)**, no-regresión en `verify_fase_3_39.php` (**10/10 PASS**), **625 pruebas PHPUnit / 2.307 aserciones (0 fallos)**, **180/180 archivos PHPCS PASS** y **cero escrituras** en `cms_ect` y `cms_ect_lab` (`121` grupos, `135` elementos activos, `1` retenido `fr:8839`).

### ADR-066: Importación Transaccional Controlada del Piloto de Navegación (`pilot-nav-2-trids-9545-9201`) y Retención del Francés (Fase 3.40 / Directiva DT-TFML-070)
- **FECHA:** 2026-10-18
- **DECISIÓN:**
  1. **Respaldo Específico Fuera del Webroot y Certificación de Restauración, Idempotencia y Rollback en Base Desechable (`cms_ect_lab_restore_verify`):**
     - Antes de ejecutar cualquier escritura en `cms_ect_lab`, se generó un respaldo SQL íntegro fuera del webroot en `D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_40_pilot_trids_9545_9201_20261018_175300.sql` (`17.891.229` bytes, `2026-10-18T22:56:06Z`, SHA-256 `8f2e9f6283ce49ad9352d9f3f8e794ec9a532f7436baf9eed4ca5eb26f200396`).
     - Se restauró dicho volcado en la base de datos desechable `cms_ect_lab_restore_verify`, verificando paridad exacta con el baseline de entrada (`121` grupos, `135` elementos activos, `1` retenido `fr:8839`, `12` manifiestos, `checkpoint = completed`, `3.403` filas WPML con MD5 `4241ca7e7ec6399a594537cb04790c10`).
     - Sobre `cms_ect_lab_restore_verify` se ensayó la ejecución del piloto (`121/135/1 -> 123/139/3`), la re-ejecución idempotente (`imported_groups = 0, skipped_groups = 2 [idempotent_skipped], 0 duplicados`) y la reversibilidad selectiva mediante `WpmlMigrationRollbackService::rollback_groups()` (`2` grupos y `4` elementos revertidos más purga de los `2` retenidos franceses, regresando a `121/135/1` con `0` discrepancias), eliminando a continuación `cms_ect_lab_restore_verify` (`DROP DATABASE`).
  2. **Derivación Canónica, Dry-Run Sin Escrituras y Ejecución Transaccional Única en `cms_ect_lab`:**
     - Se derivó `pilot-nav-2-trids-9545-9201` desde `$planner->generate_plan()` (`parent_fingerprint = 34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c`), obteniendo la huella SHA-256 canónica exacta `ed8b9ede25ad4cf2886b8a1d3a438bfddf59cd9ed4ca3808f4daccbd3b86307d` (`MigrationPlan::STATUS_READY`, `2` grupos `PARTIAL_RETAINED`, `4` elementos activos, `2` elementos franceses retenibles).
     - Se validó en seco mediante `$executor->validate_plan($pilot_plan, $expected_fp)` (`valid = true, errors = []`), comprobando invarianza de digest SHA-256 antes y después del dry-run (`6dd14f1afd9f25539b6684dbf4813b5042b7b483f506e985902745b8ad4de575`).
     - Se ejecutó una **única vez** `$executor->run($pilot_plan, 50, 'pilot-nav-2-trids-9545-9201', $expected_fp)` en `cms_ect_lab`:
       - **`TRID 9201` (`Actividades / Activities`):** creado **`group_id = 588`** (`element_type = 'post'`, `subtype = 'page'`, `canonical_element_id = 4976`) con elementos activos `id = 675` (`4976:es`, `is_source = 1`, `up_to_date`) e `id = 676` (`8339:en`, `is_source = 0`, `up_to_date`), y retención de `8955:fr` (`Activités`, `source_language = 'es'`, `fingerprint = 76fd3a3f261b7c3eada3784e406ec6a97d13e70ee52910ceb678bbb1c72aa983`) en `RetainedTranslationRegistry`.
       - **`TRID 9545` (`Nosotros / About Us`):** creado **`group_id = 589`** (`element_type = 'post'`, `subtype = 'page'`, `canonical_element_id = 6237`) con elementos activos `id = 677` (`6237:es`, `is_source = 1`, `up_to_date`) e `id = 678` (`8354:en`, `is_source = 0`, `up_to_date`), y retención de `8873:fr` (`Nous`, `source_language = 'en'`, `fingerprint = 8fe2542926df895d7b73dd0a27f7e76aa0eec4ed44e54d28dcf454056c006242`) en `RetainedTranslationRegistry`.
       - Consolidación exacta en `cms_ect_lab`: **`123` grupos**, **`139` elementos activos**, **`3` traducciones francesas retenidas** (`8839, 8955, 8873`), **`13` manifiestos**, `checkpoint = completed` y digest post-ejecución `2eb9cffc34a9059a06ff19f8272d270e916616d5e1d7a7515ece53ff75f3b335`.
  3. **Certificación de Navegación HTTP Real, Sitemaps y Observación Técnica de `<link rel="canonical">` en Páginas Internas Secundarias:**
     - En servidor HTTP real (`http://lab.ecoterra.test`), `/nosotros/` (`6237`, `es-ES`), `/en/about-us/` (`8354`, `en-US`), `/actividades/` (`4976`, `es-ES`), `/en/activities/` (`8339`, `en-US`), `/` (`197`, `es-ES`) y `/en/` (`8356`, `en-US`) responden `HTTP 200` con `hreflang` bilingüe exacto (`es`, `en`, `x-default`, `0` francés).
     - En los menús de escritorio (`menu-principal` `ID 43` y `menu-es-ingles` `ID 98`), `Nosotros` apunta a `http://lab.ecoterra.test/nosotros/`, `About Us` apunta a `http://lab.ecoterra.test/en/about-us/`, `Actividades` apunta a `http://lab.ecoterra.test/actividades/` y `Activities` apunta a `http://lab.ecoterra.test/en/activities/`, mientras las `12` páginas internas aún no migradas conservan su comportamiento seguro (`0` enlaces `/en/...` `HTTP 404` prematuros).
     - En `WP_Sitemaps_Posts('page')`, las entradas elegibles pasan de `3` a `7` (`/`, `/old-blog/`, `/actividades/`, `/nosotros/`, `/en/activities/`, `/en/about-us/`, `/en/`) y el total consolidado pasa de `26` a `30` con `0` entradas francesas y `0` duplicados.
     - **Observación técnica documentada para fase posterior:** `CanonicalUrlManager::filter_canonical_url()` devuelve las URLs canónicas localizadas exactas (`http://lab.ecoterra.test/en/about-us/` y `http://lab.ecoterra.test/en/activities/`), pero `CanonicalUrlManager::init_hooks()` registra el filtro `wp_get_canonical_url` mientras que WordPress Core (`wp_get_canonical_url()` en `wp-includes/link-template.php`) dispara el filtro `get_canonical_url`. Dado que Fase 3.40 prohíbe modificar `src/`, se documenta este hallazgo para añadir `add_filter( 'get_canonical_url', ... )` en una directiva específica.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.40 / Directiva DT-TFML-070 — Importación transaccional controlada de dos grupos de navegación (`Nosotros / About Us` y `Actividades / Activities`).
- **JUSTIFICACIÓN:** Materializar en `cms_ect_lab` el primer piloto de navegación interior bilingüe (`TRIDs 9545` y `9201`) bajo garantías criptográficas, respaldo verificable fuera del webroot e idempotencia no destructiva, certificando menús y sitemaps en vivo sin alterar `cms_ect` ni las tablas originales de WPML.
- **CONSECUENCIAS:** Estado consolidado en `cms_ect_lab` de **123 grupos**, **139 elementos activos** y **3 traducciones francesas retenidas** (`8839, 8955, 8873`), verificador reproducible `scratch/verify_fase_3_40.php` con **10/10 controles PASS (0 FAIL)**, compatibilidad de `scratch/verify_fase_3_39a.php` (**8/8 PASS**), **625 pruebas PHPUnit / 2.307 aserciones (0 fallos)** y **180/180 archivos PHPCS PASS**.

---

### ADR-067: Corrección del Filtro Nativo `get_canonical_url`, Diagnóstico del Acceso Administrativo al Laboratorio e Inventario del Panel TF Multilingual (Fase 3.41 / Directiva DT-TFML-071)
- **FECHA:** 2026-10-18
- **DECISIÓN:**
  1. **Corrección Quirúrgica en `CanonicalUrlManager::init_hooks()` y Cableado de Portada en `Plugin::get_canonical_url_manager()`:**
     - En [`src/Domain/Seo/CanonicalUrlManager.php`](../src/Domain/Seo/CanonicalUrlManager.php), `init_hooks()` registraba únicamente el filtro `'wp_get_canonical_url'` (prioridad `10`), mientras que la función nativa de WordPress Core `wp_get_canonical_url()` (`wp-includes/link-template.php:4385`) invoca en realidad `apply_filters( 'get_canonical_url', $canonical_url, $post )`.
     - Como resultado, en la portada inglesa (`/en/`, `ID 8356`) el canonical salía correcto porque `ResolvedorPortadaMultilingue::filtrar_page_link()` ya interceptaba `page_link`, pero en páginas internas secundarias (`/en/about-us/` `ID 8354` y `/en/activities/` `ID 8339`) `get_permalink()` devolvía la URL base sin prefijo (`/about-us/` y `/activities/`) y `rel_canonical()` emitía `<link rel="canonical">` sin pasar por `CanonicalUrlManager::filter_canonical_url()`.
     - Se añadió en `CanonicalUrlManager::init_hooks()` el registro explícito `add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );`, manteniendo `'wp_get_canonical_url'` (prioridad `10`) por retrocompatibilidad y los filtros de plugins SEO `'wpseo_canonical'` y `'rank_math/canonical_url'` (prioridad `20`), sin agregar ningún `add_action( 'wp_head', ... )` duplicado. Adicionalmente, en [`src/Core/Plugin.php`](../src/Core/Plugin.php) (`get_canonical_url_manager()`) se aseguró la inyección de `set_resolvedor_portada()` también en instanciación perezosa.
     - Se certificó en servidor HTTP real (`http://lab.ecoterra.test`) que las 6 rutas públicas (`/nosotros/`, `/en/about-us/`, `/actividades/`, `/en/activities/`, `/` y `/en/`) emiten exactamente **1 única etiqueta `<link rel="canonical">`** con su URL localizada exacta (`http://lab.ecoterra.test/en/about-us/` y `http://lab.ecoterra.test/en/activities/` en inglés), preservando `hreflang`, menús y las `30` entradas elegibles del sitemap.
  2. **Diagnóstico en Solo Lectura del Acceso Administrativo al Laboratorio (`wp-login.php` y Pasarela `/punko`) y Aislamiento de Cuentas:**
     - Se identificó por qué abrir directamente `http://lab.ecoterra.test/wp-login.php` redirigía (`HTTP 302`) a `http://lab.ecoterra.test`: el tema activo `ecoCMS` define en `functions.php` (líneas 49–91) el hook `wpa_custom_login_gateway()` en `init` (prioridad `1`), el cual protege `wp-login.php` exigiendo la cookie `wpa_login_token = wp_hash('ecoterra_login_gate')`.
     - Al visitar primero la ruta de pasarela del tema **`http://lab.ecoterra.test/punko`** (o un enlace firmado de restablecimiento `wp-login.php?action=rp&key=...&login=...`), WordPress emite `Set-Cookie: wpa_login_token=...` (`HttpOnly`, validez de 2 horas) y redirige (`HTTP 302`) a `http://lab.ecoterra.test/wp-login.php`, donde el formulario de acceso (`#loginform`) responde `HTTP 200 OK`.
     - Se verificó en modo solo lectura sobre `cms_ect_lab.etk_t_users` (sin extraer ni mostrar `user_pass` y con **cero escrituras** en `etk_t_users` / `etk_t_usermeta`) que existe una única cuenta dedicada de laboratorio: `ID = 1`, `user_login = 'admin_lab'`, `user_email = 'admin@lab.ecoterra.test'`, `display_name = 'Admin Lab'`, con rol `administrator` (`etk_t_capabilities = a:1:{s:13:"administrator";b:1;}`, `etk_t_user_level = 10`, `2` filas en `etk_t_usermeta`), completamente aislada de las `3` cuentas de `cms_ect.etk_t_users` (`0` ocurrencias de `admin_lab` en producción). Cualquier restablecimiento de contraseña o alta de usuario queda supeditado a la autorización explícita del propietario.
  3. **Inventario Real en Solo Lectura del Panel Administrativo de TF Multilingual (`IMPLEMENTADO` / `PARCIAL` / `PENDIENTE` / `BLOQUEADO`):**
     - **Implementado (`IMPLEMENTADO`):** `TF Multilingual > Diagnóstico` (`admin.php?page=tfml-diagnostic`, `DiagnosticUi` + 3 checks en Site Health), `TF Multilingual > Cadenas de texto` (`admin.php?page=tfml-strings`, `StringEditorialUi`), `Ajustes > TF Custom Fields` (`options-general.php?page=tfml-custom-fields`, `CustomFieldsSettingsUi`), columna `Idiomas` en listados (`AdminListColumnsUi`), edición de términos (`TermEditorialUi`), metabox de medios (`MediaEditorialUi`) y las `6` rutas REST bajo `/wp-json/tf-multilingual/v1/*`.
     - **Parcial (`PARCIAL`):**
       - `Apariencia > Menús Multilingües` (`themes.php?page=tfml-navigation`, `NavMenuEditorialUi`): renderiza ubicaciones e idiomas y la resolución en frontend funciona vía grupos `term:nav_menu` (`43 <-> 98`), pero `SettingsRepository::sanitize_payload()` filtra actualmente la clave `nav_menu_locations` al recargar `tfml_settings`, y el primer submenú automático `page=tf-multilingual` duplica `page=tfml-strings` mientras `StringEditorialUi::handle_form_submission()` valida `page=tfml-strings`.
       - `PostEditorialUi` (`post.php`): permite asignar idioma, crear traducción nueva desde cero y ver estado (`UPDATED`/`REVIEW`), pero enlazar/desenlazar posts preexistentes aún no migrados solo está expuesto por REST (`POST /wp-json/tf-multilingual/v1/translations/link`) y el panel en Gutenberg usa el metabox clásico de compatibilidad en lugar de un `PluginDocumentSettingPanel` React nativo.
     - **Pendiente (`PENDIENTE`):** pantalla de gestión CRUD de **Idiomas y Selector** en `/wp-admin/` (actualmente gestionado vía `LanguageRegistry` / `SettingsRepository`), pantalla administrativa de **Migración WPML, Traducciones Retenidas y Conmutador del Adaptador** (actualmente operado vía servicios PHP/CLI y opción `tfml_enable_wpml_compatibility_adapter`), y pantalla administrativa de **Ajustes SEO / Sitemaps** (actualmente gobernado por filtros PHP).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.41 / Directiva DT-TFML-071 — Corrección Canonical y Habilitación de Acceso Administrativo al Laboratorio + Inventario del Panel TF Multilingual.
- **JUSTIFICACIÓN:** Subsanar el defecto SEO de `<link rel="canonical">` en páginas internas secundarias antes de continuar con nuevas importaciones, esclarecer sin mutaciones el mecanismo de acceso a `/wp-login.php` en el laboratorio y entregar a la Dirección Técnica una radiografía empírica completa del estado real del panel administrativo de TF Multilingual.
- **CONSECUENCIAS:** Suite PHPUnit ampliada a **626 pruebas / 2.317 aserciones (0 fallos)**, PHPCS en **180/180 archivos PASS**, verificador reproducible `scratch/verify_fase_3_41.php` con **12/12 controles PASS (0 FAIL)**, compatibilidad de `scratch/verify_fase_3_40.php` (**10/10 PASS**), 6 capturas PNG reales del panel y **cero escrituras** en `cms_ect` y `cms_ect_lab` (`123` grupos, `139` elementos activos, `3` traducciones francesas retenidas).

---

### ADR-068: Coherencia de la Jerarquía del Menú de Administración, Pantalla Funcional de Idiomas (`LanguageEditorialUi`) y Desglose Diagnóstico de Navegación y Retención Lingüística (Fase 3.42 / Directiva DT-TFML-073)
- **FECHA:** 2026-10-18 / 2026-10-19
- **DECISIÓN:**
  1. **Armonización de la Jerarquía de Menús de WP-Admin (0 Submenús Duplicados):**
     - Se eliminó la duplicidad histórica donde la entrada de primer nivel `TF Multilingual` apuntaba de forma confusa a `Cadenas de texto` (`admin.php?page=tfml-strings`), generando una entrada hija redundante.
     - La jerarquía soberana de administración queda estructurada en exactamente 3 pantallas especializadas:
       - **`TF Multilingual` / `Idiomas`** (`admin.php?page=tf-multilingual`, con alias `admin.php?page=tfml-languages`): gestión integral del catálogo lingüístico y visualización de la matriz arquitectónica (`LanguageEditorialUi`).
       - **`Cadenas de texto`** (`admin.php?page=tfml-strings`): registro y traducción de strings gettext independientes (`StringEditorialUi`).
       - **`Diagnóstico`** (`admin.php?page=tfml-diagnostic`): auditoría integral de integridad relacional, esquemas y salud del sistema (`DiagnosticUi`).
     - Al registrar el submenú de `Idiomas` con el mismo slug padre `tf-multilingual`, WordPress Core reemplaza automáticamente el submenú por defecto, garantizando cero elementos duplicados en la barra lateral.
  2. **Implementación de la Pantalla Funcional de Gestión de Idiomas (`LanguageEditorialUi`):**
     - Se implementó la clase de presentación editorial [`src/Admin/LanguageEditorialUi.php`](../src/Admin/LanguageEditorialUi.php) integrada con `LanguageRegistry` y `SettingsRepository`.
     - **Diseño de dos columnas al estilo WordPress nativo:** columna izquierda (32% ancho) con formulario de adición/edición; columna derecha (66% ancho) con tabla responsiva de idiomas configurados y matriz del sistema.
     - **Autocompletado de idiomas predefinidos:** selector rápido con catálogo estándar (español, inglés, portugués, francés, alemán, italiano, etc.) que autocompleta instantáneamente mediante JavaScript los campos de slug, nombre internacional, endónimo y locale gettext.
     - **Renderizado de insignias de bandera SVG soberanas:** resolución dinámica de banderas locales en [`assets/images/flags/`](../assets/images/flags/), incorporando la insignia SVG soberana para el idioma francés (`fr.svg`) respetando la paleta de color institucional de Ecoterra (`#1e3a2f` y `#c5a880`).
     - **Invariantes estrictos de seguridad y dominio:**
       - Todas las mutaciones (`add_language`, `toggle_active`, `set_default`, `delete_language`, `update_language`) exigen capacidad `manage_options` y nonces individuales dedicados (`NONCE_ACTION_*`).
       - **Invariante Soberano de Idioma Predeterminado:** Se bloquea tajantemente la desactivación o eliminación del idioma predeterminado del sitio (arrojando mensaje de error preventivo al usuario si se intenta forzar por URL o manipulación DOM).
  3. **Desglose Transparente y Aclaración de las Métricas de Navegación en Diagnóstico:**
     - Se reemplazó la ambigua métrica *"0 ubicaciones configuradas"* por un desglose detallado de las **3 dimensiones reales** de la navegación:
       - **Ubicaciones del tema detectadas (`2`):** `primary` y `footer` registradas en `theme_mods` del tema activo `ecoCMS`.
       - **Grupos de menús traducidos (`18`):** total de grupos activos en `tfml_groups` con `subtype = 'nav_menu_item'`.
       - **Mapeos manuales configurados (`0`):** asignaciones directas de sobrescritura en `tfml_settings['nav_menu_locations']`, reflejando que la resolución de conmutación opera de forma automática por correspondencia de grupos de traducción.
     - Este refinamiento se incorporó tanto en [`src/Diagnostic/DiagnosticService.php`](../src/Diagnostic/DiagnosticService.php) como en la Tarjeta 5 de [`src/Admin/DiagnosticUi.php`](../src/Admin/DiagnosticUi.php).
  4. **Divulgación Soberana de Traducciones Retenidas (`RetainedTranslationRegistry`):**
     - Tanto en la pantalla de `Idiomas` como en la Tarjeta 3 de `Diagnóstico`, se expone explícitamente la presencia de las **3 traducciones francesas retenidas** (`8839, 8955, 8873`).
     - Se aclara a los administradores que el francés no forma parte del catálogo activo de navegación pública (`es`, `en`, `pt`), pero sus datos están custodiados e inmunes a pérdidas dentro del registro de migración, listos para ser promovidos cuando el propietario decida activar dicho idioma.
  5. **Matriz de Servicios de Dominio vs Endpoints REST vs Pantallas WP-Admin:**
     - En lugar de crear pantallas o submenús con marcadores de posición (*placeholders*) vacíos que confunden la experiencia de usuario, se diseñó e integró la **Matriz Modular de TF Multilingual** al pie de la pantalla de Idiomas.
     - La matriz informa con total transparencia técnica qué módulos operan a nivel de servicios PHP de dominio interno, cuáles están expuestos vía REST API / Sitemaps, y cuáles disponen de paneles visuales nativos en `/wp-admin/`.
  6. **Aislamiento Absoluto e Invarianza de Datos Certificada:**
     - Cero escrituras en la base de datos de producción `cms_ect` (MD5 inmutable `4241ca7e7ec6399a594537cb04790c10` / 3.403 filas).
     - Invarianza total en la base de datos de laboratorio `cms_ect_lab`: 123 grupos de traducción, 139 elementos activos y 3 traducciones retenidas.
---

### ADR-069: Vinculación y Desvinculación Editorial de Traducciones Existentes en el Panel Administrativo (`PostEditorialUi`, `TranslationEditorialService`, REST API) (Fase 3.43 / Directiva DT-TFML-074)
- **FECHA:** 2026-10-18 / 2026-10-19
- **DECISIÓN:**
  1. **Arquitectura Centralizada en `TranslationEditorialService` sin Motores Paralelos ni Tablas Duplicadas:**
     - En lugar de crear lógica paralela en los controladores visuales, la búsqueda de candidatos (`search_link_candidates`), la vinculación relacional (`link_translation`) y la desvinculación segura (`unlink_translation`) se centralizan soberanamente en [`src/Editorial/TranslationEditorialService.php`](../src/Editorial/TranslationEditorialService.php).
     - Tanto el controlador REST (`TranslationsController::get_candidates`, `link_translation`, `unlink_translation`) como la UI editorial de WordPress (`PostEditorialUi::handle_link_translation`, `handle_unlink_translation`, `handle_ajax_search_candidates`) delegan exactamente en la misma capa de servicio de dominio.
  2. **Doble Mecanismo Editorial: Creación de Borradores (`Crear nueva`) vs Vinculación de Existentes (`Vincular existente`):**
     - En [`src/Admin/PostEditorialUi.php`](../src/Admin/PostEditorialUi.php), cada idioma faltante se presenta en una tarjeta organizada por pestañas independientes:
       - Pestaña `[+ Crear nueva]`: invoca `create_post_translation()` para generar un nuevo borrador clonando metadatos compartidos.
       - Pestaña `[🔗 Vincular existente]`: permite localizar un post/página preexistente y vincularlo al grupo existente sin crear contenido duplicado.
  3. **Buscador en Vivo Debounced (250ms), Tarjeta de Previsualización y Fallback por ID Directo:**
     - El formulario de vinculación incluye un input con autocompletado en vivo por AJAX (`admin-ajax.php?action=tfml_search_candidates`) con debounce de 250ms, filtrando estrictamente por tipo de elemento, subtipo exacto y excluyendo el post actual y elementos ya vinculados a cualquier grupo de TFML.
     - Cada elemento de la lista desplegable muestra el título, ID numérico, slug y estado de publicación (`publish`, `draft`, etc.).
     - Al seleccionar un candidato, se despliega una tarjeta de confirmación visual (`tfml-candidate-preview-box`) con los metadatos completos y se habilita el botón de acción principal.
     - Se mantiene un campo directo numérico (`target_post_id`) como mecanismo de respaldo robusto para entornos sin JavaScript o selección directa por ID.
  4. **Desvinculación Segura (`unlink_translation`) con 100% Preservación de la Entidad en Core (`wp_posts`):**
     - La desvinculación retira la correspondencia relacional en `tfml_group_elements` y actualiza/promueve el ID canónico si el elemento desvinculado era el canónico.
     - CERO operaciones de eliminación o alteración en `wp_posts` o `wp_terms`: el objeto de WordPress Core permanece 100% intacto como contenido huérfano no gestionado, disponible para ser editado individualmente o revinculado a otro grupo.
     - La acción de desvinculación se ofrece mediante un botón dedicado `[Desvincular]` con icono `dashicons-editor-unlink`, confirmación nativa de JavaScript y nonce individualizado.
  5. **Integridad Estricta de Dominio y Validaciones de Seguridad:**
     - Bloqueo de duplicidad de idioma en el grupo (`EditorialConflictException`).
     - Bloqueo de heterogeneidad de subtipos (ej. vincular un `post` a una `page`) (`EditorialValidationException`).
     - Bloqueo de vinculación de posts que ya pertenecen a otro grupo activo (`EditorialConflictException`).
     - Bloqueo de vinculación contra sí mismo (`source_id === target_id`) (`EditorialValidationException`).
     - Bloqueo de vinculación a idiomas inactivos o elementos retenidos en francés (`EditorialValidationException`).
     - Verificación obligatoria de nonces dedicados (`tfml_link_post_translation_*`, `tfml_unlink_post_translation_*`, `tfml_editorial_ajax`) y capacidades de edición (`edit_post` en el post origen y destino).
  6. **Aislamiento Estricto y Cero Mutaciones sobre la Base de Datos:**
     - Cero escrituras en producción `cms_ect` (MD5 inmutable `4241ca7e7ec6399a594537cb04790c10` / 3.403 filas).
     - Invarianza total en la base de datos de laboratorio `cms_ect_lab`: 123 grupos, 139 elementos, 3 franceses retenidos intactos.
     - Todas las demostraciones de vinculación y desvinculación se verificaron de forma sintética e inofensiva en fixtures descartables sin alterar las páginas reales de `cms_ect_lab`.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.43 / Directiva DT-TFML-074 — Vinculación Editorial de Traducciones Existentes.
- **JUSTIFICACIÓN:** Dotar al equipo editorial de una herramienta visual completa para conectar contenidos ya existentes en WordPress sin obligar a recrearlos ni recurrir a llamadas REST manuales o manipulaciones de base de datos.
- **CONSECUENCIAS:** Suite de pruebas unitarias ampliada a **655 pruebas / 2.434 aserciones (0 fallos, 0 advertencias)**, PHPCS 100% limpio en **181/181 archivos**, verificador reproducible `scratch/verify_fase_3_43.php` certificado con **10/10 controles PASS (0 FAIL)**, captura visual generada en `/brain/` (`fase_3_43_admin_post_link.png`) y 4/4 páginas frontend con HTTP 200 y canonicals correctos.
---

### ADR-070: Hardening de Seguridad Editorial: Desvinculación mediante POST Estricto, Protección Anti-Tampering y Transaccionalidad Atómica (`PostEditorialUi`, `TranslationEditorialService`, REST API) (Fase 3.43A / Directiva DT-TFML-075)
- **FECHA:** 2026-10-18 / 2026-10-19
- **DECISIÓN:**
  1. **Erradicación Absoluta de Métodos Modificadores vía GET:**
     - En conformidad con las directrices de seguridad de WordPress Core y las mejores prácticas HTTP/REST, se eliminó por completo el enlace `<a>` de desvinculación que utilizaba el método GET.
     - Se sustituyó por un formulario inline POST `<form class="tfml-unlink-form" method="post" action="admin-post.php">` con inputs ocultos para `action` (`tfml_unlink_post_translation`), `source_post_id`, `element_id` y nonce criptográfico individualizado (`tfml_unlink_nonce`), acompañado de un diálogo de confirmación explícito al usuario en el evento `onsubmit`.
     - En [`src/Admin/PostEditorialUi.php`](../src/Admin/PostEditorialUi.php), los controladores `handle_unlink_translation()`, `handle_create_translation()` y `handle_link_translation()` imponen una verificación incondicional de método (`$_SERVER['REQUEST_METHOD'] === 'POST'`), respondiendo de inmediato con `wp_die( ..., 405 )` (Method Not Allowed) ante cualquier invocación no autorizada mediante GET.
  2. **Protección Anti-Tampering y Validación Contextual Cruzada:**
     - En [`src/Editorial/TranslationEditorialService.php`](../src/Editorial/TranslationEditorialService.php), el método `unlink_translation( string $element_type, int $element_id, ?int $new_canonical_id = null, ?int $context_source_id = null )` incorpora la validación obligatoria del contexto de origen `$context_source_id`.
     - Si se suministra un post de contexto, el servicio verifica rigurosamente que tanto el elemento a desvincular como el post de contexto pertenezcan exactamente al mismo grupo de traducción de TF Multilingual. Cualquier intento de desvincular un elemento desde el contexto de otro grupo arroja `EditorialConflictException` previniendo manipulaciones maliciosas o accidentales entre grupos cruzados.
     - Se valida que el usuario cuente con los permisos y capacidades (`edit_post`) tanto sobre el elemento destino como sobre el post de contexto de origen (`EditorialPermissionException`).
  3. **Integridad Relacional, Ciclo de Vida y Transaccionalidad Completa:**
     - **Desvinculación Secundaria:** Se retira la fila relacional en `tfml_group_elements` preservando el objeto de WordPress Core en `wp_posts` al 100% sin alteración.
     - **Desvinculación Canónica:** Se promueve automáticamente el elemento restante del grupo como nuevo canónico.
     - **Desvinculación del Último Elemento:** Si solo queda un elemento en el grupo, su desvinculación purga limpiamente el grupo huérfano en `tfml_translation_groups`, dejando el post en `wp_posts` como entidad autónoma no gestionada.
     - **Preservación Inmune de Traducciones Retenidas:** Se certifica que las traducciones francesas retenidas (`8839, 8955, 8873`) registradas en `RetainedTranslationRegistry` permanecen totalmente inmunes e inmutables durante las desvinculaciones de elementos activos.
     - **Idempotencia y Manejo de Reintentos:** Intentos repetidos de desvinculación sobre elementos no gestionados arrojan limpiamente `EditorialValidationException`.
     - **Transaccionalidad Atómica:** Toda mutación relacional se ejecuta en bloque transaccional atómico (`begin_transaction`, `commit_transaction`, `rollback_transaction`), revirtiendo por completo cualquier cambio previo si se produce un fallo o error en base de datos.
  4. **Alineación con el Endpoint REST (`/translations/unlink`):**
     - En [`src/Rest/TranslationsController.php`](../src/Rest/TranslationsController.php), se actualizó la ruta de desvinculación para admitir el parámetro `source_id`, validando capacidades del usuario en `unlink_permissions_check()` y verificando anti-tampering antes de delegar a `TranslationEditorialService`.
  5. **Aislamiento Absoluto de Datos y Certificación Invariable:**
     - Producción `cms_ect` intocada: MD5 inmutable `4241ca7e7ec6399a594537cb04790c10` (3.403 filas).
     - Laboratorio `cms_ect_lab` invariable: 123 grupos, 139 elementos y 3 traducciones francesas retenidas preservadas.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.43A / Directiva DT-TFML-075 — Hardening de Vinculación y Desvinculación Editorial.
- **JUSTIFICACIÓN:** Resolver las observaciones técnicas de seguridad erradicando el uso del método GET para operaciones que modifican el estado del sistema, reforzando la protección CSRF y previniendo manipulaciones maliciosas entre grupos editoriales.
- **CONSECUENCIAS:** Suite de pruebas PHPUnit ampliada a **670 pruebas / 2.475 aserciones (100% PASS, 0 errores, 1 omitida)**, PHPCS 100% limpio en **183/183 archivos**, verificador reproducible `scratch/verify_fase_3_43a.php` certificado con **10/10 controles PASS (0 FAIL)**, captura visual de alta resolución `fase_3_43a_admin_post_link.png` y 4/4 URLs frontend verificadas con HTTP 200 y canonicals válidos.
---

### ADR-071: Centro Administrativo de Traducciones, Consulta Unificada de Contenidos Multilingües y Resolución Definitiva de la Reserva de Fase 3.43A sobre Traducciones Retenidas (TranslationsOverviewUi, TranslationGroupRepository, TranslationEditorialService) (Fase 3.44 / Directiva DT-TFML-076)
- **FECHA:** 2026-10-19
- **DECISIÓN:**
  1. **Resolución Definitiva de la Reserva de Fase 3.43A (Preservación de Grupos con Retenidas):**
     - En [`src/Domain/Translation/TranslationGroupRepository.php`](../src/Domain/Translation/TranslationGroupRepository.php), se actualizó el método `remove_translation()` admitiendo el parámetro booleano `$purge_empty_group = true`. Cuando `$purge_empty_group` es falso y se desvincula el último elemento activo (0 elementos restantes), la fila del grupo en `tfml_groups` no es purgada; en su lugar, se actualiza `canonical_element_id` a `null`.
     - En [`src/Editorial/TranslationEditorialService.php`](../src/Editorial/TranslationEditorialService.php), el método `unlink_translation()` consulta previamente a `RetainedTranslationRegistry::get_by_group_id($group_id)`. Si el grupo alberga traducciones históricas retenidas (e.g. idioma francés), se instruye al repositorio para preservar la fila del grupo (`$purge_empty = false`).
     - Esto resuelve definitivamente la reserva de Fase 3.43A: se mantiene la clave foránea conceptual, la trazabilidad del manifiesto de migración, y se garantiza que `promote_retained_translation()` pueda reconectar y promover de inmediato la entidad retenida como nuevo canónico una vez activado el idioma en el futuro.
  2. **Nuevo Centro de Traducciones en el Panel de Administración (`TranslationsOverviewUi`):**
     - Se creó la clase [`src/Admin/TranslationsOverviewUi.php`](../src/Admin/TranslationsOverviewUi.php), registrando la nueva pantalla `TF Multilingual → Traducciones` (`admin.php?page=tfml-translations`).
     - Se integró la jerarquía de submenús mediante prioridades calibradas en la acción `admin_menu`:
       * Prioridad 9: `Idiomas` (`page=tf-multilingual`)
       * Prioridad 10: `Traducciones` (`page=tfml-translations`)
       * Prioridad 11: `Cadenas de texto` (`page=tfml-strings`)
       * Prioridad 12: `Diagnóstico` (`page=tfml-diagnostic`)
     - Capacidad requerida para consulta: `edit_posts`.
  3. **Resumen Ejecutivo y Métricas Globales (Tarjetas KPI):**
     - El encabezado del Centro de Traducciones despliega 4 tarjetas estadísticas basadas en el sistema de diseño nativo de WordPress:
       * **Grupos Registrados:** Total de grupos activos en el sistema (123).
       * **Elementos Vinculados:** Total de publicaciones y términos gestionados (139).
       * **Idiomas Activos:** Idiomas con catálogo público en producción (`ES`, `EN`, `PT`).
       * **Traducciones Retenidas:** Registros en custodia histórica (3 registros FR).
  4. **Motor de Consulta Paginado y Filtros Flexibles (`query_groups_overview`):**
     - En [`src/Domain/Translation/TranslationGroupRepository.php`](../src/Domain/Translation/TranslationGroupRepository.php), se implementó `query_groups_overview( array $args = array() ): array`, que ejecuta consultas SQL optimizadas con `LIMIT` y `OFFSET`, calculando totales globales y paginados.
     - Soporta búsqueda de texto en títulos, slugs o IDs de elemento (`s`), filtro por subtipo exacto (`subtype`) mediante catálogo dinámico `get_distinct_subtypes()`, filtro por estado de traducción (`status`: todos, completas, incompletas, revisión) y filtro por idioma presente (`lang`).
  5. **Tabla Central de Traducciones e Indicadores Editoriales:**
     - La tabla utiliza clases estándar `.wp-list-table.widefat.fixed.striped` con columnas para: Grupo / Subtipo, Contenido Canónico (con enlace directo al editor y estado del post), y una columna por cada idioma activo (`ES`, `EN`, `PT`).
     - Cada celda presenta insignias de estado: `Actualizada` (verde, `dashicons-yes`), `Requiere revisión` (amarillo, `dashicons-warning`), `— Sin traducción [+ Traducir]` (enlace rápido al editor).
     - El contenido canónico está claramente diferenciado con la etiqueta visual `Origen`.
  6. **Sección Informativa de Custodia Histórica Francesa:**
     - Al pie de la pantalla, un contenedor `.postbox` con borde distintivo expone con total transparencia técnica las 3 traducciones retenidas de la migración WPML (`Accueil #8839`, `Activités #8955`, `Nous #8873`), sus TRIDs de origen (`197`, `9201`, `9545`), grupo TFML vinculado y su estado de custodia inactiva.
  7. **Cero Mutaciones y Aislamiento Absoluto de Datos:**
     - La pantalla es 100% de lectura / consulta; no realiza ninguna operación de escritura en base de datos.
     - Producción `cms_ect` intocada: MD5 inmutable `4241ca7e7ec6399a594537cb04790c10` (3.403 filas).
     - Laboratorio `cms_ect_lab` invariable: 123 grupos, 139 elementos y 3 traducciones retenidas preservadas.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.44 / Directiva DT-TFML-076 — Centro Administrativo de Traducciones.
- **JUSTIFICACIÓN:** Dotar a los administradores de un panel centralizado para visualizar, buscar y supervisar el estado de todas las traducciones del sitio, al tiempo que se resuelve de manera definitiva la reserva de Fase 3.43A protegiendo las traducciones retenidas ante desvinculaciones.
- **CONSECUENCIAS:** Suite de pruebas PHPUnit ampliada a **681 pruebas / 2.526 aserciones (100% PASS, 0 errores, 1 omitida)**, PHPCS 100% limpio en todo `src/` (**102/102 archivos**), verificador reproducible `scratch/verify_fase_3_44.php` certificado con **10/10 controles PASS (0 FAIL)**, capturas visuales de alta definición generadas en `/brain/` (`fase_3_44_admin_translations.png`, `fase_3_44_admin_translations_full.png`) y 4/4 URLs frontend verificadas con HTTP 200 y canonicals correctos.

---

### ADR-072: Panel Administrativo de Migración WPML (Modo Consulta y Auditoría)
- **DECISIÓN:** Crear una interfaz administrativa integral bajo `TF Multilingual → Migración WPML` (`admin.php?page=tfml-migration`) para la inspección, auditoría, trazabilidad y planificación de la migración WPML, reutilizando exclusivamente los servicios certificados existentes (`MigrationManager`, `WpmlMigrationPlanner`, `MigrationPlan`, `MigrationCheckpointRepository`, `RetainedTranslationRegistry`, `WpmlMigrationRollbackService`, `WpmlSourceAnalyzer`) sin implementar motores paralelos y garantizando un modo estrictamente de solo lectura (cero escrituras).
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.45 / Directiva DT-TFML-077 — Panel Administrativo de Migración WPML.
- **JUSTIFICACIÓN:** Dotar al administrador del sistema y auditores técnicos de una pantalla unificada para supervisar el avance de la migración WPML (3.403 filas en `icl_translations`, 249 TRIDs sin adjuntos), contrastar los 123 grupos ya incorporados a TFML (139 elementos), auditar el punto de control activo (`#9545 completed`) y los 13 manifiestos de lotes históricos inmutables, transparentar las 3 traducciones en custodia histórica (idioma francés inactivo) y presentar una propuesta técnica detallada para el próximo lote mínimo de páginas (diferenciando las 7 independientes de las 12 jerárquicas con padres activos).
- **DETALLES DE IMPLEMENTACIÓN:**
  1. **Servicio y Componente UI Dedicado (`MigrationUi`):**
     - Se implementó la clase [`src/Admin/MigrationUi.php`](../src/Admin/MigrationUi.php), registrando la nueva pantalla `TF Multilingual → Migración WPML` (`admin.php?page=tfml-migration`) con capacidad `manage_options`.
     - Se calibró la jerarquía de submenús mediante prioridades en la acción `admin_menu`:
       * Prioridad 9: `Idiomas` (`page=tf-multilingual`)
       * Prioridad 10: `Traducciones` (`page=tfml-translations`)
       * Prioridad 11: `Cadenas de texto` (`page=tfml-strings`)
       * Prioridad 12: `Migración WPML` (`page=tfml-migration`)
       * Prioridad 13: `Diagnóstico` (`page=tfml-diagnostic`)
  2. **Garantía Estricta de Solo Consulta (Read-Only Security):**
     - Banner de seguridad prominente en la cabecera advirtiendo el modo consulta y auditoría.
     - Bloqueo inmediato con `wp_die( ..., 403 )` ante cualquier intento de solicitud `POST` o parámetros modificadores.
     - Cero mutaciones en la base de datos de producción (`cms_ect`) ni en la de laboratorio (`cms_ect_lab`), garantizando la invarianza absoluta de los 123 grupos, 139 elementos y 3 traducciones retenidas.
  3. **Cuatro Pestañas de Navegación Funcional:**
     - **Pestaña 1: Plan Determinista (`tab=plan`):** Despliega la huella criptográfica SHA-256 (`c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99`), estado `NEEDS_CONFIGURATION`, resumen de deduplicación de medios (Model B: 3.033 adjuntos), filtros por clasificación (`all`, `blocked_by_language`, `conflict`, `orphan`, `skipped`, `importable`), subtipo y búsqueda (`s`), con paginación a 20 grupos por página.
     - **Pestaña 2: Historial de Lotes (`tab=history`):** Expone el punto de control activo (`status: completed`, `last_processed_trid: 9545`, `total_items: 2`, bloqueo libre) y la tabla histórica de los 13 manifiestos inmutables en `wp_options` (`tfml_migration_manifest_*`), sumando exactamente 123 grupos importados.
     - **Pestaña 3: Custodia Histórica (`tab=retained`):** Explica con total transparencia el modelo de cero polución activa y desglosa las 3 traducciones francesas en custodia (`Accueil #8839`, `Activités #8955`, `Nous #8873`) asociadas a sus respectivos grupos TFML (`#587`, `#588`, `#589`).
     - **Pestaña 4: Propuesta Próximo Lote (`tab=next_batch`):** Análisis analítico de las 19 páginas en `BLOCKED_BY_LANGUAGE`. Diferencia 7 páginas independientes (TRIDs 430, 432, 9537, 9634, 9697, 9818, 34831) de 12 páginas jerárquicas con padres ya migrados en TFML (#6237 Nosotros, #6505 Servicios, #9218 Experiencia Transformadora), detallando las precondiciones mandatorias para una futura ejecución.
  4. **Enriquecimiento del Servicio de Rollback (`WpmlMigrationRollbackService`):**
     - Se añadió el método público `get_all_execution_manifests(): array` para recuperar cronológicamente todos los manifiestos de ejecución persistidos en `wp_options`, soportando tanto la base de datos real como entornos de pruebas sintéticos.
- **CONSECUENCIAS:**
  - Suite de pruebas PHPUnit ampliada a **691 pruebas / 2.558 aserciones (100% PASS, 0 errores, 1 omitida)** con [`tests/Unit/MigrationUiTest.php`](../tests/Unit/MigrationUiTest.php).
  - PHPCS 100% limpio en todo el proyecto (**0 errores, 0 avisos**).
  - Verificador reproducible `scratch/verify_fase_3_45.php` certificado con **10/10 controles PASS (0 fallos)**.
  - 4 capturas visuales de alta definición generadas en el directorio de artefactos (`fase_3_45_admin_migration_plan.png`, `fase_3_45_admin_migration_history.png`, `fase_3_45_admin_migration_retained.png`, `fase_3_45_admin_migration_next_batch.png`).
  - Cero regresiones en frontend: HTTP 200, canonicals íntegros y 30 entradas en sitemaps sin traducciones retenidas.

---

### ADR-073: Conciliación Canónica de Identidades WPML, Arquitectura Jerárquica y Preparación del Piloto Mínimo (Fase 3.45A / Directiva DT-TFML-078)
- **FECHA:** 2026-10-19
- **DECISIÓN:**
  1. **Conciliación Forense en Solo Lectura de las 19 Páginas en `BLOCKED_BY_LANGUAGE`:**
     - Se realizó una auditoría directa contra `cms_ect_lab.etk_t_icl_translations` y `etk_t_posts` para verificar las identidades exactas (ID, slug, título, estado de publicación y jerarquía) de todas las páginas catalogadas con variantes en francés.
     - Se purgaron las denominaciones erróneas y conjeturas del informe anterior, conciliando con precisión matemática:
       * TRID 430: "Galería de Fotos" (`galeria`) / "Photo Gallery" (`gallery`) / "Galerie" (`galerie`).
       * TRID 432: "Contactenos" (`contactenos`) / "Contact Us" (`contact-us`) / "Contactez-nous" (`contactez-nous`).
       * TRID 9537: "Paquetes" (`paquetes`) / "Tour Packages" (`tour-packages`) / "Forfaits touristiques" (`forfaits-touristiques`).
       * TRID 9563: "Servicios" (`servicios`) / "Services" (`services`) / "Services" (`services`) *(Página raíz autónoma, futuro padre jerárquico)*.
       * TRID 9634: "Naturaleza Autentica" (`naturaleza-autentica`) / "Authentic Nature" (`authentic-nature`) / "Nature" (`nature`).
       * TRID 9697: "Experiencia EcoTerra" (`experiencia-ecoterra`) / "Ecoterra Experience" (`ecoterra-experience`) / "Expérience Ecoterra" (`experience-ecoterra`).
       * TRID 9818: "Confort y Tranquilidad" (`confort-y-tranquilidad`) / "Comfort and Tranquility" (`comfort-and-tranquility`) / "Chambres" (`chambres`).
       * TRID 34652: "Experiencia Transformadora" (`experiencia-transformadora`) / "Transformative Experience" (`transformative-experience`) / "Qui somme nous?" *(Página raíz autónoma, futuro padre jerárquico)*.
       * TRID 34831: "Blog" (`blog`) / "Blog" (`blog`) / "Blog" (`blog`).
  2. **Rectificación de Grupos Acreditados y Purga de Conjeturas sobre Grupos Inexistentes:**
     - Se confirmaron los grupos acreditados del piloto histórico:
       * Grupo TFML #587: Inicio (Post #197 ES / Post #8356 EN), TRID 197.
       * Grupo TFML #588: Actividades (Post #4976 ES / Post #8339 EN), TRID 9201.
       * Grupo TFML #589: Nosotros (Post #6237 ES / Post #8354 EN), TRID 9545.
     - Se demostró empíricamente la inexistencia de grupos #593 y #594 en la base de datos (el ID máximo en `etk_t_tfml_groups` es 589). Los posts #6505 (Servicios) y #9218 (Experiencia Transformadora) no habían sido migrados a TFML.
  3. **Segregación Estricta de Dependencias Jerárquicas (3 Categorías Nítidas):**
     - **Categoría A (9 Páginas Raíz Autónomas, `post_parent = 0`):** TRIDs 430, 432, 9537, 9563, 9634, 9697, 9818, 34652, 34831. Cero dependencias previas; requieren exclusivamente autorización de retención para su variante francesa.
     - **Categoría B (4 Páginas Jerárquicas con Padre Acreditado en TFML):** Hijas de Nosotros / About Us (Grupo TFML #589): TRID 424 (¿Quienes Somos?), TRID 9838 (Reviews), TRID 34632 (Accesibilidad y Transportes) y TRID 34823 (Terms and Conditions). Dependencia de padre 100% resuelta en TFML.
     - **Categoría C (6 Páginas Jerárquicas Bloqueadas por Padre Pendiente):** Hijas de Servicios (#6505: TRIDs 9326 y 9574) e Hijas de Experiencia Transformadora (#9218: TRIDs 34660, 34776, 34790, 34791). Bloqueadas incondicionalmente por integridad referencial hasta que sus respectivos padres sean migrados previamente.
  4. **Rediseño del Panel `MigrationUi` (Tab `next_batch`):**
     - Advertencia de Bloqueo Preventivo: Plan Maestro en `NEEDS_CONFIGURATION` con huella `c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99`.
     - Tres tablas independientes renderizando títulos y slugs dinámicamente desde la base de datos real.
     - Tarjeta destacada del Piloto Mínimo Certificado (`pilot-nav-2-trids-430-432`) con derivación canónica mediante `MigrationPlan::derive_partial_plan_with_retained_languages()`:
       * Estado del Plan Parcial: `READY`.
       * Huella del Plan Parcial (pilot_fp): `e18b00794e7ab339347c9fe101e067f2c95f339643a0957d765e1b135446c130`.
       * Huella del Plan Maestro Padre (parent_fp): `c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99`.
       * TRIDs ordenados canónicamente: `#430` y `#432`.
       * Elementos ES/EN activos esperados: `4`.
       * Traducciones francesas en custodia retenida: `2` (#8989 y #8823).
       * Estado de Ejecución: `DETENIDO / NO AUTORIZADO` (cero mutaciones, modo estricto de auditoría).
  5. **Invarianza Absoluta de Datos:**
     - Producción `cms_ect` intocada: MD5 inmutable `4241ca7e7ec6399a594537cb04790c10` (3.403 filas).
     - Laboratorio `cms_ect_lab` invariable: 123 grupos, 139 elementos y 3 traducciones retenidas preservadas.
- **ESTADO:** **ACEPTADA**
- **CONTEXTO:** Fase 3.45A / Directiva DT-TFML-078 — Conciliación Canónica del Panel de Migración y Preparación del Siguiente Lote.
- **JUSTIFICACIÓN:** Resolver las observaciones técnicas de la auditoría de Fase 3.45, corrigiendo la identificación errónea de los TRIDs, clarificando las dependencias jerárquicas y certificando un lote piloto mínimo determinista sin mutaciones en base de datos.
- **CONSECUENCIAS:** Verificador reproducible `scratch/verify_fase_3_45a.php` certificado con **10/10 controles PASS (0 fallos)**, 691 pruebas PHPUnit superadas al 100%, PHPCS 100% limpio en todo el proyecto (187/187 archivos) y captura de alta resolución generada (`fase_3_45a_admin_migration_next_batch.png`).





