# English and Nepali

The owner (2026-11-07): "users can switch between Nepali and English on the
fly which is saved as their user pref". Government officials read the
tracker in whichever language they work in; one click changes it, and it
stays changed for them on every device.

## Decided by the owner

- **Round by round.** Round 1 is the switch, sign-in, the shell (sidebar,
  menus, top bar, search, quick add, the to-do list), My work, the Documents
  pages, and the server's messages and form errors. Every other page
  follows in its own round; until then it stays in English.
- **Nepali reads as Nepali.** Digits are Devanagari (१२३) in Nepali, and
  where a date shows in both calendars the Bikram Sambat date comes first.
  English stays exactly as it was.

## The builder's calls

- English is everyone's language until they choose; the switch names the
  other language in its own script ("नेपाली" in English, "English" in
  Nepali), in the top bar of every signed-in page and on the sign-in pages.
- Saved as `user_preferences.locale` (`en`, `ne`) and copied to a `locale`
  cookie (a year), so the sign-in page keeps the language after signing
  out; someone signed out chooses with the cookie alone. The saved choice
  wins over the cookie. The app (API) reads and writes `locale` on
  `/api/v1/user` and `/api/v1/user/preferences`, and every API answer is
  in the token owner's language.
- A page not yet translated keeps the English helpers (`formatTimeAgo`,
  `plural`), so it never mixes Nepali numbers into English text; a
  translated page uses the i18n helpers throughout.
- Notification texts are stored in English when they are made, so the
  notifications list, emails and the morning mail are a later round (they
  need wording at read time, by kind). The task preview (Peek) and the
  task page are round 2 with the project pages.

## How it works

- **One dictionary, English text as the key** (Laravel's JSON
  translations): `lang/ne.json` maps each English text to Nepali, for the
  server's `__()`/`trans_choice()` and the browser's `t()`/`tc()`
  (`resources/js/lib/i18n.ts`). A text with no Nepali shows in English.
  Laravel's own messages are in `lang/ne/{validation,auth,passwords,pagination}.php`.
- **Placeholders** are Laravel's `:name`, in both. A number filled into one
  is written in the language's digits (`Locales::number` on the server,
  automatic in `t`). Counted texts use `one|many` (`tc(':count file|:count
  files', n)`, `trans_choice`); a Nepali entry may have a single form.
- **The language of a request**: `App\Http\Middleware\SetLocale` (web and
  API groups) from `App\Support\Locales::forRequest`; shared to every page
  as `locale` and `locales`; `<html lang>` follows it. Switching is `POST
  /locale` (anyone; signed in, it also saves the preference) or `PATCH
  /workspace/preferences` with `locale`; the page comes back with its state.
- **Browser helpers** (`lib/i18n.ts`): `t`, `tc`, `number`, `timeAgo`
  (Intl's Nepali relative time on formatTimeAgo's steps), `formatDay`
  (Intl dates, Nepali month names and digits). They read the shared
  `locale` while rendering, so call them in markup or `$derived`, never
  once at the top of a script. Labels kept in data (`lib/navigation.ts`)
  stay English and are passed through `t()` where they are drawn.
- **Fonts**: Geist has no Devanagari, so the sans stack is Geist then
  Mukta: Latin letters stay Geist, Nepali falls to Mukta.
- **The check**: `npm run i18n:check` (`scripts/i18n-check.mjs`, in CI's
  lint workflow) finds every literal key in `resources/js` and `app/`,
  fails on one with no Nepali or with different placeholders, and refuses
  keys built at run time.

## Writing Nepali

Plain, polite government Nepali that an office assistant and a secretary
both read without effort. Address the reader as तपाईं. Action buttons in
the polite imperative (सुरक्षित गर्नुहोस्), shortened to the noun only
where a control is too small (tabs, chips). Sentences end with ।. Names of
products and file types stay as they are (Word, Excel, PowerPoint,
ONLYOFFICE, SDOC, .docx). No em dashes, as in English.

