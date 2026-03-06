<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Función que se ejecuta automáticamente al instalar el plugin.
 */
function xmldb_local_retentionmanager_install() {
    global $DB;

    // 1. Crear una categoría para el campo personalizado (si no existe)
    $categoryname = 'Mantenimiento Automatizado';
    $category = $DB->get_record('customfield_category', ['name' => $categoryname]);
    
    if (!$category) {
        $category = new stdClass();
        $category->name = $categoryname;
        $category->description = 'Configuraciones del plugin Gestor de Retención';
        $category->descriptionformat = FORMAT_HTML;
        $category->sortorder = 99;
        $category->contextid = context_system::instance()->id;
        $category->timecreated = time();
        $category->timemodified = time();
        
        $category->id = $DB->insert_record('customfield_category', $category);
    }

    // 2. Crear el campo de la casilla (checkbox) si no existe
    $fieldname = 'auto_cleanup_enabled';
    $field = $DB->get_record('customfield_field', ['shortname' => $fieldname]);
    
    if (!$field) {
        $field = new stdClass();
        $field->shortname = $fieldname;
        $field->name = 'Activar limpieza automática de progreso';
        $field->type = 'checkbox';
        $field->description = 'Borra calificaciones, certificados y desmatricula a los alumnos antiguos.';
        $field->descriptionformat = FORMAT_HTML;
        $field->sortorder = 1;
        $field->categoryid = $category->id;
        
        // Configuración de seguridad: Bloqueado por defecto (locked = 1)
        // Solo admins o roles con el permiso podrán cambiarlo
        $configdata = [
            'checkbydefault' => 0, 
            'locked' => 1, 
            'visibility' => 2 // 2 = Visible para todos
        ];
        $field->configdata = json_encode($configdata);
        
        $field->timecreated = time();
        $field->timemodified = time();
        
        $DB->insert_record('customfield_field', $field);
    }
}