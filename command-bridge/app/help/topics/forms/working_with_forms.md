# Working with ICS forms

Most ICS forms in Command Bridge work the same way. This page covers the common steps. Each form's own help page says what goes in each block and anything that form does differently.

## Before you start

- Every form belongs to the **active incident**. Most forms also belong to an **operational period** (OP). If no OP is active, you are asked to start one first. See [New operational period](help:operational_periods/new).
- The incident name, OP dates and times and your name are filled in for you wherever the app knows them.
- What you can see or change on each form is set in [Form permissions](help:admin/form_permissions). If a form opens read-only, you don't have edit rights for it.

## Saving

Press **Save** (or **Save Draft**) often. A saved form stays a **draft** and anyone with edit rights can keep changing it.

## The status banner

Every form starts with a banner showing:

- the form's **status**: Draft, Waiting for [approver], or Approved
- the **steps**: Prepare, Sign, Approval, Approved
- the **Next action** box, which says what has to happen next and holds the buttons for whoever does it

A form that has been signed but not yet approved says **Not approved yet** and names who still has to approve it. On the dashboard it shows as "Signed, waiting for approval".

## Signing and approval

Most forms use the same two-step sign-off:

1. The person who prepared the form presses **Sign and submit** (in the banner, or at the bottom of the form). The form is signed digitally and **locked**.
2. Command Bridge picks the **approver** from the position that normally approves that form. If that position is empty, it moves up the chain of command. If several people hold the position, you choose one. The preparer can change the approver with **Change approver**.
3. The approver gets a dashboard notification and presses **Approve and sign**, or **Return for changes** with a reason.

Once a form is approved, the banner shows who signed and who approved it, and nothing else can be changed.

## Locked forms and Revise

A form is locked from the moment it is signed until someone revises it, both while it waits for approval and after it is approved. Nobody can save, add or delete anything on a locked form. Nothing else waits for the approval, though. The meetings on an ICS 230, for example, still show and still send reminders.

To change a locked form, use **Revise** in the banner and give a reason:

- **Who can revise:** the person who signed it, the approver, anyone who can edit or sign that form, and a SysAdmin. An [ICS 214](help:forms/ics214/index) can only be revised by its owner, the approver (while it waits) or a SysAdmin.
- **What happens:**
  - Both signatures are cleared.
  - The form opens as a draft with the next revision number ("Revision 1", "Revision 2" and so on).
  - It has to be signed and approved again.
  - The person who signed it and the approver are notified.
- **The record of the old version:** before anything changes, Command Bridge saves a PDF of the version being replaced. **Revision history** in the banner lists every earlier version: who revised it and why, who had signed and approved it, and a link to its PDF. Each revision is also in the [audit log](help:audit/index).

"Return for changes" by the approver works the same way as Revise.

A few things record what happened rather than change the plan, so they are allowed on a locked form without revising it:

- Marking an [ICS 230](help:forms/ics230/index) meeting held or cancelled (**Was it held?**).
- New [ICS 213](help:forms/ics213/index) messages and replies. Each message is locked as soon as it is saved.

Resources received by the [Resources Unit](help:forms/resources/index) are still added to the ICS 211 and ICS 218 automatically. Adding entries by hand to a signed ICS 210, 211 or 218 needs a revision.

The [ICS 213RR](help:forms/ics213rr_v2/index) keeps its own workflow. A request can't be changed after it is submitted. It has to be returned for revision and go through approval again, because it involves spending.

The default approver for each form:

| Form | Normally approved by |
|---|---|
| ICS 201, 208, 215A, 230 | Incident Commander |
| ICS 203, 207, 209, 210, 211 | Planning Section Chief |
| ICS 205, 205A, 206, 218 | Logistics Section Chief |
| ICS 213, 214 | The signer's own supervisor |

An administrator can change these in [Sign-off settings](help:admin/signoff_settings). Three forms have their own steps instead: the [ICS 202](help:forms/ics202/index) (approved by the Incident Commander), the [ICS 204](help:forms/ics204/index) (approved by the Planning Section Chief) and the [ICS 213RR](help:forms/ics213rr_v2/index) (its own request workflow).

Every save, signature, approval and revision is recorded in the [audit log](help:audit/index) and on the signer's [ICS 214](help:forms/ics214/index).

## Printing

- **Preview PDF** opens the filled form in a new tab. **Download PDF** saves it.
- PDFs are printed on the **official ICS Canada form**. When text is too long for a box, the box ends with "(cont.)" and the full text is printed on a continuation sheet at the end.
- **Download Blank Form** gives you the empty official form, for example for paper use when the network is down.

## About the form guidance

The block-by-block guidance on each form's help page is based on the ICS Canada Forms Catalogue (2022), written in our own words. ICS Canada's own wording and the official forms are not covered by the Command Bridge licence. Where your agency's procedures say something different, follow your agency.
