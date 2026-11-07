# Forms: offices build their own forms, filled by signed-in officials

Date: 2026-11-07. The first round of forms: **internal forms**, filled only by
people signed in to the tracker. Public forms come in a later round, on a
deployment of their own (below). The owner's words:

- "make this new mis system super capable and user friendly for the
  government officials so they can make their own form easily and host"
- "the form might require email otp (and phone otp) to fill in, or not. the
  form can be multi step or single step. the form can have images, text,
  interactive content, video, etc. the form can have text inoput, select,
  searchable select, radio, checkbox, paragraphs, and more all with
  necessary validation options. such each form will have a unique url so
  that we can share it to the users. each form can also have an optional key
  requirement for eg. with the online exam."
- "what if we integrate this with tasks.sdoc.gov.np ... each office will use
  it, so they can now create forms on the fly there and share? it will pair
  perfectly with onlyoffice's word, excel and powerpoint we have."
- "i will test with internal forms first, then we go public forms too. two
  factor sign in requirement for form looks fine. submissions should not be
  tasks automatically until we make submission in that form a task which
  gets auto assigned to someone in a project. Phone OTP is not anywhere so
  we'll just keep that plan for future. exam mode is nice but for future."

Everything else below is the builder's call, marked "(call)" where it is a
choice the owner may want to check.

## For the owner to check first

The calls with the most weight, in one place:

1. **Every form lives in a project** (no personal forms this round).
   Responses are the office's records, so they stay with the project's
   organization when the person who made the form leaves or moves. Someone
   with no project uses their organization's General project.
2. **The project's team does not see responses** just by being on the team.
   Responses are seen by whoever runs the project, the form's maker, and the
   people they share responses with. This differs from project documents,
   which the whole team edits.
3. **A form may be sent to other organizations** (a data call, such as OPMCM
   asking every ministry), but only by its organization's admins or a
   super-admin. This is the first thing that crosses organizations without a
   cross-organization team, so it is kept narrow: see "Other organizations".
4. **A data call can take one response per organization** instead of one
   per person.
5. **Nothing is deleted this round**: responses, drafts' last saves and
   files are kept. Deletion rules come with public forms.

## Why

Offices collect information all the time: a progress report from each
ministry, a training registration, a staff survey, an IT request. Today that
is a letter with an Excel file attached, emailed back and copied by hand, or
a Google Form whose answers live abroad. The tracker already knows every
office, every official and their designation, and keeps documents in
ONLYOFFICE. Forms built here need no new accounts. They know who is filling
them in, and their answers go straight into the spreadsheets and tasks
offices already use.

## What people get

1. **A Forms page** (`/workspace/forms`), in the sidebar, with three lists:
   - **To fill**: open forms meant for the viewer that they haven't
     submitted, nearest deadline first.
   - **Filled**: what they have submitted, to read again or edit while the
     form allows it.
   - **Managing**: forms they can edit or whose responses they can see.
2. **A Forms tab on every project**, beside Documents, with that project's
   forms.
3. **A builder**: steps, content and questions, validation, conditions,
   languages, templates and a preview on phone and desktop.
4. **A link for every form**, `https://tasks.sdoc.gov.np/f/{code}`, to paste
   into a letter, a chat or a notice, plus a QR code (call). It asks people
   to sign in first.
5. **Responses**: a table, stages, notes, files, a summary, a spreadsheet in
   Documents, and who hasn't answered yet, with a reminder button.
6. **My work** shows forms waiting to be filled, with their deadlines
   (call), so a data call doesn't depend on someone keeping the link.
7. **Optionally, a task from each response**, given to a chosen person in a
   chosen project. Off unless the form turns it on.

## Where a form lives

Every form belongs to **one project** and so to that project's
organization (owner's check 1). It never moves between projects in this
round. Archiving the project closes its open forms (call); their responses
stay readable to whoever could see them.

## Who may do what

One home for every rule, in `WorkspaceAccess` (a new Forms section), and one
answer per person and form: their **access level**, `edit`, `responses` or
none, the highest of:

1. **Runs the project**: `edit` for whoever passes `canManageProjectAccess`
   on the form's project (its organization's admins, its program's manager,
   its manager), as they do for its tasks.
