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

namespace local_mentorship;

use core_user;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Pairing API: create, end, validate and query mentor/mentee pairings.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pairing {
    /** @var int Pairing is active. */
    const STATUS_ACTIVE = 0;

    /** @var int Pairing was ended by an admin. */
    const STATUS_ENDED = 1;

    /** @var string Table name. */
    const TABLE = 'local_mentorship_pairing';

    /**
     * Check whether a new pairing is allowed.
     *
     * Chains (A mentors B, B mentors C) are allowed. Self, suspended or deleted users,
     * duplicate active and direct reverse active pairs are not.
     *
     * @param int $mentorid
     * @param int $menteeid
     * @return string|null Language string id of the error in local_mentorship, or null when valid.
     */
    public static function validate(int $mentorid, int $menteeid): ?string {
        global $DB;

        if ($mentorid === $menteeid) {
            return 'error:self';
        }
        if (
            $DB->record_exists_select(
                'user',
                '(id = :m OR id = :e) AND (suspended = 1 OR deleted = 1)',
                ['m' => $mentorid, 'e' => $menteeid]
            )
        ) {
            return 'error:suspended';
        }
        if (self::exists_active($mentorid, $menteeid)) {
            return 'error:duplicate';
        }
        if (self::exists_active($menteeid, $mentorid)) {
            return 'error:reverse';
        }
        return null;
    }

    /**
     * Create a pairing.
     *
     * Pairings are open-ended: they start now and have no end date. A mentor is
     * replaced with change_mentor(), which keeps the old pairing as history.
     *
     * @param int $mentorid
     * @param int $menteeid
     * @param bool $notify Send a notification to both users.
     * @return int New pairing id.
     */
    public static function create(int $mentorid, int $menteeid, bool $notify): int {
        global $DB;

        if ($error = self::validate($mentorid, $menteeid)) {
            throw new moodle_exception($error, 'local_mentorship');
        }
        $mentor = core_user::get_user($mentorid, '*', MUST_EXIST);
        $mentee = core_user::get_user($menteeid, '*', MUST_EXIST);

        $now = time();
        $record = (object) [
            'mentorid' => $mentorid,
            'menteeid' => $menteeid,
            'startdate' => $now,
            'enddate' => 0,
            'status' => self::STATUS_ACTIVE,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);

        event\pairing_created::create([
            'objectid' => $record->id,
            'context' => \context_system::instance(),
            'relateduserid' => $menteeid,
            'other' => ['mentorid' => $mentorid],
        ])->trigger();

        if ($notify) {
            self::notify($record->id, $mentor, get_string('notify:mentor', 'local_mentorship', fullname($mentee)));
            self::notify($record->id, $mentee, get_string('notify:mentee', 'local_mentorship', fullname($mentor)));
        }
        return $record->id;
    }

    /**
     * Give a mentee a new mentor: the current pairing ends (kept as history) and a new one starts.
     *
     * Validated before anything changes, and both steps run in one transaction, so a
     * mentee is never left without their mentor because the new pairing was refused.
     *
     * @param int $id Active pairing to change.
     * @param int $newmentorid
     * @param bool $notify Notify the new mentor and the mentee.
     * @return int New pairing id.
     */
    public static function change_mentor(int $id, int $newmentorid, bool $notify): int {
        global $DB;

        $old = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        if (!self::is_active($old)) {
            throw new moodle_exception('error:notactive', 'local_mentorship');
        }
        if ($error = self::validate($newmentorid, (int) $old->menteeid)) {
            throw new moodle_exception($error, 'local_mentorship');
        }

        $transaction = $DB->start_delegated_transaction();
        self::end($id);
        $newid = self::create($newmentorid, (int) $old->menteeid, $notify);
        $transaction->allow_commit();
        return $newid;
    }

    /**
     * End an active pairing. The record is kept for history.
     *
     * @param int $id
     */
    public static function end(int $id): void {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $now = time();
        $record->status = self::STATUS_ENDED;
        if (empty($record->enddate) || $record->enddate > $now) {
            $record->enddate = $now;
        }
        $record->timemodified = $now;
        $DB->update_record(self::TABLE, $record);

        event\pairing_ended::create([
            'objectid' => $record->id,
            'context' => \context_system::instance(),
            'relateduserid' => $record->menteeid,
            'other' => ['mentorid' => $record->mentorid],
        ])->trigger();
    }

    /**
     * Active mentees of a user.
     *
     * @param int $userid Mentor.
     * @return stdClass[] User records (with pairingid, startdate, enddate) keyed by pairing id.
     */
    public static function get_mentees(int $userid): array {
        return self::get_people($userid, 'mentorid', 'menteeid');
    }

    /**
     * Active mentors of a user.
     *
     * @param int $userid Mentee.
     * @return stdClass[] User records (with pairingid, startdate, enddate) keyed by pairing id.
     */
    public static function get_mentors(int $userid): array {
        return self::get_people($userid, 'menteeid', 'mentorid');
    }

    /**
     * Whether a user currently mentors anyone.
     *
     * One indexed query; used on every page by the primary navigation hook, which shows
     * "My mentorship" to mentors only. Pairings with a suspended or deleted user do not count.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_mentees(int $userid): bool {
        global $DB;
        [$where, $params] = self::active_where('p');
        $sql = "SELECT 1
                  FROM {" . self::TABLE . "} p
                  JOIN {user} mu ON mu.id = p.mentorid AND mu.suspended = 0 AND mu.deleted = 0
                  JOIN {user} eu ON eu.id = p.menteeid AND eu.suspended = 0 AND eu.deleted = 0
                 WHERE p.mentorid = :userid AND $where";
        return $DB->record_exists_sql($sql, $params + ['userid' => $userid]);
    }

    /**
     * Whether a pairing is currently active.
     *
     * @param stdClass $pairing
     * @return bool
     */
    public static function is_active(stdClass $pairing): bool {
        return (int) $pairing->status === self::STATUS_ACTIVE
            && (empty($pairing->enddate) || $pairing->enddate > time());
    }

    /**
     * Whether a user may open a pairing page.
     *
     * Access is decided by the pairing record itself, not a role in user context.
     *
     * @param stdClass $pairing
     * @param int $userid
     * @return bool
     */
    public static function can_view(stdClass $pairing, int $userid): bool {
        global $DB;

        if (has_capability('local/mentorship:manage', \context_system::instance(), $userid)) {
            return true;
        }
        $member = in_array($userid, [(int) $pairing->mentorid, (int) $pairing->menteeid], true);
        // Members lose access while either user is suspended, same as the lists hide them.
        $suspended = $DB->record_exists_select(
            'user',
            '(id = :m OR id = :e) AND (suspended = 1 OR deleted = 1)',
            ['m' => $pairing->mentorid, 'e' => $pairing->menteeid]
        );
        return $member && !$suspended && self::is_active($pairing);
    }

    /**
     * Course completion progress for a user, for courses with completion tracking.
     *
     * @param int $userid
     * @return array List of ['name', 'pct', 'iscomplete', 'inprogress', 'notstarted'].
     */
    public static function get_progress(int $userid): array {
        $progress = [];
        foreach (enrol_get_users_courses($userid, true, 'enablecompletion') as $course) {
            $pct = \core_completion\progress::get_course_progress_percentage($course, $userid);
            if ($pct === null) {
                continue;
            }
            $pct = (int) round($pct);
            $coursecontext = \context_course::instance($course->id);
            $progress[] = [
                'name' => format_string($course->fullname, true, ['context' => $coursecontext, 'escape' => false]),
                'pct' => $pct,
                'iscomplete' => $pct >= 100,
                'inprogress' => $pct > 0 && $pct < 100,
                'notstarted' => $pct === 0,
            ];
        }
        return $progress;
    }

    /**
     * Average completion across tracked courses.
     *
     * @param int $userid
     * @return int|null Null when no course tracks completion.
     */
    public static function get_overall_progress(int $userid): ?int {
        $progress = self::get_progress($userid);
        if (!$progress) {
            return null;
        }
        return (int) round(array_sum(array_column($progress, 'pct')) / count($progress));
    }

    /**
     * Remove every pairing that involves a user.
     *
     * @param int $userid
     */
    public static function delete_for_user(int $userid): void {
        global $DB;
        $DB->delete_records_select(self::TABLE, 'mentorid = :a OR menteeid = :b', ['a' => $userid, 'b' => $userid]);
    }

    /**
     * Whether an active pairing exists in this direction.
     *
     * @param int $mentorid
     * @param int $menteeid
     * @return bool
     */
    private static function exists_active(int $mentorid, int $menteeid): bool {
        global $DB;
        [$where, $params] = self::active_where();
        $params += ['mentorid' => $mentorid, 'menteeid' => $menteeid];
        return $DB->record_exists_select(self::TABLE, "mentorid = :mentorid AND menteeid = :menteeid AND $where", $params);
    }

    /**
     * SQL condition for active pairings.
     *
     * @param string $alias Table alias, empty for none.
     * @return array [sql, params]
     */
    private static function active_where(string $alias = ''): array {
        $p = $alias ? "$alias." : '';
        return [
            "{$p}status = :activestatus AND ({$p}enddate = 0 OR {$p}enddate > :now)",
            ['activestatus' => self::STATUS_ACTIVE, 'now' => time()],
        ];
    }

    /**
     * Load the users on the other side of a user's active pairings.
     *
     * @param int $userid
     * @param string $selffield Column holding $userid.
     * @param string $otherfield Column holding the user to load.
     * @return stdClass[]
     */
    private static function get_people(int $userid, string $selffield, string $otherfield): array {
        global $DB;

        [$where, $params] = self::active_where('p');
        $userfields = \core_user\fields::for_userpic()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT p.id AS pairingid, p.startdate, p.enddate, $userfields
                  FROM {" . self::TABLE . "} p
                  JOIN {user} u ON u.id = p.$otherfield
                 WHERE p.$selffield = :userid AND u.deleted = 0 AND u.suspended = 0 AND $where
              ORDER BY u.lastname, u.firstname";
        return $DB->get_records_sql($sql, $params + ['userid' => $userid]);
    }

    /**
     * Send a pairing notification.
     *
     * @param int $pairingid
     * @param stdClass $to
     * @param string $text
     */
    private static function notify(int $pairingid, stdClass $to, string $text): void {
        $url = new moodle_url('/local/mentorship/view.php', ['id' => $pairingid]);

        $message = new \core\message\message();
        $message->component = 'local_mentorship';
        $message->name = 'pairingcreated';
        $message->userfrom = core_user::get_noreply_user();
        $message->userto = $to;
        $message->subject = get_string('notify:subject', 'local_mentorship');
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $text;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('viewpairing', 'local_mentorship');
        message_send($message);
    }
}
