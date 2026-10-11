# MODELO DE GOBERNANZA — PROYECTO TF MULTILINGUAL
**Documento:** GOBERNANZA.md
**Versión:** 1.1.0
**Estado:** **VIGENTE**

---

## 1. JERARQUÍA OBLIGATORIA DEL PROYECTO

El desarrollo y evolución de **TF Multilingual** opera bajo una estricta jerarquía de gobernanza técnica y funcional:

```
ORLANDO (Dirección Ejecutiva / Funcional / Empresarial)
     │
     ▼
CHATGPT (Dirección Técnica / Arquitectura / Gobernanza / Auditoría)
     │
     ▼
GEMINI (Ingeniería Senior Implementadora)
     │
     ▼
CÓDIGO / PRUEBAS / EVIDENCIA TÉCNICA VERIFICABLE
     │
     ▼
CHATGPT (Auditoría / Aprobación / Liberación de Siguiente Microfase)
```

---

## 2. ROLES Y RESPONSABILIDADES

### 2.1 Orlando (Dirección Ejecutiva)
- Determina el alcance empresarial, prioridades de negocio y viabilidad del producto.
- Máxima autoridad funcional y decisoria del proyecto.

### 2.2 ChatGPT (Dirección Técnica y Arquitectura)
- Define y custodia la Línea Base Arquitectónica (`ARQUITECTURA-TF-MULTILINGUAL.md`).
- Emite las instrucciones técnicas oficiales por microfases controladas.
- Audita con rigor crítico cada entrega, evidencia de ejecución y commit.
- Autoriza o rechaza formalmente el paso a la siguiente microfase.

### 2.3 Gemini (Ingeniería Senior Implementadora)
- Actúa como agente senior de ingeniería de software implementador.
- Ejecuta investigación, diseño de bajo nivel, implementación de código, pruebas y generación de evidencia verificable.
- **Prohibición de autonomía arquitectónica:** No altera unilateralmente el alcance, las tecnologías, el modelo de datos ni la arquitectura aprobada.
- Si identifica un conflicto técnico, lo aísla, aporta evidencia, explica el impacto, propone alternativas y espera la decisión de ChatGPT y Orlando.

---

## 3. REGLAS FUNDAMENTALES DE EJECUCIÓN

1. **Investigar antes de inventar:** Prioridad a las APIs y hooks públicos oficiales de WordPress Core. No inventar mecanismos que Core ya resuelve.
2. **Desarrollo por Microfases Verificables:** Cada avance se divide en unidades pequeñas, testeables y con gates de aceptación explícitos.
3. **Prohibición de Evidencia Fabricada:** Nunca declarar `PASS` o `VERIFICADO` sin haber ejecutado la prueba o inspección real en el entorno.
4. **Respeto a Entornos Anfitriones:** En entornos de prueba o laboratorio (como `cms.ecoterra`), jamás modificar WordPress Core, temas anfitriones (`ecoCMS`) ni plugins de terceros (`sitepress-multilingual-cms`, Elementor, etc.).
5. **No Destructividad:** El motor de TFML y sus herramientas de migración deben operar bajo principios de preservación íntegra de contenidos y datos editoriales.
6. **Inviolabilidad de Tablas Externas en Pruebas de Integración:** Queda terminantemente prohibido ejecutar sentencias SQL de escritura (`INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `TRUNCATE`, `ALTER`, `DROP`) sobre tablas pertenecientes a plugins de terceros (ej. tablas `*_icl_*` de WPML). Las pruebas de integración y scripts de verificación deben apoyarse exclusivamente en APIs públicas y hooks de WordPress Core o de los plugins involucrados. La integridad de las tablas externas debe ser auditada mediante centinelas de solo lectura con comprobación dual determinista (conteo estricto y huella criptográfica/hash de estado antes y después de cada suite).

---

## 4. CRITERIOS EXPLÍCITOS DE FALLO, DETENCIÓN INMEDIATA Y MATRIZ DE CERTIFICACIÓN (ADENDA DT-TFML-042-A)

### 4.1 Condiciones de Detención Inmediata y Fallo (STOP / FAIL)
Cualquier ejecución (piloto o en producción) debe abortar inmediatamente y declararse no conforme si ocurre cualquiera de las siguientes condiciones:
1. **Desviación de Aislamiento:** La conexión activa no apunta a la base de datos expresamente autorizada (ej. intentar escribir en `cms_ect` en lugar de `cms_ect_lab`), o el prefijo de tablas difiere del certificado (`etk_t_`).
2. **Compromiso Criptográfico o de Respaldo:** El respaldo de preflight no existe, su huella SHA-256 no coincide exactamente con el certificado oficial, o se encuentra expuesto dentro de directorios servidos por HTTP.
3. **Violación de Huella o Linaje de Plan:** La huella digital SHA-256 del plan no coincide con la aprobada por la Dirección Técnica, o un plan parcial no demuestra su linaje con el plan matriz.
4. **Violación de Alcance:** Se intenta procesar TRIDs fuera de la lista autorizada, elementos bloqueados por dependencias de idiomas incompletas (ej. francés `fr`), o la cantidad de grupos/elementos excede el límite fijado.
5. **Estado no Listo:** Intento de ejecutar un plan en estado `STATUS_NEEDS_CONFIGURATION` o `STATUS_BLOCKED` sin autorización ni resolución de dependencias.
6. **Mutación de Tablas Ajenas o de Core:** Cualquier alteración en tablas `*_icl_*` de WPML, `*_posts` o `*_terms` fuera de los metadatos y estructuras propias de TF Multilingual.
7. **Fallo de Rollback o Residuos:** En caso de aborto o reversión, si el rollback deja registros huérfanos o residuos en las tablas de TFML, la ejecución se declara fallida.

### 4.2 Matriz Estándar de Resultados de Ejecución
Toda auditoría y reporte técnico debe clasificar los resultados según los siguientes estados taxativos:

| Estado | Significado y Criterio Técnico |
|---|---|
| **PASS** | Todas las verificaciones pre-flight, ejecución, idempotencia, rollback, recuperación y post-flight son 100% exitosas. Cero residuos, cero mutaciones en tablas externas, huellas intactas. |
| **FAIL_RECOVERED** | Se produjo una falla durante la ejecución, pero los mecanismos de rollback transaccional o el manejo de excepciones aislaron el fallo y devolvieron la base de datos a su estado limpio inicial sin pérdida ni corrupción de datos. |
| **FAIL_UNRECOVERED** | Se produjo una falla y los mecanismos de rollback fallaron o dejaron inconsistencias residuales en la base de datos, requiriendo restauración manual del respaldo. |
| **BLOCKED** | La ejecución fue detenida por un gate de gobernanza o preflight antes de emitir cualquier sentencia de escritura en la base de datos. |
| **NOT_EXECUTED** | La fase o lote no fue ejecutado por diseño (ej. dry-run de planificación o retención preventiva). |

