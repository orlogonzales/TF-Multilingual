# CHANGELOG â€” TF MULTILINGUAL

Todos los cambios notables de este proyecto serÃ¡n documentados en este archivo.
El formato se basa en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/) y este proyecto se adhiere a [Semantic Versioning](https://semver.org/lang/es/).

---

## [0.1.0-dev] - 2026-10-05

### AÃ±adido
- **Arquitectura Oficial:** IncorporaciÃ³n del documento tÃ©cnico aprobado `docs/ARQUITECTURA-TF-MULTILINGUAL.md` (VersiÃ³n 1.0.0).
- **Gobernanza y DecisiÃ³n:** Documento de gobernanza `docs/GOBERNANZA.md` y registro consolidado de decisiones `docs/DECISIONES-ARQUITECTONICAS.md` (ADR-001 al ADR-017).
- **PlanificaciÃ³n:** DefiniciÃ³n del `docs/ROADMAP.md` para las etapas Core/MVP y Post-Core.
- **Estructura Base del Plugin:** CreaciÃ³n del archivo principal `tf-multilingual.php` y guard de desinstalaciÃ³n `uninstall.php`.
- **NÃºcleo de Bootstrap:** Clase inicial `TF\Multilingual\Core\Plugin` con verificaciÃ³n de entorno y prevenciÃ³n de acceso directo.
- **Tooling de Calidad:** ConfiguraciÃ³n de `composer.json` con autoloading PSR-4, configuraciÃ³n de PHPCS (`phpcs.xml.dist`) bajo WordPress Coding Standards (WPCS) y suite de pruebas inicial `phpunit.xml.dist`.
- **Pruebas Unitarias:** CreaciÃ³n del primer test unitario de bootstrap en `tests/Unit/PluginTest.php`.

### Nota de Estado
- Esta versiÃ³n corresponde al micro-baseline de inicializaciÃ³n y tooling. El motor funcional multilingÃ¼e, la persistencia en base de datos y la gestiÃ³n de traducciones se implementarÃ¡n en las fases subsiguientes segÃºn el roadmap aprobado.
