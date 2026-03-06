<?php
namespace local_retentionmanager\task;

defined('MOODLE_INTERNAL') || die();

class cleanup_task extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('cleanuptask', 'local_retentionmanager');
    }

    public function execute() {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/course/lib.php'); 
        require_once($CFG->libdir . '/completionlib.php'); 

        mtrace("Iniciando tarea de limpieza de retención...");

        $fields = $DB->get_records('customfield_field', ['shortname' => 'auto_cleanup_enabled'], 'id DESC');
        
        if (empty($fields)) {
            mtrace("Aviso: No se encontró el campo de curso 'auto_cleanup_enabled'.");
            return;
        }

        $field = reset($fields);

        $sql_courses = "SELECT instanceid AS courseid 
                        FROM {customfield_data} 
                        WHERE fieldid = :fieldid AND intvalue = 1";
        $courses = $DB->get_records_sql($sql_courses, ['fieldid' => $field->id]);

        if (empty($courses)) {
            mtrace("No hay cursos con la limpieza automática habilitada.");
            return;
        }

        // TIEMPO LÍMITE: 10 minutos (600 segundos)
        $time_limit = time() - 600;

        foreach ($courses as $c) {
            $courseid = $c->courseid;
            mtrace("-> Revisando curso ID: {$courseid}");

            $sql_users = "SELECT userid, timecompleted 
                          FROM {course_completions} 
                          WHERE course = :courseid 
                            AND timecompleted > 0 
                            AND timecompleted <= :timelimit";
            
            $expired_users = $DB->get_records_sql($sql_users, [
                'courseid' => $courseid,
                'timelimit' => $time_limit
            ]);

            if (empty($expired_users)) {
                mtrace("   Sin usuarios caducados o pendientes de limpieza.");
                continue;
            }

            $course_obj = $DB->get_record('course', ['id' => $courseid]);
            $completioninfo = new \completion_info($course_obj);
            $modinfo = get_fast_modinfo($course_obj);

            foreach ($expired_users as $user) {
                $userid = $user->userid;
                mtrace("   [!] Limpiando progreso del usuario ID: {$userid}");

                // 1. Calificaciones y Actividades
                if (function_exists('\grade_user_delete_all')) {
                    \grade_user_delete_all($userid, $courseid);
                }

                foreach ($modinfo->cms as $cm) {
                    if ($cm->completion != COMPLETION_TRACKING_NONE) {
                        $completioninfo->update_state($cm, COMPLETION_INCOMPLETE, $userid, true);
                    }
                }
                
                $this->delete_quiz_attempts($userid, $courseid);

                // 2. Desmatriculación y Certificados
                $this->unenrol_user($userid, $courseid);
                $this->delete_certificates($userid, $courseid);

                // 3. EL GOLPE FINAL: Forzar el 'Pendiente' eliminando la finalización en BD PRIMERO
                $DB->delete_records('course_completions', ['userid' => $userid, 'course' => $courseid]);
                $DB->delete_records('course_completion_crit_compl', ['userid' => $userid, 'course' => $courseid]);

                // 4. Purgar Memorias (DEBE IR AL FINAL para no crear cachés fantasma)
                rebuild_course_cache($courseid, true);
                \cache::make('core', 'completion')->purge();
                // Limpiar la caché específica del estado de finalización del curso
                if (\cache::make('core', 'coursecompletion')) {
                    \cache::make('core', 'coursecompletion')->purge();
                }
            }
            
            mtrace("   - Progreso limpiado y estatus general reseteado a Pendiente.");
        }
        
        mtrace("Tarea de limpieza finalizada con éxito.");
    }

    private function delete_quiz_attempts($userid, $courseid) {
        global $DB;
        $quizzes = $DB->get_records('quiz', ['course' => $courseid]);
        if (empty($quizzes)) return;

        foreach ($quizzes as $quiz) {
            $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $userid]);
            if ($attempts) {
                foreach ($attempts as $attempt) {
                    quiz_delete_attempt($attempt, $quiz); 
                }
            }
        }
    }

    private function unenrol_user($userid, $courseid) {
        global $DB;
        $instances = $DB->get_records('enrol', ['courseid' => $courseid]);
        foreach ($instances as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            if ($plugin) {
                $is_enrolled = $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
                if ($is_enrolled) {
                    $plugin->unenrol_user($instance, $userid);
                }
            }
        }
    }

    private function delete_certificates($userid, $courseid) {
        global $DB;
        
        mtrace("   [-] Limpiando certificados...");

        // 1. Course Certificate (tool_certificate_issues)
        if ($DB->get_manager()->table_exists('tool_certificate_issues')) {
            $deleted_tool = $DB->delete_records('tool_certificate_issues', [
                'userid' => $userid, 
                'courseid' => $courseid
            ]);
            if ($deleted_tool) {
                mtrace("     > Certificado de 'coursecertificate' (tool_certificate) eliminado.");
            }
        }

        // 2. Custom Certificate
        if ($DB->get_manager()->table_exists('customcert_issues')) {
            $sql1 = "SELECT ci.id FROM {customcert_issues} ci JOIN {customcert} c ON c.id = ci.customcertid WHERE c.course = :courseid AND ci.userid = :userid";
            $issues1 = $DB->get_records_sql($sql1, ['courseid' => $courseid, 'userid' => $userid]);
            if ($issues1) {
                foreach ($issues1 as $issue) { $DB->delete_records('customcert_issues', ['id' => $issue->id]); }
                mtrace("     > Certificado 'customcert' eliminado.");
            }
        }

        // 3. Simple Certificate
        if ($DB->get_manager()->table_exists('simplecertificate_issues')) {
            $sql2 = "SELECT ci.id FROM {simplecertificate_issues} ci JOIN {simplecertificate} c ON c.id = ci.certificateid WHERE c.course = :courseid AND ci.userid = :userid";
            $issues2 = $DB->get_records_sql($sql2, ['courseid' => $courseid, 'userid' => $userid]);
            if ($issues2) {
                foreach ($issues2 as $issue) { $DB->delete_records('simplecertificate_issues', ['id' => $issue->id]); }
                mtrace("     > Certificado 'simplecertificate' eliminado.");
            }
        }

        // 4. Certificado Antiguo
        if ($DB->get_manager()->table_exists('certificate_issues')) {
            $sql3 = "SELECT ci.id FROM {certificate_issues} ci JOIN {certificate} c ON c.id = ci.certificateid WHERE c.course = :courseid AND ci.userid = :userid";
            $issues3 = $DB->get_records_sql($sql3, ['courseid' => $courseid, 'userid' => $userid]);
            if ($issues3) {
                foreach ($issues3 as $issue) { $DB->delete_records('certificate_issues', ['id' => $issue->id]); }
                mtrace("     > Certificado antiguo eliminado.");
            }
        }
    }
}