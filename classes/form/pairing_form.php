<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_mentorship\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_mentorship\pairing;

/**
 * Admin form to assign one mentor to one or more mentees.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pairing_form extends \moodleform {
    /**
     * Core user search options (same selector core uses in Report builder audiences).
     *
     * @param bool $multiple
     * @return array
     */
    public static function user_selector_options(bool $multiple): array {
        return [
            'ajax' => 'core_user/form_user_selector',
            'multiple' => $multiple,
            'valuehtmlcallback' => function ($userid) {
                $user = \core_user::get_user($userid);
                return $user ? fullname($user) : '';
            },
        ];
    }

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'autocomplete',
            'mentorid',
            get_string('mentor', 'local_mentorship'),
            [],
            self::user_selector_options(false)
        );
        $mform->setType('mentorid', PARAM_INT);
        $mform->addRule('mentorid', null, 'required', null, 'client');

        // Several mentees can be paired with the same mentor in one go.
        $mform->addElement(
            'autocomplete',
            'menteeids',
            get_string('mentees', 'local_mentorship'),
            [],
            self::user_selector_options(true)
        );
        $mform->setType('menteeids', PARAM_INT);
        $mform->addRule('menteeids', null, 'required', null, 'client');
        $mform->addHelpButton('menteeids', 'menteeids', 'local_mentorship');

        $mform->addElement('advcheckbox', 'notify', get_string('notify', 'local_mentorship'));
        $mform->setDefault('notify', 1);

        $this->add_action_buttons(false, get_string('createpairing', 'local_mentorship'));
    }

    /**
     * Check every mentee against the pairing rules; the whole submission is refused if any fails.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $mentorid = (int) ($data['mentorid'] ?? 0);
        $problems = [];
        foreach ((array) ($data['menteeids'] ?? []) as $menteeid) {
            if ($mentorid && ($error = pairing::validate($mentorid, (int) $menteeid))) {
                $mentee = \core_user::get_user((int) $menteeid);
                $problems[] = ($mentee ? fullname($mentee) . ': ' : '') . get_string($error, 'local_mentorship');
            }
        }
        if ($problems) {
            $errors['menteeids'] = implode(' ', $problems);
        }
        return $errors;
    }
}