2. **Made it**: `edit` for the form's maker while they may still use the
   project (`canUseProjectDocuments`, the same "on the team" rule as
   documents).
3. **Shared**: an effective share's level (`edit` or `responses`), to an
   active person of the project's organization. Effective means the same as
   for documents: stored, but counting only while that person is active and
   in that organization.

Super-admins get `edit`, as on every project.

What each level allows:

- **Can edit** (`edit`): change the form, publish, close and reopen it,
  choose who fills it in, share it, turn tasks on and off, and everything
  **Can see responses** allows.
- **Can see responses** (`responses`): read responses, move them between
  stages, write notes, download files, export, see who hasn't answered and
  send reminders.

Team members with no level see the form in the project's Forms tab (title,
status, deadline), fill it in when they are among its respondents, and
nothing more (owner's check 2).

**Who may make a form**: anyone who may use the project's documents
(`canUseProjectDocuments`). They become its maker (call: the same people
who start documents there).

**Sharing stays inside the project's organization**, with the documents'
share picker and its one generic 422 for anyone else. A form that is shared
with nobody and has no maker on the team any more is still run by whoever
runs the project.

## Who fills it in

Chosen by whoever may edit, before or after publishing:

- **Everyone in {organization}** (default): every active person of the
  project's organization.
- **The project's team**.
- **Chosen people** of the project's organization.
- **Other organizations**, added to any of the above (see below).

Every respondent must be signed in and active (`canSignIn`). Someone who
isn't among the respondents, opening the link, sees only "This form isn't
open to you", with no title and no organization (call). The same rule
answers the API.

**Answering as** (call, a form setting):

- **Each person** (default): one response per person.
- **One per organization**, for data calls: each organization among the
  respondents sends one response. Any active person of that organization
  may start it, and anyone else there may open it and change it until the
  deadline. Every save records who made it (owner's check 4). The
  organization has one draft. If two people change it at once, the later
  save wins, and the page says who saved last and when (call).

### Other organizations

- Only the form's organization's admins and super-admins may add other
  organizations (call, owner's check 3), the way only super-admins open a
  project's team to other organizations.
- People there see the form, the name of the organization running it, and
  their own (or their organization's) response. Nothing else of the form's
  organization: not its projects, people or other responses.
- The form's side sees each response with the name, designation and
  organization of whoever sent it, as for any response.
- In "who hasn't answered", another organization's people are counted by
  organization ("Ministry of Health: 3 of 12"), never named (call). In
  "one per organization" mode only the organizations are listed anyway.

## Filling it in

- **The top of every form** says who runs it: the organization, the project
  and the maker.
- **Who they are is not asked**. The form shows the respondent's name,
  designation and organization, and the response keeps them. A form never
  needs questions for them.
- **Drafts save themselves** on the server as people type (call: a few
  seconds after each change), so closing the page, losing the connection or
  switching devices loses nothing. Only an explicit **Clear my draft**
  throws a draft away. The form's side never sees drafts.
- **Steps**: one or more, with progress shown. Each step is checked before
  the next opens. A form with more than one step ends on a review page
  before Submit (call).
- **After Submit**: a receipt with the response's reference (call: like
  sdoc's references, from an alphabet without look-alike characters), and
  the response under Filled.
- **Editing after Submit**: allowed until the deadline unless the form
  turns it off (call: on by default). Each edit is kept in the response's
  history.
- **After the deadline**, or once the form is closed: read-only for
  everyone, the form's side included.
- **Phones**: every form works at phone width, one column.

## The builder

- **Content blocks**:
  - a heading;
  - text, with bold, italic, lists and links;
  - an image (uploaded);
  - a video, as a YouTube or Vimeo link shown in the page (call: no video
    uploads, since the VM shouldn't stream);
  - a file to download, such as a PDF of a circular;
  - a divider.
- **Questions**:
  - short text, paragraph, number, email, phone, link;
  - a date: the form chooses BS or AD, and each answer is stored as the AD
    date with its BS twin;
  - single choice (radio, or a dropdown, searchable when it is long), and
    several choices (checkboxes);
  - yes/no, and a scale (1 to 5, or 1 to 10) with labels at its ends;
  - a file upload;
  - **a place**: province, then district, then local level, then ward, each
    narrowing the next, from a built-in list (see Data);
  - **a group that repeats** ("Add another"), for degrees, trainings, or
    the items of a report;
  - **a declaration**: a box to tick, with the text it confirms.
- **Validation**, chosen from presets, never typed as a pattern unless the
  person opens Advanced:
  - required or not;
  - text: minimum and maximum length; Nepali (Devanagari) only, English
    letters only, digits only, a Nepali mobile number, email, link; a custom
    pattern under Advanced;
  - numbers: minimum, maximum, whole numbers only;
  - dates: earliest and latest, or **an age between X and Y on a date**
    (for a date of birth);
  - several choices: at least and at most;
  - files: kinds (PDF, image, Word, Excel), size (call: up to 20 MB each),
    how many;
  - a group that repeats: at least and at most.
- **Conditions**: a question, content block or step can be **shown only
  when** earlier answers match: equals, isn't, includes, is answered, or
  isn't answered. One condition, or several joined by "all of" or "any
  of". No formulas. A hidden question is never required, and an answer
  left in a hidden question is dropped when the response is saved, so a
  response never holds an answer to a question it didn't show (call).
- **Languages**: Nepali, English, or both (the respondent switches). With
  both, every label, option and message needs both before publishing.
- **Templates** to start from (call): blank; a data call (one per
  organization); a staff survey; an event or training registration; a
  request (with tasks on). Each is an ordinary form made from a copy.
  **Duplicate** copies any form the person may edit into a project where
  they may make one.
- **Preview** on phone and desktop. **Try it** sends a test response that is
  marked as a test, makes no task, and is never counted or exported.
- **Before publishing**, the builder points at problems:
  - a step with no question;
  - a choice question with fewer than two options;
  - a missing translation;
  - no deadline (allowed, with a warning, call);
  - tasks turned on with nobody who can be assigned.

### Versions

- Editing a published form changes only its draft. **Publish changes** puts
  the new version live. A version, once published, never changes.
- Every response keeps the version it was filled in on, and is shown with
  it.
- Every question and option has an id that never changes and is never used
  again, even if its label changes or it is deleted. An answer stores ids,
  not labels, so renaming an option keeps every answer right.
- A question removed in a later version keeps its answers, shown under
  "Earlier questions" on the responses that have them.

## Opening and closing

- A form is **Draft** (only the form's side sees it), **Open** or
  **Closed**.
- **Opens** and **Closes** dates and times, in office time
  (`resources/js/lib/dates.ts`'s rule). Without a close date it stays open
  until someone closes it.
- **A response limit** (call): closes the form after N responses, such as
  the seats of a training.
- **Publishing can notify the respondents** (a box, ticked by default for
  chosen people and data calls, unticked for a whole organization, call).
  This sends a new in-app notification, `FormOpened`, plus the right-away
  email (one more entry in `SendEmailUpdates::KINDS` and `item`).
- **Remind**, from "who hasn't answered", sends `FormReminder` (also
  emailed) to those who haven't. In "one per organization" mode it goes to
  that organization's admins (call). Not more than once a day per person and
  form (call).
- Nobody is notified about their own action, and someone who can't open the
  form is never notified about it.

## Responses

- **The table**: one row per response with its reference, who sent it, when,
  its stage, and the questions chosen as columns. Search, filters (stage,
  choice answers, dates, organization) and sort, all in the URL like the
  reports. Shown 50 at a time with "Show more" (call).
- **One response** opens beside the table: every answer, files, its
  version, its history (submitted, edited, moved, notes, by whom and when)
  and its task, if it has one.
- **Stages**: each form has its own list (call: New, In review, Done to
  start with). Responses move one at a time or many at once, and each move
  goes into the history, optionally with a note.
- **Notes**: written on a response by the form's side, never shown to the
  respondent.
- **Summary**: counts for every choice, yes/no and scale question, and
  responses per day.
- **Who hasn't answered**: the respondents with no response yet, by name
  inside the form's organization and by organization outside it (above),
  with Remind.
- **Files**: open, download, and every file of the filtered responses as
  one ZIP.
- **Export**:
  - **Download .xlsx**: the filtered responses, one column per question;
    repeating groups on their own sheet, linked by reference (call).
  - **Open as spreadsheet**: the same file saved as a **personal document**
    of the person exporting, then opened in ONLYOFFICE, where they can
    share it like any other personal document. Never a project document,
    because every project document is open to the whole team (owner's
    check 2). It is a snapshot named with the form and the date. Exporting
    again makes a new document, so nobody's remarks in an earlier one are
    overwritten (call).
- **Respondents see** their own response and its answers, not its stage or
  notes (call: stages stay internal this round).

## A task from each response

Off by default (owner's words). Turned on by someone with Can edit who may
also add tasks to the chosen project (call: as quick add's project list
decides).

- **Settings**:
  - **Project**: the form's own or another project of its organization.
  - **Assign to**: one person assignable there (`Member::scopeAssignableFor`),
    with the project's manager offered first (call).
  - **Due**: optionally, a number of days after the response.
  - **Title**: the form's title and the response's reference, or one
    short-text question's answer (call).
  - **Show in the task**: the questions whose answers are copied into the
    task's description (call: none by default; the reference and a link are
    always there). The project's team can read the task, and so whatever it
    copies.
- **Each new response** (not an edit, not a test) makes one task:
  - It is **made by the system**: `created_by` stays null (the `creating`
    hook would otherwise make the respondent its creator, someone who may
    not even see the project). Status is the project's default status.
  - It is assigned through the existing assignment path, so the person gets
    `TaskAssigned` as usual.
  - It goes into the project's activity like any new task.
- **The link both ways**: the response shows its task and the task's status.
  The task shows the response's reference and a link that opens it for
  whoever can see responses.
- **Edits after the task exists** change the response and its history, not
  the task (call).
- **If the chosen person can no longer be assigned** (they left the team or
  were deactivated), the task is still made, unassigned, and the form shows
  its editors a warning to choose someone else (call).
- **Switching it off** stops new tasks; tasks already made stay. Responses
  already in when it is turned on make no tasks (call).
- **The setting outlives whoever turned it on**: it keeps working when they
  leave, and anyone with Can edit (and the right to add tasks to the
  project) may change it (call).

## Data

- `forms`:
  - `project_id` (restrict on delete) and `created_by` (the maker, users,
    null on delete);
  - `code` (the link's, unique, never reused) and `title`;
  - `status` (`draft|open|closed`), `opens_at`, `closes_at` and the response
    limit;
  - the settings (`jsonb`): languages, answering as, editing after Submit,
    notifications, tasks;
  - the published version and the draft schema (`jsonb`); soft deletes
    (call).
- `form_versions`: form, `number`, `schema` (`jsonb`), `published_by`,
  `published_at`. Never changed once written.
- `form_shares`: form (cascade), member (cascade), `permission`
  (`edit|responses`), `shared_by`; unique (form, member). The same idea as
  `document_shares`.
- `form_respondents`: who fills it in (`everyone|team|people`), the chosen
  members, and other organizations.
- `form_responses`:
  - form, version and `reference`;
  - `member_id` (each person) or `organization_id` (one per organization),
    with a partial unique index on each;
  - `answers` (`jsonb`, keyed by question id, with a GIN index for the
    filters);
  - who sent it (name, designation and organization at the time, `jsonb`,
    call);
  - `stage`, `is_test`, `task_id` (null on delete), `submitted_at` and
    timestamps.
- `form_drafts`: form, member (or organization), `answers` (`jsonb`), the
  version they were typed on; unique per respondent.
- `form_response_events`: the history (submitted, edited, stage, note), who,
  data (`jsonb`), when.
- `form_files`: response or draft, question id, which entry of a repeating
  group, the object key, name, size and type. Files go to RustFS on the
  `documents` disk under a `forms/` key form that can't collide with
  documents' (builder picks), and are never overwritten or deleted this
  round (owner's check 5).
- Every JSON column is `jsonb` (the house rule). Tests compare JSON by
  value, never by key order.
- **New libraries** (call, each as sdoc uses it):
  - TipTap for text blocks, with the server cutting the HTML down to what
    the editor makes, as sdoc's `BlogHtml` does;
  - `nepali-date-converter` for BS dates;
  - `@rayzenai/nepali-geo-map` (MIT: 7 provinces, 77 districts and 753
    local levels with Nepali and English names and ward counts, with a PHP
    side so the server checks a place too);
  - an `.xlsx` writer (OpenSpout or PhpSpreadsheet).
- **Checking answers**: the server decides, with one error per question.
  The browser checks the same presets for quick feedback. A shared list of
  cases (inputs and the expected errors) runs in the Pest suite, and in a
  JS test if the builder adds a runner (call), so the two never drift.

## API

Twins for every action, for the future app (`docs/api.md` updated):

- the three lists;
- a form to fill (its published schema and the viewer's draft), saving a
  draft, Submit, a person's own responses and editing one;
- the builder: create, update the draft, publish, close, reopen,
  duplicate;
- respondents, shares and their pickers;
- the responses table with its filters, one response, stages, notes, files,
  the summary, who hasn't answered, Remind, both exports;
- the task settings.

`userPayload` says whether forms are on. Exact routes are the builder's.

## Also update

- The Guide: a Forms section on building, sharing, who sees responses,
  data calls, and tasks from responses.
- `CLAUDE.md`: a Forms section, the `WorkspaceAccess` method list, the
  morph map (`form`, `form-response`), and the notification kinds.
- Tests: happy, failure and privilege-escalation cases for each rule:
  - **Who fills it in**: someone who isn't among the respondents opening
    or submitting by link and by API; another organization's person when
    their organization wasn't chosen; an inactive person; a second response
    from one person; two people of one organization in "one per
    organization" mode ending with one response.
  - **Who sees responses**: a team member with no level reading
    responses, their files, the ZIP or an export (web and API); someone
    with Can see responses editing the form or its respondents; a share to
    someone outside the organization (the generic 422).
  - **Other organizations**: a project manager or a maker who is not an
    admin adding another organization; another organization's people named
    in "who hasn't answered".
  - **Exports**: an export landing as a project document instead of a
    personal one.
  - **Answers and versions**: answers to hidden questions dropped; editing
    after the deadline or once closed; a published version changing; an
    answer surviving an option's rename; a removed question's answers still
    shown.
  - **Tasks**: turned on by someone who can't add tasks to the project;
    assigning someone who can't be assigned; a task's `created_by` being
    the respondent; a task's description showing questions that weren't
    chosen; a test response making a task.
  - **Notifications**: reminders more than once a day; the respondent or
    the actor notified about their own action.

## Not in this round

- **Public forms**, on their own deployment from this repository (its own
  database role that reads published forms and writes responses only, its
  own storage bucket and queue, its own domain). With them come:
  two-factor sign-in for whoever publishes a public form (owner: "looks
  fine"), email codes, keys, captcha, and rules for deleting old responses.
- **Not until later**: phone codes (no SMS gateway yet) and an exam mode
  (owner: "for future").
- **Content and questions**: interactive content beyond video (H5P quizzes
  and interactive video), question grids, calculated answers, uploading
  videos.
- **Ways of answering and assigning**: anonymous surveys, giving tasks
  out in turns, a form in no project.
- **Outputs**: Word letters and certificates for each response through
  ONLYOFFICE, spreadsheets that refresh themselves, sharing responses with
  respondents (stages, replies).
- **Changes after the fact**: withdrawing a response, moving a form
  between projects, handing a form to another organization.
- **Elsewhere**: webhooks, embedding forms in other sites, forms in search
  and the command palette, the Flutter app's screens.

## Left to the builder

Route layout, page and component structure, the schema's exact JSON shape,
how ids and codes are made, how questions are reordered in the builder, the
QR code, the paging mechanism, the `.xlsx` library, the object key form,
and the exact copy, as long as each rule above has one home in
`WorkspaceAccess` or its service, and both surfaces (web and API) use it.
