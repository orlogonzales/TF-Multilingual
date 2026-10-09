# MATRIZ DE COMPATIBILIDAD TÉCNICA — ELEMENTOR Y ELEMENTOR PRO
**Documento:** COMPATIBILIDAD-ELEMENTOR.md  
**Proyecto:** TF Multilingual (`tf-multilingual`)  
**Versión:** 1.0.0  
**Fecha:** 2026-10-09  
**Estado:** **OFICIAL — FASE 3.2**  

---

## 1. PROPÓSITO Y PRINCIPIO RECTOR

Este documento formaliza la **Matriz de Compatibilidad Real** entre TF Multilingual y los constructores visuales **Elementor Core** y **Elementor Pro**, conforme al dictamen de la Dirección Técnica para la Fase 3.2.

**Principio de Honestidad Técnica:**  
No se declara compatibilidad con ningún componente, widget o subsistema sin evidencia verificable en pruebas unitarias automatizadas y pruebas de laboratorio en entorno de ejecución real de WordPress.

---

## 2. MATRIZ DE COMPATIBILIDAD POR SUBSISTEMA

| Subsistema / Función | Estado | Entorno Verificado | Notas Técnicas y Restricciones |
| :--- | :---: | :---: | :--- |
| **Detección Dinámica de Runtime** | ✅ **VERIFICADO** | Core 4.3.4 / Pro 4.1.0 | Detección reactiva mediante constantes `ELEMENTOR_VERSION` y `ELEMENTOR_PRO_VERSION`. Cero fallos si Elementor está ausente. |
| **Políticas de Metadatos Core** | ✅ **VERIFICADO** | Core 4.3.4 | `_elementor_edit_mode` (`SHARE`), `_elementor_template_type` (`SHARE`), `_elementor_version` (`SHARE`), `_wp_page_template` (`SHARE`). |
| **Políticas de Metadatos Pro** | ✅ **VERIFICADO** | Pro 4.1.0 | `_elementor_pro_version` (`SHARE`). |
| **Independencia Editorial del Árbol JSON** | ✅ **VERIFICADO** | Core 4.3.4 | `_elementor_data` (`TRANSLATE`) y `_elementor_page_settings` (`TRANSLATE`). Mutaciones en traducciones no afectan al post fuente. |
| **Caché Dinámica de Estilos CSS** | ✅ **VERIFICADO** | Core 4.3.4 | `_elementor_css` (`IGNORE`). Elementor recompila sus hojas de estilo específicas por idioma y post. |
| **Preservación de IDs Técnicos** | ✅ **VERIFICADO** | Core 4.3.4 | Los identificadores de 7 caracteres (`id: "..."`) de secciones, columnas y widgets se preservan íntegros para no invalidar reglas CSS `.elementor-element-{id}`. |
| **Zero-Cloning Selectivo** | ✅ **VERIFICADO** | Core 4.3.4 | Posts regulares y Gutenberg conservan `post_content` inicial vacío y cero metadatos de Elementor. Posts construidos con Elementor clonan y localizan el árbol. |
| **Renderizado Frontend en Vivo** | ✅ **VERIFICADO** | Core 4.3.4 / Pro 4.1.0 | Certificado con `\Elementor\Plugin::$instance->frontend->get_builder_content_for_display()`. |
| **Widgets Básicos de Contenido** | ✅ **VERIFICADO** | Core 4.3.4 | `heading`, `text-editor`, `image`, `button`, `icon`. |
| **Localización de Medios Simples** | ✅ **VERIFICADO** | Core 4.3.4 | `image`, `photo`, `background_image`, `icon` (actualización atómica de `id` vía `MediaTranslationResolver` y fallback de `url`). |
| **Localización de Enlaces Internos** | ✅ **VERIFICADO** | Core 4.3.4 | Sustitución asistida de `url` en controles de enlace cuando apuntan a contenidos con traducción existente en el idioma destino. |
| **Widgets de Galerías / Carruseles** | ✅ **VERIFICADO** | Core 4.3.4 / Pro 4.1.0 | `gallery`, `carousel` (recorrido recursivo y traducción de arrays de medios `['id' => X, 'url' => Y]`). |
| **Widgets de Plantillas / Template** | ✅ **VERIFICADO** | Pro 4.1.0 | `template_id` en widgets de tipo template mapeado al ID de la plantilla traducida correspondiente. |
| **Theme Builder (Header, Footer, Single)** | ⏳ **PENDIENTE** | No certificado | Requiere adaptar condiciones de visualización dinámica (`_elementor_conditions`) por idioma. |
| **Formularios de Elementor Pro (`form`)** | ⏳ **PENDIENTE** | No certificado | Acciones post-envío (redirecciones localizadas, webhooks), etiquetas y campos dinámicos de formularios. |
| **Etiquetas Dinámicas (`__dynamic__`)** | ⏳ **PENDIENTE** | No certificado | Tags dinámicos de Core/ACF incrustados en settings de widgets requieren resolución en tiempo de renderizado. |
| **Widgets Globales (`widgetType: 'global'`)**| ⏳ **PENDIENTE** | No certificado | Sincronización multi-página de widgets globales entre diferentes idiomas. |
| **Contenedores Flexbox/Grid Complejos** | ⏳ **PENDIENTE** | No certificado | Anidamientos profundos (>4 niveles) con CSS responsive personalizado por breakpoint. |
| **Loop Builder de Elementor Pro** | ⏳ **PENDIENTE** | No certificado | Maquetación de loops dinámicos (`loop-grid`, `loop-carousel`) vinculados a CPTs y taxonomías. |
| **Popups de Elementor Pro** | ⏳ **PENDIENTE** | No certificado | Triggers, cookies y condiciones de despliegue modal por idioma. |
| **Widgets y Addons de Terceros** | ❌ **NO CERTIFICADO**| N/A | Essential Addons, JetElements, Happy Addons, etc. Requieren adaptadores específicos y no deben asumirse compatibles. |

