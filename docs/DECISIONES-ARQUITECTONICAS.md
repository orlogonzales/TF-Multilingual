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

