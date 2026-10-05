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