| English | Nepali |
| --- | --- |
| My work | मेरो काम |
| Project, projects | परियोजना, परियोजनाहरू |
| Program, programs | कार्यक्रम, कार्यक्रमहरू |
| Task, tasks | कार्य, कार्यहरू |
| Subtask | उपकार्य |
| To-do (private), to-dos | गर्नुपर्ने काम, गर्नुपर्ने कामहरू |
| Just for me / Just me | मेरो लागि मात्र |
| Document, documents | कागजात, कागजातहरू |
| Spreadsheet | स्प्रेडसिट |
| Presentation | प्रस्तुति |
| People | व्यक्तिहरू |
| Team | टोली |
| Office (organization) | कार्यालय |
| Whole office | पूरै कार्यालय |
| Organizational admin | कार्यालय प्रशासक |
| Super-admin | सुपर प्रशासक |
| Project manager | परियोजना प्रबन्धक |
| Program manager | कार्यक्रम प्रबन्धक |
| Member | सदस्य |
| Designation | पद |
| Notifications | सूचनाहरू |
| Updates (phone tab) | सूचना |
| Search (button / noun) | खोज्नुहोस् / खोज |
| Settings | सेटिङ |
| Sign in / Sign out | साइन इन गर्नुहोस् / साइन आउट गर्नुहोस् |
| Email address | इमेल ठेगाना |
| Password | पासवर्ड |
| Today, Tomorrow, Yesterday | आज, भोलि, हिजो |
| Coming up | आउँदै गरेका |
| Later | पछि |
| No date | मिति नतोकिएका |
| Snooze, snoozed | पछि सार्नुहोस्, पछि सारिएका |
| Done | सम्पन्न |
| Doing | गर्दै |
| Late, overdue | ढिलो, म्याद नाघेको |
| Due date | म्याद |
| Assign | जिम्मा दिनुहोस् |
| Urgent, High, Medium, Low | अत्यावश्यक, उच्च, मध्यम, न्यून |
| Status | अवस्था |
| Save | सुरक्षित गर्नुहोस् |
| Cancel | रद्द गर्नुहोस् |
| Delete | मेटाउनुहोस् |
| Trash, Move to trash | रद्दीटोकरी, रद्दीटोकरीमा सार्नुहोस् |
| Restore | फिर्ता ल्याउनुहोस् |
| Rename | नाम फेर्नुहोस् |
| Share | साझा गर्नुहोस् |
| Upload, Download | अपलोड गर्नुहोस्, डाउनलोड गर्नुहोस् |
| Version | संस्करण |
| Can view / Can edit | हेर्न मात्र / सम्पादन गर्न सक्ने |
| Only you | तपाईं मात्र |
| Personal | व्यक्तिगत |
| Shared with me | मसँग साझा गरिएका |
| Recent | हालका |
| Show more | थप देखाउनुहोस् |
| Loading | लोड हुँदैछ |
| Try again | फेरि प्रयास गर्नुहोस् |
| Undo | पूर्ववत् गर्नुहोस् |
| Appearance | रूपरङ |
| Language | भाषा |
| Light, Dark, System | उज्यालो, अँध्यारो, प्रणाली अनुसार |
| Guide | मार्गदर्शिका |
| Reports | प्रतिवेदनहरू |
| Attendance | हाजिरी |
| Leave, kaaj | बिदा, काज |
| Organogram | संगठन संरचना |
| Needs attention (Maintenance) | ध्यान दिनुपर्ने |
| Sticky notes | टाँसिने टिपोट |
| New task | नयाँ कार्य |
| Comment | टिप्पणी |
| Week | हप्ता |
| Code activity | कोड गतिविधि |

## Rounds after this one

2. Projects, the project page (board, list, people, code, documents tab's
   remaining parts), the task page and Peek.
3. People, organogram, attendance, leave, work reports, maintenance,
   settings, the Guide.
4. Notifications (worded at read time by kind), emails and the morning mail
   in each recipient's language, the plan tracker.
5. The Flutter app, from the same `lang/ne.json` keys and the API's
   `locale`.
