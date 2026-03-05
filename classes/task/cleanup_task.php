<?php
namespace local_retentionmanager\task;

defined('MOODLE_INTERNAL') || die();

class cleanup_task extends \core\task\scheduled_task {

    /**
     * Devuelve el nombre de la tarea (visible en el panel de tareas de Moodle)
     */
    public function get_name() {
        return get_string('cleanuptask', 'local_retentionmanager');
    }

    /**
     * La lógica principal que se ejecutará en la madrugada
     */
    public function execute() {
        global $DB, $CFG;

        // Cargar librerías necesarias de Moodle
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        mtrace("Iniciando tarea de limpieza de retención (2 años)...");

        // 1. Buscar el ID del campo personalizado 'auto_cleanup_enabled'
        $field = $DB->get_record('customfield_field', ['shortname' => 'auto_cleanup_enabled'], 'id');
        
        if (!$field) {
            mtrace("Aviso: No se encontró el campo de curso 'auto_cleanup_enabled'. Debes crearlo en la administración. Abortando.");
            return;
        }

        // 2. Obtener todos los cursos que tienen la casilla de limpieza marcada (valor 1)
        $sql_courses = "SELECT instanceid AS courseid 
                        FROM {customfield_data} 
                        WHERE fieldid = :fieldid AND intvalue = 1";
        $courses = $DB->get_records_sql($sql_courses, ['fieldid' => $field->id]);

        if (empty($courses)) {
            mtrace("No hay cursos con la limpieza automática habilitada en este momento.");
            return;
        }

        // Calcular la fecha límite: Hace exactamente 2 años (730 días)
        $two_years_ago = time() - (2 * 365 * 24 * 60 * 60);

        // 3. Recorrer cada curso activado
        foreach ($courses as $c) {
            $courseid = $c->courseid;
            mtrace("-> Revisando curso ID: {$courseid}");

            // Buscar usuarios que terminaron hace más de 2 años en este curso
            $sql_users = "SELECT userid, timecompleted 
                          FROM {course_completions} 
                          WHERE course = :courseid 
                            AND timecompleted > 0 
                            AND timecompleted <= :twoyearsago";
            
            $expired_users = $DB->get_records_sql($sql_users, [
                'courseid' => $courseid,
                'twoyearsago' => $two_years_ago
            ]);

            if (empty($expired_users)) {
                mtrace("   Sin usuarios caducados.");
                continue;
            }

            // 4. Limpiar los datos de cada usuario encontrado
            foreach ($expired_users as $user) {
                $userid = $user->userid;
                mtrace("   [!] Limpiando progreso del usuario ID: {$userid}");

                // A. Borrar TODAS las calificaciones del libro de calificaciones
                grade_user_delete_all($userid, $courseid);
                mtrace("       - Calificaciones borradas.");

                // B. Borrar intentos de cuestionarios (Quizzes)
                $this->delete_quiz_attempts($userid, $courseid);

                // C. Borrar el registro de finalización del curso
                $DB->delete_records('course_completions', ['userid' => $userid, 'course' => $courseid]);
                mtrace("       - Estado de finalización reseteado.");

                // D. Desmatricular al usuario
                $this->unenrol_user($userid, $courseid);
            }
        }
        
        mtrace("Tarea de limpieza finalizada con éxito.");
    }

    /**
     * Función de ayuda: Borra los intentos de cuestionario del usuario
     */
    private function delete_quiz_attempts($userid, $courseid) {
        global $DB;
        $sql = "SELECT qa.id, qa.quiz 
                FROM {quiz_attempts} qa
                JOIN {quiz} q ON q.id = qa.quiz
                WHERE q.course = :courseid AND qa.userid = :userid";
        
        $attempts = $DB->get_records_sql($sql, ['courseid' => $courseid, 'userid' => $userid]);
        if ($attempts) {
            foreach ($attempts as $attempt) {
                // Función nativa de Moodle para borrar intentos limpiamente
                quiz_delete_attempt($attempt, $attempt->quiz); 
            }
            mtrace("       - Intentos de cuestionario eliminados.");
        }
    }

    /**
     * Función de ayuda: Desmatricula al usuario del curso usando la API correcta
     */
    private function unenrol_user($userid, $courseid) {
        global $DB;
        // Buscar todos los métodos de matriculación usados en este curso
        $instances = $DB->get_records('enrol', ['courseid' => $courseid]);
        
        foreach ($instances as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            if ($plugin) {
                // Comprobar si el usuario está matriculado con este método específico
                $is_enrolled = $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
                if ($is_enrolled) {
                    $plugin->unenrol_user($instance, $userid);
                    mtrace("       - Usuario desmatriculado (Método: {$instance->enrol}).");
                }
            }
        }
    }
}