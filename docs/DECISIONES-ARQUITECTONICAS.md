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
     - Subtipos taxonómicos y tipos de post involucrados: 8 grupos de `elementor_library` (posts) y 2 grupos de `elementor_library_type` (terms).
     - Cardinalidad verificada: 10 grupos y 11 elementos (incluye el primer grupo bilingüe de plantillas: TRID 9208 con Post 5048 en español y Post 5055 en inglés).
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
