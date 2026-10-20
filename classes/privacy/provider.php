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

namespace local_mentorship\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_mentorship\pairing;

/**
 * Privacy provider for local_mentorship. Pairings are stored at system context.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani, Rutgers DCF-OTPD
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(pairing::TABLE, [
            'mentorid' => 'privacy:metadata:pairing:mentorid',
            'menteeid' => 'privacy:metadata:pairing:menteeid',
            'startdate' => 'privacy:metadata:pairing:startdate',
            'enddate' => 'privacy:metadata:pairing:enddate',
            'status' => 'privacy:metadata:pairing:status',
            'timecreated' => 'privacy:metadata:pairing:timecreated',
        ], 'privacy:metadata:pairing');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        return $collection;
    }

    /**
     * Contexts holding data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :level
                   AND EXISTS (SELECT 1 FROM {" . pairing::TABLE . "} p WHERE p.mentorid = :a OR p.menteeid = :b)";
        $contextlist->add_from_sql($sql, ['level' => CONTEXT_SYSTEM, 'a' => $userid, 'b' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('mentorid', "SELECT mentorid FROM {" . pairing::TABLE . "}", []);
        $userlist->add_from_sql('menteeid', "SELECT menteeid FROM {" . pairing::TABLE . "}", []);
    }

    /**
     * Export a user's pairings.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $records = $DB->get_records_select(pairing::TABLE, 'mentorid = :a OR menteeid = :b', ['a' => $userid, 'b' => $userid]);
        if (!$records) {
            return;
        }
        $data = [];
        foreach ($records as $r) {
            $data[] = [
                'role' => get_string((int) $r->mentorid === $userid ? 'mentor' : 'mentee', 'local_mentorship'),
                'otheruserid' => (int) $r->mentorid === $userid ? $r->menteeid : $r->mentorid,
                'startdate' => transform::datetime($r->startdate),
                'enddate' => $r->enddate ? transform::datetime($r->enddate) : '-',
                'active' => transform::yesno(pairing::is_active($r)),
                'timecreated' => transform::datetime($r->timecreated),
            ];
        }
        writer::with_context($context)->export_data(
            [get_string('pluginname', 'local_mentorship')],
            (object) ['pairings' => $data]
        );
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records(pairing::TABLE);
        }
    }

    /**
     * Delete data for one user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            pairing::delete_for_user((int) $contextlist->get_user()->id);
        }
    }

    /**
     * Delete data for several users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            pairing::delete_for_user((int) $userid);
        }
    }
}
