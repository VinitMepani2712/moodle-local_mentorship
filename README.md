# Mentorship (local_mentorship)

Pair mentors with mentees in Moodle and give mentors a clear view of each mentee's training.

A user can be a mentor and a mentee at the same time (Maria mentors Jordan while Jordan mentors
Taylor). No new role is created: the relationship is stored by the plugin and decides who can see what.

## Features

- **Admin assignment**: one mentor and any number of mentees in one step. Self-pairing, duplicate
  and reverse pairs, and suspended or deleted users are refused with a clear reason.
- **Change mentor**: hand a mentee to a new mentor. The old pairing stays in the history.
- **My mentorship**: mentors see their mentees in a searchable, sortable list with progress rings,
  and a summary of the selected mentee: overall progress, course status, overdue activities and
  recent completions.
- **Training report**: per-mentee report with summary cards, filters (status, overdue, search,
  sort, date ranges), collapsible courses with activities, grades and pass/fail, and CSV export.
- **Notifications** to both users when a pairing is created (popup and email, user-configurable).
- **Navigation**: "My mentorship" in the top menu for mentors (Moodle 4.3 and later) and on the
  user's own profile page (all supported versions).
- **Privacy API** support, event logging, and cleanup when a user is deleted.

## Requirements

- Moodle 4.1 to 5.3.
- Course completion tracking enabled in courses, for progress figures.

## Installation

1. Install this plugin first: Site administration > Plugins > Install plugins, or copy the folder to
   `local/mentorship` (`public/local/mentorship` on Moodle 5.1 and later).
2. Visit Site administration > Notifications to complete the installation.
3. Optionally install the companion dashboard block, `block_mentorship`.

## Configuration

- Capability `local/mentorship:manage` (given to Managers by default) allows assigning pairings.
- Manage pairings at Site administration > Plugins > Local plugins > Mentorship pairings.

## Report rules

The training report counts courses enrolled in since 1 July 2021, ignores courses named as
cancelled, and treats a normalised grade of 70% or more as passed. These match the DCF-LMS grade
report the plugin was built from; adjust `report::COURSE_START_DATE` and `report::PASS_SCORE` in
`classes/report.php` if your site needs different values.

## Privacy

The plugin stores mentor and mentee user IDs, start and end dates and status for each pairing. It
implements the Privacy API for export and deletion. It makes no requests to external servers: the
fonts it uses are bundled (see `thirdpartylibs.xml`).

## Development

JavaScript sources are in `amd/src`. Rebuild `amd/build` with Moodle's tooling before a release:
`npx grunt amd --root=local/mentorship` from the Moodle root.

## Support

- Source code: https://github.com/YOUR-GITHUB-USER/moodle-local_mentorship
- Issue tracker: https://github.com/YOUR-GITHUB-USER/moodle-local_mentorship/issues
- Documentation: https://github.com/YOUR-GITHUB-USER/moodle-local_mentorship#readme

## License

GNU GPL v3 or later; see `LICENSE`. Bundled fonts: SIL Open Font License 1.1.
