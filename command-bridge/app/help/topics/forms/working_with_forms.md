# Working with ICS forms

Most ICS forms in Command Bridge work the same way. This page covers the common steps. Each form's own help page says what goes in each block and anything that form does differently.

## Before you start

- Every form belongs to the **active incident**. Most forms also belong to an **operational period** (OP). If no OP is active, you are asked to start one first. See [New operational period](help:operational_periods/new).
- The incident name, OP dates and times and your name are filled in for you wherever the app knows them.
- What you can see or change on each form is set in [Form permissions](help:admin/form_permissions). If a form opens read-only, you don't have edit rights for it.

## Saving

Press **Save** (or **Save Draft**) often. A saved form stays a **draft** and anyone with edit rights can keep changing it.

## Signing and approval

Most forms use the same two-step sign-off:

1. The person who prepared the form presses **Sign and Submit** (or **Sign and submit** in the Sign-off box). The form is signed digitally and locked.
2. Command Bridge picks the **approver** from the position that normally approves that form. If that position is empty, it moves up the chain of command. If several people hold the position, you choose one. You can change the approver with **Update approver**.
3. The approver gets a dashboard notification and presses **Approve and sign**, or **Return for changes** with a reason. Returning the form unlocks it so it can be fixed and signed again.

Once a form is **approved** it stays locked. Only a SysAdmin can reopen it, and reopening clears both signatures.

The default approver for each form:

| Form | Normally approved by |
|---|---|
| ICS 201, 208, 215A, 230 | Incident Commander |
| ICS 203, 207, 209, 210, 211 | Planning Section Chief |
| ICS 205, 205A, 206, 218 | Logistics Section Chief |
| ICS 213, 214 | The signer's own supervisor |

An administrator can change these in [Sign-off settings](help:admin/signoff_settings). Three forms have their own steps instead: the [ICS 202](help:forms/ics202/index) (approved by the Incident Commander), the [ICS 204](help:forms/ics204/index) (approved by the Planning Section Chief) and the [ICS 213RR](help:forms/ics213rr_v2/index) (its own request workflow).

Every save, signature, approval and return is recorded in the [audit log](help:audit/index) and on the signer's [ICS 214](help:forms/ics214/index).

## Printing

- **Preview PDF** opens the filled form in a new tab. **Download PDF** saves it.
- PDFs are printed on the **official ICS Canada form**. When text is too long for a box, the box ends with "(cont.)" and the full text is printed on a continuation sheet at the end.
- **Download Blank Form** gives you the empty official form, for example for paper use when the network is down.

## About the form guidance

The block-by-block guidance on each form's help page is based on the ICS Canada Forms Catalogue (2022), written in our own words. ICS Canada's own wording and the official forms are not covered by the Command Bridge licence. Where your agency's procedures say something different, follow your agency.
