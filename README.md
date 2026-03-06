# Local Retention Manager (Gestor de Retención)

Un plugin local para Moodle diseñado para automatizar la limpieza y el reseteo del progreso de los estudiantes en cursos específicos. Ideal para estrategias de retención, recertificación o cursos recurrentes donde los usuarios necesitan volver a cursar el contenido después de un tiempo determinado.

## 🚀 Descripción General

Este plugin ejecuta una Tarea Programada (`cleanup_task`) que busca cursos que tengan habilitada la limpieza automática. Luego, identifica a los usuarios cuyo tiempo de finalización haya expirado y procede a resetear completamente su historial en ese curso, dejándolo como si acabaran de matricularse por primera vez.

## ✨ Características Principales

* **Filtro por Cursos:** Solo actúa sobre los cursos que tienen el campo personalizado `auto_cleanup_enabled` activo.
* **Reseteo Profundo:** No solo borra la calificación, sino que elimina los intentos de los cuestionarios, desmarca las actividades completadas y borra el estado general del curso.
* **Desmatriculación Automática:** Elimina la matrícula del usuario usando el método de inscripción original.
* **Limpieza Multi-Certificado:** Detecta y elimina automáticamente los certificados emitidos por los plugins más populares de Moodle para evitar que el estudiante conserve el diploma de un curso reseteado.
* **Gestión de Caché Segura:** Purga específicamente las cachés de finalización de curso (`completion` y `coursecompletion`) para evitar "estados fantasma" en la interfaz gráfica.

## 📜 Plugins de Certificados Soportados

El script está preparado para interceptar y borrar las emisiones en las tablas de los siguientes módulos:

1. **Course Certificate** (`tool_certificate_issues` - Típico de Moodle Workplace / Totara).
2. **Custom Certificate** (`mod_customcert` / `customcert_issues`).
3. **Simple Certificate** (`mod_simplecertificate` / `simplecertificate_issues`).
4. **Certificate (Legacy)** (`mod_certificate` / `certificate_issues`).

## ⚙️ Configuración y Requisitos

Para que el script procese un curso, se requiere la existencia de un **Campo personalizado de curso** (Course custom field):

1. Ve a *Administración del sitio > Cursos > Campos personalizados del curso*.
2. Crea un nuevo campo de tipo **Casilla de verificación (Checkbox)** o **Entero (Integer)**.
3. El **Nombre corto (shortname)** del campo DEBE ser exactamente: `auto_cleanup_enabled`.
4. En la configuración del curso que deseas automatizar, marca este campo con el valor `1` (o actívalo).

## 🛠️ Ejecución y Pruebas (CLI)

La limpieza se ejecuta de forma automática según la programación de la tarea en Moodle. Sin embargo, para entornos de pruebas o para forzar la ejecución manual, puedes usar la interfaz de línea de comandos (CLI) de tu servidor.

Ubicado en la raíz de tu instalación de Moodle, ejecuta:

```bash
php admin/cli/scheduled_task.php --execute="\local_retentionmanager\task\cleanup_task"
```


https://gemini.google.com/app/31e15f4b23d881f3?utm_source=app_launcher&utm_medium=owned&utm_campaign=base_all
