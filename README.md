# TF Multilingual

**TF Multilingual** es un plugin multilingÃ¼e profesional, nativo e independiente para WordPress.

## FilosofÃ­a del Producto
- **WordPress sigue siendo WordPress:** Cada traducciÃ³n editorial es un objeto real de WordPress (`wp_posts` o `wp_terms`).
- **Simplicidad de uso:** Instalar â†’ Elegir idiomas â†’ Traducir.
- **Aislamiento y Rendimiento:** Tablas dedicadas para relaciones lingÃ¼Ã­sticas sin sobrecargar `wp_postmeta`.
- **No destructivo:** Desactivar o desinstalar el plugin nunca elimina el contenido editorial ni los archivos fÃ­sicos de WordPress.

## Estado del Proyecto
Este proyecto se encuentra actualmente en **Fase 1.0 (InicializaciÃ³n y Primer Micro-Baseline)**.
Las capacidades multilingÃ¼es, la persistencia relacional en base de datos y la interfaz de usuario se implementarÃ¡n progresivamente en las microfases sucesivas.

> **Aviso de Compatibilidad:** Las integraciones con constructores visuales, plugins de SEO y adaptadores de migraciÃ³n externa estÃ¡n contempladas en la arquitectura y serÃ¡n certificadas formalmente en sus fases correspondientes.

## Requisitos del Sistema
- **WordPress:** 6.8 o superior.
- **PHP:** 8.1 o superior (Entorno principal de desarrollo y pruebas: PHP 8.3).
- **Base de Datos:** MySQL 5.7+ / MariaDB 10.4+ (Motor InnoDB, cotejamiento `utf8mb4`).

## DocumentaciÃ³n Oficial
La documentaciÃ³n tÃ©cnica aprobada se encuentra en el directorio `/docs`:
- [Arquitectura TÃ©cnica Oficial v1.0](docs/ARQUITECTURA-TF-MULTILINGUAL.md)
- [Modelo de Gobernanza TÃ©cnica](docs/GOBERNANZA.md)
- [Registro de Decisiones ArquitectÃ³nicas (ADR)](docs/DECISIONES-ARQUITECTONICAS.md)
- [Roadmap del Proyecto](docs/ROADMAP.md)
- [Changelog](docs/CHANGELOG.md)

## Entorno de Desarrollo y Calidad
El proyecto utiliza Composer para la gestiÃ³n de autoloading PSR-4 y herramientas de desarrollo:

```bash
# Instalar dependencias de desarrollo
composer install

# Ejecutar anÃ¡lisis de estÃ¡ndares de cÃ³digo (WPCS)
composer run lint

# Ejecutar suite de pruebas unitarias
composer run test
```

## Estructura del CÃ³digo
```text
tf-multilingual/
â”œâ”€â”€ docs/           # DocumentaciÃ³n tÃ©cnica oficial y gobernanza
â”œâ”€â”€ src/            # CÃ³digo fuente (PSR-4: TF\Multilingual\)
â”‚   â”œâ”€â”€ Core/       # NÃºcleo y ciclo de vida del plugin
â”‚   â””â”€â”€ ...         # MÃ³dulos del dominio (segÃºn roadmap)
â”œâ”€â”€ tests/          # Suite de pruebas (Unit / Integration)
â”œâ”€â”€ tf-multilingual.php # Archivo principal del plugin WordPress
â””â”€â”€ uninstall.php   # Script de desinstalaciÃ³n controlada
```
