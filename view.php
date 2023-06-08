<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The view page where you can start or participate in a session.
 *
 * @package   mod_jazzquiz
 * @author    Sebastian S. Gundersen <sebastsg@stud.ntnu.no>
 * @copyright 2014 University of Wisconsin - Madison
 * @copyright 2018 NTNU
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_jazzquiz;
use Exception;

require_once("../../config.php");
require_once($CFG->dirroot . '/mod/jazzquiz/lib.php');
require_once($CFG->dirroot . '/mod/jazzquiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/editlib.php');

require_login();

/**
 * View the quiz page.
 * @param jazzquiz $jazzquiz
 * @throws \dml_exception
 * @throws \moodle_exception
 */
function jazzquiz_view_start_quiz(jazzquiz $jazzquiz) {
    global $PAGE;
    // Set the quiz view page to the base layout for 1 column layout.
    $PAGE->set_pagelayout('base');

    $session = $jazzquiz->load_open_session();
    if (!$session) {
        jazzquiz_view_default($jazzquiz);
        return;
    }
    $session->load_session_questions();
    $session->load_attempts();
    $session->initialize_attempt();
    if ($jazzquiz->is_instructor()) {
        $session->attempt->data->status = jazzquiz_attempt::PREVIEW;
    } else {
        $session->attempt->data->status = jazzquiz_attempt::INPROGRESS;
    }
    $session->attempt->save();

    // Initialize JavaScript for the question engine.
    // TODO: Not certain if this is needed. Should be checked further.
    \question_engine::initialise_js();

    $renderer = $jazzquiz->renderer;
    $renderer->header($jazzquiz, 'view');
    $renderer->render_quiz($session);
    $renderer->footer();
}

/**
 * View the "Continue session" or "Start session" form.
 * @param jazzquiz $jazzquiz
 * @throws \dml_exception
 * @throws \moodle_exception
 */
function jazzquiz_view_default_instructor(jazzquiz $jazzquiz) {
    global $PAGE, $DB;
    $startsessionform = new forms\view\start_session($PAGE->url, ['jazzquiz' => $jazzquiz]);
    $data = $startsessionform->get_data();
    if ($data) {
        $sessions = $DB->get_records('jazzquiz_sessions', [
            'jazzquizid' => $jazzquiz->data->id,
            'sessionopen' => 1
        ]);
        // Only create session if not already open.
        if (empty($sessions)) {
            $allowguests = isset($data->allowguests) ? 1 : 0;
            $sessionid = $jazzquiz->create_session($data->session_name, $data->anonymity, $allowguests);
            if ($sessionid === false) {
                return;
            }
        } else {
            redirect($PAGE->url, 'A session is already open.', 0);
            // Note: Redirect exits.
        }
        // Redirect to the quiz start.
        $quizstarturl = clone($PAGE->url);
        $quizstarturl->param('action', 'quizstart');
        redirect($quizstarturl, null, 0);
        // Note: Redirect exits.
    }
    $renderer = $jazzquiz->renderer;
    $renderer->header($jazzquiz, 'view');
    if ($jazzquiz->is_session_open()) {
        $renderer->continue_session_form($jazzquiz);
    } else {
        $renderer->start_session_form($startsessionform);
    }
    $renderer->footer();
}

/**
 * View the "Join quiz" or "Quiz not running" form.
 * @param jazzquiz $jazzquiz
 * @throws \moodle_exception
 */
function jazzquiz_view_default_student(jazzquiz $jazzquiz) {
    global $PAGE;
    $studentstartform = new forms\view\student_start_form($PAGE->url);
    $data = $studentstartform->get_data();
    if ($data) {
        $quizstarturl = clone($PAGE->url);
        $quizstarturl->param('action', 'quizstart');
        redirect($quizstarturl, null, 0);
        // Note: Redirect exits.
    }

    /** @var output\renderer $renderer */
    $renderer = $jazzquiz->renderer;
    $renderer->header($jazzquiz, 'view');
    $session = $jazzquiz->load_open_session();
    if ($session) {
        $renderer->join_quiz_form($studentstartform, $session);
    } else {
        $renderer->quiz_not_running($jazzquiz->cm->id);
    }
    $renderer->footer();
}