---

## 3. DETALLE DE FUNCIONES VERIFICADAS

### 3.1 Elementor Core
1. **Estructura Jerárquica del Árbol JSON:**
   - Decodificación segura de `_elementor_data` mediante `json_decode( $data, true )`.
   - Recorrido recursivo exhaustivo de elementos: `section`, `column`, `container` y `widget`.
   - Persistencia protegida mediante `wp_slash( wp_json_encode( $elements ) )` para evitar que `update_post_meta()` elimine las barras de escape.
2. **Localización Asistida de Campos:**
   - **Medios:** Búsqueda recursiva en `settings` de claves de imagen y mapeo de IDs traducidos mediante `MediaTranslationResolver::resolve()`.
   - **Enlaces:** Localización de URLs internas hacia el permalink del elemento traducido correspondiente en el idioma de destino.
3. **Independencia Editorial Estricta:**
   - El árbol JSON del idioma destino es una copia estructural desacoplada. Modificar títulos, párrafos o estilos en la traducción no produce mutación alguna en el contenido del post original.
4. **Zero-Cloning Selectivo:**
   - Si `_elementor_edit_mode !== 'builder'`, el post no se trata como documento de Elementor, manteniendo el principio general de que `post_content` inicial queda vacío en borradores de traducción.

### 3.2 Elementor Pro
1. **Detección Reactiva:**
   - Reconocimiento de `ELEMENTOR_PRO_VERSION` en `IntegrationManager` y `ElementorIntegration`.
2. **Galerías y Carruseles:**
   - Soporte para arrays de ítems de medios en `settings` (e.g. `gallery => [ ['id' => 10, 'url' => '...'], ... ]`).
3. **Referencias a Plantillas:**
   - Mapeo de `template_id` en widgets de tipo template mediante `ContentTranslationResolver::resolve_element_id()`.

---

## 4. FUNCIONES PENDIENTES DE CERTIFICACIÓN (ALCANCE POST-CORE)

Las siguientes funciones se mantienen explícitamente en el **Backlog Técnico** y no deben promocionarse como compatibles hasta contar con suite de pruebas dedicada y evidencia de laboratorio:

### 4.1 Theme Builder de Elementor Pro
- **Condiciones de Visualización:** Elementor Pro almacena las condiciones de despliegue en el post meta `_elementor_conditions` (e.g. `include/general`, `include/singular/page`). En entornos multilingües, cada plantilla de Header o Footer en un idioma secundario debe reflejar las condiciones adecuadas para las URLs prefijadas del idioma correspondiente.

### 4.2 Formularios de Elementor Pro (`form`)
- **Acciones tras Envío:** Las acciones de tipo *Redirect* deben apuntar a la página de agradecimiento traducida en el idioma activo del usuario.
- **Campos y Notificaciones por Correo:** El asunto y cuerpo del email de notificación deben poderse traducir independientemente.

### 4.3 Contenido Dinámico / Dynamic Tags (`__dynamic__`)
- Elementor almacena tags dinámicos como strings codificados dentro de `settings.__dynamic__` (ej. `[elementor-tag id="..." name="post-title"]`). Requiere un parser especializado para resolver las referencias a campos personalizados de ACF o metadatos de WordPress según el idioma activo.

### 4.4 Widgets Globales (`widgetType = 'global'`)
- Un widget global en Elementor posee su propio post en `elementor_library` y se referencia mediante `template_id`. La traducción de una página debe enlazar con la traducción del widget global, no con el original.

---

## 5. ADDONS DE TERCEROS (NO CERTIFICADOS)

Queda formalmente establecido que TF Multilingual **no certifica** la compatibilidad con complementos de terceros para Elementor, entre ellos:
- **Essential Addons for Elementor**
- **Premium Addons for Elementor**
- **Crocoblock / JetElements / JetEngine**
- **Happy Addons**
- **Elementor Header & Footer Builder**

Cualquier compatibilidad futura con estos módulos requerirá un adaptador desacoplado dedicado dentro de `TF\Multilingual\Integration\` sujeto a su propio ciclo de pruebas.
