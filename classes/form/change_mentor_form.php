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
 * Admin form to give a mentee a new mentor.
 *
 * Custom data: 'pairing' (the active pairing record).
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class change_mentor_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'change', $this->_customdata['pairing']->id);
        $mform->setType('change', PARAM_INT);

        $mform->addElement('autocomplete', 'mentorid', get_string('newmentor', 'local_mentorship'), [],
            pairing_form::user_selector_options(false));
        $mform->setType('mentorid', PARAM_INT);
        $mform->addRule('mentorid', null, 'required', null, 'client');

        $mform->addElement('advcheckbox', 'notify', get_string('notify', 'local_mentorship'));
        $mform->setDefault('notify', 1);

        $this->add_action_buttons(true, get_string('changementor', 'local_mentorship'));
    }

    /**
     * Same rules as a new pairing, checked against this mentee.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['mentorid'])) {
            $menteeid = (int) $this->_customdata['pairing']->menteeid;
            if ($error = pairing::validate((int) $data['mentorid'], $menteeid)) {
                $errors['mentorid'] = get_string($error, 'local_mentorship');
            }
        }
        return $errors;
    }
}
