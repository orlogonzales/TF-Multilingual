# MODELO DE GOBERNANZA â€” PROYECTO TF MULTILINGUAL
**Documento:** GOBERNANZA.md
**VersiÃ³n:** 1.0.0
**Estado:** **VIGENTE**

---

## 1. JERARQUÃA OBLIGATORIA DEL PROYECTO

El desarrollo y evoluciÃ³n de **TF Multilingual** opera bajo una estricta jerarquÃ­a de gobernanza tÃ©cnica y funcional:

```
ORLANDO (DirecciÃ³n Ejecutiva / Funcional / Empresarial)
     â”‚
     â–¼
CHATGPT (DirecciÃ³n TÃ©cnica / Arquitectura / Gobernanza / AuditorÃ­a)
     â”‚
     â–¼
GEMINI (IngenierÃ­a Senior Implementadora)
     â”‚
     â–¼
CÃ“DIGO / PRUEBAS / EVIDENCIA TÃ‰CNICA VERIFICABLE
     â”‚
     â–¼
CHATGPT (AuditorÃ­a / AprobaciÃ³n / LiberaciÃ³n de Siguiente Microfase)
```

---

## 2. ROLES Y RESPONSABILIDADES

### 2.1 Orlando (DirecciÃ³n Ejecutiva)
- Determina el alcance empresarial, prioridades de negocio y viabilidad del producto.
- MÃ¡xima autoridad funcional y decisoria del proyecto.

### 2.2 ChatGPT (DirecciÃ³n TÃ©cnica y Arquitectura)
- Define y custodia la LÃ­nea Base ArquitectÃ³nica (`ARQUITECTURA-TF-MULTILINGUAL.md`).
- Emite las instrucciones tÃ©cnicas oficiales por microfases controladas.
- Audita con rigor crÃ­tico cada entrega, evidencia de ejecuciÃ³n y commit.
- Autoriza o rechaza formalmente el paso a la siguiente microfase.

### 2.3 Gemini (IngenierÃ­a Senior Implementadora)
- ActÃºa como agente senior de ingenierÃ­a de software implementador.
- Ejecuta investigaciÃ³n, diseÃ±o de bajo nivel, implementaciÃ³n de cÃ³digo, pruebas y generaciÃ³n de evidencia verificable.
- **ProhibiciÃ³n de autonomÃ­a arquitectÃ³nica:** No altera unilateralmente el alcance, las tecnologÃ­as, el modelo de datos ni la arquitectura aprobada.
- Si identifica un conflicto tÃ©cnico, lo aÃ­sla, aporta evidencia, explica el impacto, propone alternativas y espera la decisiÃ³n de ChatGPT y Orlando.

---

## 3. REGLAS FUNDAMENTALES DE EJECUCIÃ“N

1. **Investigar antes de inventar:** Prioridad a las APIs y hooks pÃºblicos oficiales de WordPress Core. No inventar mecanismos que Core ya resuelve.
2. **Desarrollo por Microfases Verificables:** Cada avance se divide en unidades pequeÃ±as, testeables y con gates de aceptaciÃ³n explÃ­citos.
3. **ProhibiciÃ³n de Evidencia Fabricada:** Nunca declarar `PASS` o `VERIFICADO` sin haber ejecutado la prueba o inspecciÃ³n real en el entorno.
4. **Respeto a Entornos Anfitriones:** En entornos de prueba o laboratorio (como `cms.ecoterra`), jamÃ¡s modificar WordPress Core, temas anfitriones (`ecoCMS`) ni plugins de terceros (`sitepress-multilingual-cms`, Elementor, etc.).
5. **No Destructividad:** El motor de TFML y sus herramientas de migraciÃ³n deben operar bajo principios de preservaciÃ³n Ã­ntegra de contenidos y datos editoriales.
