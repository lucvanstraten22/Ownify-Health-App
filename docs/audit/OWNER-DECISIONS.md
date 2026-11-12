# Ownify final audit — owner decisions (Checkpoint 2)

Recorded on 12 November 2026 from the owner's export of
`docs/audit/OWNER-DECISIONS.html`. These decisions are the scope for Phase 3:
proposals and prototypes, for approval before anything is built.

## Decisions

1. **Title sizes:** popup and sheet titles are 17 px everywhere. Card heads are 17 px everywhere, as on Community.
2. **Destructive actions:** option A. The trigger is a neutral button with a bin icon, and gold is used only for the
   final yes.
   - *Owner's note:* keep the current look and size of "Vriend verwijderen" and "Account verwijderen", because they
     are rarely used.
3. **Button text:** 15 px for full-width and primary buttons, and 13 px beside a field. Nothing is 11 px.
4. **Account popup:**
   - The popup becomes a summary (photo, name, Vrienden ›, Uitloggen).
   - Editing moves to Instellingen › Account.
   - Tapping the photo changes it; picking a picture uploads it straight away.
   - The real name is shown as a quiet line under the username.
   - Uitloggen has the logout icon in both places.
5. **Example 3:** option A. The header loses its card, and the photo and names stand on the page background. No
   screenshot was sent, so the guess about the "thick elements" is still unconfirmed.
6. **Privacy wording:**
   - The OwnifyAI consent text starts from the draft. The final Dutch and English need the owner's approval.
   - "Later instelbaar" is tidied up: "Profiel vindbaar" is removed, "Meedoen aan ranglijsten" is shown as "Altijd",
     and "Vriendverzoeken toestaan" moves into Privacy.
7. **Icons:**
   - The icons are re-mapped as proposed.
   - Two new icons are added: a warning icon and a sun/moon icon for Thema.
   - Close becomes × everywhere; chevron-down is used only for lists.
8. **Charts:** items 1–9 and 11 are approved.
   - Item 10 is split. Naming lines at their end is rejected (N10); stronger bars are approved (N11).
9. **Back:**
   - Web: back closes the top layer.
   - Android: back on another tab goes to Overzicht first.
10. **Overzicht arrival:** the shorter version, with the pillars starting together with the ring.
    - *Owner's note:* the total is **2.5 s**, not the proposed 1.5 s.
11. **Words:**
    - Training is the category, and **Beweging** is moving during the day; "Activiteit" goes. This differs from my
      recommendation.
    - "dagscore" becomes "Gezondheidsscore".
    - The research back button names its page.
12. **Small picks:** 12 px between cards of one kind and 16 px between different blocks. A unit is small and grey
    after its number; % stays attached at full size.
13. **Leftovers:**
    - Remove the status check, after checking the live server log.
    - Remove the badges card.
    - Remove "suggest a target".
    - Keep the "derived" settings field.
    - Remove the pull-to-sync hooks, unless the screenshot tools use them.
    - Bring `g7` up to date.
14. **Speed:**
    - Turn on server compression.
    - Let browsers keep versioned style and script files for a long time (cache).
    - Android refreshes on return at most once a minute; a pull to sync always refreshes.

## New conventions

- **Approved:** N1–N9 and N11–N17.
- **Rejected:** N10 (naming the Verloop lines at their end).