/**
 * View appropriate form based on role and session state.
 * @param jazzquiz $jazzquiz
 * @throws \dml_exception
 * @throws \moodle_exception
 */
function jazzquiz_view_default(jazzquiz $jazzquiz) {
    if ($jazzquiz->is_instructor()) {
        // Show "Start quiz" form.
        jazzquiz_view_default_instructor($jazzquiz);
    } else {
        // Show "Join quiz" form.
        jazzquiz_view_default_student($jazzquiz);
    }
}


/**
 * Entry point for viewing a quiz.
 */
function jazzquiz_view() {
    global $PAGE;
    $cmid = optional_param('id', false, PARAM_INT);
    if (!$cmid) {
        // Probably a login redirect that doesn't include any ID.
        // Go back to the main Moodle page, because we have no info.
        header('Location: /');
        exit;
    }
    
    $action = optional_param('action', '', PARAM_ALPHANUM);
    $jazzquiz = new jazzquiz($cmid);
    $session = $jazzquiz->load_open_session();
    $isCapable = true;
 
    /*
     * Checks capability if the session doesn't allow for guests.
     * If this if-condition is not present, it will not be possible to 
     * determine whether a guest is allowed to access the session regardless
     * of whether or not the session allows for guests. 
     * 
     * If the session does allow guests, it will not be necessary to perform
     * the require_capability check, but if the session does not allow guests, 
     * the require_capability check will determine whether the user is a guest
     * or not, which will be used to determine whether the user should be granted
     * access or not.
     */
    if (!$session || $session->data->allowguests != 1) {
        try {
            /*
             * Throws exception if capabilities are not satisfied. Meaning
             * that the user has a guest status rather than student status
             * or higher in the role hierarchy. If an exception is not thrown,
             * it indicates that the user has the required role to attend the
             * session.
             */
            require_capability('mod/jazzquiz:attempt', $jazzquiz->context);
        } catch (Exception $e) {
            // Indicates that the guest user is not allowed to access this session.
            $isCapable = false;
        }
    }

    $PAGE->set_pagelayout('incourse');
    $PAGE->set_context($jazzquiz->context);
    $PAGE->set_cm($jazzquiz->cm);
    $modname = get_string('modulename', 'jazzquiz');
    $quizname = format_string($jazzquiz->data->name, true);
    $PAGE->set_title(strip_tags($jazzquiz->course->shortname . ': ' . $modname . ': ' . $quizname));
    $PAGE->set_heading($jazzquiz->course->fullname);
    $url = new \moodle_url('/mod/jazzquiz/view.php');
    $url->param('id', $cmid);
    $url->param('quizid', $jazzquiz->data->id);
    $url->param('action', $action);
    $PAGE->set_url($url);

    if ($isCapable) {
        if ($jazzquiz->is_instructor()) {
            $improviser = new improviser($jazzquiz);
            $improviser->insert_default_improvised_question_definitions();
        }
        if ($action === 'quizstart') {
            jazzquiz_view_start_quiz($jazzquiz);
        } else {
            jazzquiz_view_default($jazzquiz);
        }
    } else {
        /*
         * Shows "guests_not_allowed" if capability is false and
         * session doesn't allow for guests to attend. 
         * 
         * This is triggered when the session does not allow for guests
         * to attend, and the user trying to attend is a guest.
         */

        /** @var output\renderer $renderer */
        $renderer = $jazzquiz->renderer;
        $renderer->header($jazzquiz, 'view');
        $renderer->guests_not_allowed();
        $renderer->footer();
    }
}

jazzquiz_view();
